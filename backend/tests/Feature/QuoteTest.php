<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Product;
use App\Models\Quote;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesSupportData;
use Tests\TestCase;

/**
 * Quotes a store sent in its own chat before support messaging: the customer can still accept or
 * decline them until they expire, and the store can withdraw them, but the chat stays read-only.
 * The admin's side of sending offers is checked at the end.
 */
class QuoteTest extends TestCase
{
    use MakesSupportData, RefreshDatabase;

    private SellerProfile $seller;

    private User $buyer;

    private Conversation $chat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->seller = $this->approvedSeller();
        $product = Product::factory()->published()->create([
            'seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id, 'custom_order_available' => true,
        ]);
        $this->buyer = $this->customer();
        $this->chat = $this->oldConversation($this->buyer, $this->seller, $product);
    }

    public function test_a_pending_quote_from_an_old_chat_can_still_be_accepted(): void
    {
        $id = $this->oldQuote($this->chat)->id;

        $this->actAs($this->buyer)->getJson("/api/conversations/{$this->chat->id}")->assertJsonPath('data.quotes.0.status', 'pending');
        $this->postJson("/api/quotes/$id/accept")->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->assertNotNull(Quote::find($id)->responded_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'quote.accepted', 'subject_id' => $id]);
        // The chat stays as it was: no new message is written into it.
        $this->assertSame(2, Message::where('conversation_id', $this->chat->id)->count());
        // The store hears about it without the customer's name, and is sent to its orders.
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->seller->user_id, 'type' => 'quote.answered']);
        $this->assertStringNotContainsString($this->buyer->name, json_encode($this->seller->user->notifications()->value('data')));

        // A decided quote cannot change again, from either side.
        $this->postJson("/api/quotes/$id/accept")->assertUnprocessable();
        $this->postJson("/api/quotes/$id/decline")->assertUnprocessable();
        $this->actAs($this->seller->user)->postJson("/api/seller/quotes/$id/withdraw")->assertUnprocessable();
    }

    public function test_customer_declines_and_store_withdraws(): void
    {
        $first = $this->oldQuote($this->chat)->id;
        $this->actAs($this->buyer)->postJson("/api/quotes/$first/decline")->assertOk()->assertJsonPath('data.status', 'declined');

        $second = $this->oldQuote($this->chat)->id;
        $this->actAs($this->seller->user)->postJson("/api/seller/quotes/$second/withdraw")->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->actAs($this->buyer)->postJson("/api/quotes/$second/accept")->assertUnprocessable()
            ->assertJsonPath('errors.quote.0', 'This quote is already withdrawn.');
    }

    public function test_expired_quote_cannot_be_accepted_but_is_valid_through_its_last_day(): void
    {
        $id = $this->oldQuote($this->chat, ['valid_until' => now()->toDateString()])->id;

        $this->travelTo(now()->endOfDay()->subMinute());
        $this->actAs($this->buyer)->getJson("/api/conversations/{$this->chat->id}")->assertJsonPath('data.quotes.0.status', 'pending');

        $this->travelTo(now()->addDay()->startOfDay()->addMinute());
        $this->getJson("/api/conversations/{$this->chat->id}")->assertJsonPath('data.quotes.0.status', 'expired');
        $this->postJson("/api/quotes/$id/accept")->assertUnprocessable()->assertJsonPath('errors.quote.0', 'This quote is already expired.');
        // Stored status stays pending; expiry is derived.
        $this->assertSame('pending', Quote::find($id)->status->value);
    }

    public function test_only_the_chat_customer_can_respond(): void
    {
        $id = $this->oldQuote($this->chat)->id;

        $this->actAs($this->customer())->postJson("/api/quotes/$id/accept")->assertNotFound();
        $this->postJson("/api/quotes/$id/decline")->assertNotFound();
        $this->actAs($this->seller->user)->postJson("/api/quotes/$id/accept")->assertForbidden();
        $this->actAs($this->approvedSeller()->user)->postJson("/api/seller/quotes/$id/withdraw")->assertNotFound();
        $this->actAs($this->buyer)->postJson("/api/seller/quotes/$id/withdraw")->assertForbidden();
        $this->postJson('/api/quotes/999999/accept')->assertNotFound();

        $this->assertSame('pending', Quote::find($id)->status->value);
    }

    public function test_no_acceptance_once_the_store_is_suspended_or_the_chat_was_closed(): void
    {
        $id = $this->oldQuote($this->chat)->id;

        // Store suspended: the customer cannot accept, but can still decline.
        $this->seller->forceFill(['status' => 'suspended'])->save();
        $this->actAs($this->buyer)->postJson("/api/quotes/$id/accept")->assertUnprocessable();
        $this->seller->forceFill(['status' => 'approved'])->save();

        // A chat that was closed before support messaging cannot have a quote accepted.
        $this->chat->forceFill(['status' => 'closed'])->save();
        $this->postJson("/api/quotes/$id/accept")->assertUnprocessable();
        $this->postJson("/api/quotes/$id/decline")->assertOk();
    }

    public function test_sending_an_offer_is_validated(): void
    {
        $admin = $this->admin();
        $ticket = $this->askSupport($this->buyer, ['topic' => 'custom', 'seller_slug' => $this->seller->slug, 'body' => 'A 240 cm sofa please'])->json('data.id');
        ['request' => $request] = $this->relayOffer($ticket, $admin, $this->seller, ['quantity' => 1000, 'price' => '9999999999.99']);
        // The proposal was the largest allowed; its total does not overflow.
        $this->assertSame(999999999999 * 1000, Quote::latest('id')->first()->totalSantim());

        $proposal = collect($this->actAs($this->seller->user)->postJson("/api/seller/requests/$request/messages", [
            'body' => 'Second option', 'proposal' => ['price' => '30000', 'valid_until' => now()->addDays(10)->toDateString()],
        ])->json('data.messages'))->last()['id'];
        $send = fn (array $data) => $this->actAs($admin)->postJson("/api/admin/support/requests/$request/proposals/$proposal/send", array_merge([
            'title' => 'Sofa', 'description' => 'Velvet, 240 cm wide.', 'valid_until' => now()->addDays(3)->toDateString(),
        ], $data));

        $send(['title' => ''])->assertUnprocessable()->assertJsonValidationErrors('title');
        $send(['description' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('description');
        $send(['valid_until' => now()->subDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('valid_until');
        $send(['valid_until' => 'next week'])->assertUnprocessable()->assertJsonValidationErrors('valid_until');
        // Another request's proposal id is not this request's.
        $this->postJson('/api/admin/support/requests/999999/proposals/'.$proposal.'/send', [
            'title' => 'Sofa', 'description' => 'Velvet, 240 cm wide.', 'valid_until' => now()->toDateString(),
        ])->assertNotFound();

        // An expired proposal cannot be sent.
        $this->travel(11)->days();
        $send(['valid_until' => now()->toDateString()])->assertUnprocessable()
            ->assertJsonPath('errors.proposal.0', "The store's proposal has expired. Ask the store for a new one.");
    }
}
