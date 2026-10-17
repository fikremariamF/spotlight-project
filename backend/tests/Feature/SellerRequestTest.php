<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Quote;
use App\Models\SellerRequest;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\MakesSupportData;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

/**
 * Admins ask stores; stores answer Betoch only. Covers acceptance criteria 3, 4 and 6:
 * the store never sees the customer, and its answer reaches the customer only through an admin.
 */
class SellerRequestTest extends TestCase
{
    use MakesSupportData, PlacesOrders, RefreshDatabase {
        MakesSupportData::actAs insteadof PlacesOrders;
    }

    private User $admin;

    private int $ticketId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrders();
        $this->admin = $this->admin();
        $this->buyer->forceFill(['phone' => '+251911222333'])->save();
        $this->ticketId = $this->askSupport($this->buyer, ['product_slug' => $this->sofa->slug, 'topic' => 'availability',
            'body' => 'Can you check the walnut finish? My number is 0911 222 333.'])->json('data.id');
    }

    private function ask(array $overrides = [])
    {
        return $this->actAs($this->admin)->postJson('/api/admin/support/requests', array_merge([
            'support_ticket_id' => $this->ticketId, 'seller_profile_id' => $this->seller->id, 'topic' => 'stock',
            'subject' => 'Walnut finish in stock?', 'body' => 'A customer asks whether the walnut finish is in stock.',
            'product_id' => $this->sofa->id,
        ], $overrides));
    }

    public function test_admin_asks_the_store_and_the_store_sees_only_the_request(): void
    {
        $id = $this->ask()->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.store.id', $this->seller->id)
            ->assertJsonPath('data.ticket.id', $this->ticketId)
            ->json('data.id');

        $this->actAs($this->seller->user);
        $this->getJson('/api/seller/requests/unread-count')->assertJsonPath('data.unread', 1);
        $this->getJson('/api/seller/summary')->assertJsonPath('data.requests.waiting', 1);
        $view = $this->getJson("/api/seller/requests/$id")->assertOk()
            ->assertJsonPath('data.subject', 'Walnut finish in stock?')
            ->assertJsonPath('data.product.id', $this->sofa->id)
            ->assertJsonPath('data.messages.0.from', 'Betoch Support')
            ->assertJsonMissingPath('data.ticket')
            ->assertJsonMissingPath('data.support_ticket_id')
            ->assertJsonMissingPath('data.store');
        // Nothing about the customer: not their name, email, phone or what they wrote.
        $raw = $view->getContent();
        foreach ([$this->buyer->name, $this->buyer->email, '0911 222 333', '+251911222333', 'walnut finish?', $this->admin->name] as $secret) {
            $this->assertStringNotContainsString($secret, $raw, $secret);
        }
        $this->getJson('/api/seller/requests/unread-count')->assertJsonPath('data.unread', 0);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->seller->user_id, 'type' => 'store_request.new']);

        // Working on an unassigned inquiry takes it, as replying does; asking the store is not a reply.
        $ticket = SupportTicket::find($this->ticketId);
        $this->assertSame($this->admin->id, $ticket->assigned_to);
        $this->assertSame('open', $ticket->status->value);
        $this->assertNull($ticket->first_response_at);
    }

    public function test_the_store_answer_stays_internal_until_an_admin_writes_to_the_customer(): void
    {
        $id = $this->ask()->json('data.id');
        $this->actAs($this->seller->user)->postJson("/api/seller/requests/$id/messages", ['body' => 'Yes, two in walnut. STORE-ONLY'])
            ->assertCreated()->assertJsonPath('data.status', 'answered')->assertJsonPath('data.messages.1.from', 'You');

        // The customer's inquiry is unchanged.
        $this->actAs($this->buyer)->getJson("/api/support/tickets/{$this->ticketId}")->assertOk()
            ->assertJsonCount(1, 'data.messages')
            ->assertDontSee('STORE-ONLY');
        $this->getJson('/api/support/unread-count')->assertJsonPath('data.unread', 0);

        // The admin sees the answer on the inquiry and decides what to say.
        $admin = $this->actAs($this->admin)->getJson("/api/admin/support/tickets/{$this->ticketId}")->assertOk()
            ->assertJsonPath('data.store_requests.0.status', 'answered')
            ->assertJsonPath('data.store_requests.0.messages.1.body', 'Yes, two in walnut. STORE-ONLY')
            ->assertJsonPath('data.store_requests.0.messages.1.from', $this->seller->store_name);
        $this->assertContains($this->seller->store_name.' answered', collect($admin->json('data.messages'))->pluck('body')->all());
        $this->getJson('/api/admin/summary')->assertJsonPath('data.support.store_answers', 1);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->admin->id, 'type' => 'store_request.answered']);

        $this->postJson("/api/admin/support/tickets/{$this->ticketId}/messages", ['body' => 'Good news: the walnut finish is in stock.'])->assertCreated();
        $this->actAs($this->buyer)->getJson("/api/support/tickets/{$this->ticketId}")
            ->assertJsonPath('data.messages.1.body', 'Good news: the walnut finish is in stock.')
            ->assertDontSee('STORE-ONLY');
    }

    public function test_stores_see_only_their_own_requests(): void
    {
        $id = $this->ask()->json('data.id');
        $other = $this->approvedSeller();

        $this->actAs($other->user);
        $this->getJson('/api/seller/requests')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/seller/requests/$id")->assertNotFound();
        $this->postJson("/api/seller/requests/$id/messages", ['body' => 'Not mine'])->assertNotFound();
        $this->assertSame(1, SellerRequest::find($id)->messages()->count());

        // Customers and admins cannot use the store side; stores cannot use the admin side.
        $this->actAs($this->buyer)->getJson('/api/seller/requests')->assertForbidden();
        $this->actAs($this->admin)->postJson("/api/seller/requests/$id/messages", ['body' => 'x'])->assertForbidden();
        $this->actAs($this->seller->user)->postJson("/api/admin/support/requests/$id/messages", ['body' => 'x'])->assertForbidden();
        $this->postJson("/api/admin/support/requests/$id/close")->assertForbidden();
    }

    public function test_requests_can_only_reference_the_stores_own_listing_or_order(): void
    {
        $other = $this->approvedSeller();
        $theirs = Product::factory()->published()->create(['seller_profile_id' => $other->id, 'category_id' => $this->category()->id]);
        $this->ask(['product_id' => $theirs->id])->assertUnprocessable()->assertJsonValidationErrors('product_id');

        $order = $this->placeOrder(1);
        $this->ask(['seller_profile_id' => $other->id, 'product_id' => null, 'order_id' => $order->id])->assertUnprocessable()->assertJsonValidationErrors('order_id');
        $this->ask(['product_id' => null, 'order_id' => $order->id, 'topic' => 'fulfilment'])->assertCreated()->assertJsonPath('data.order.number', $order->number);

        // An unpaid online order is invisible to the store, so it cannot be referenced either.
        Order::whereKey($order->id)->update(['payment_method' => 'online', 'paid_at' => null]);
        $this->ask(['product_id' => null, 'order_id' => $order->id])->assertUnprocessable()->assertJsonValidationErrors('order_id');

        $pending = $this->pendingSeller();
        $this->ask(['seller_profile_id' => $pending->id, 'product_id' => null])->assertUnprocessable()->assertJsonValidationErrors('seller_profile_id');
        $this->ask(['support_ticket_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors('support_ticket_id');
    }

    public function test_requests_without_an_inquiry_and_closing(): void
    {
        $id = $this->ask(['support_ticket_id' => null, 'topic' => 'delivery', 'subject' => 'Delivery days', 'body' => 'Which days do you deliver?'])
            ->assertCreated()->assertJsonPath('data.ticket', null)->json('data.id');
        $this->getJson('/api/admin/support/requests')->assertOk()->assertJsonPath('meta.counts.open', 1);

        $this->postJson("/api/admin/support/requests/$id/close")->assertOk()->assertJsonPath('data.status', 'closed');
        $this->actAs($this->seller->user)->postJson("/api/seller/requests/$id/messages", ['body' => 'Late answer'])->assertUnprocessable()
            ->assertJsonPath('errors.body.0', 'This request is closed.');
        $this->getJson('/api/seller/requests?status=closed')->assertJsonCount(1, 'data');
        $this->getJson('/api/seller/requests?status=active')->assertJsonCount(0, 'data');
    }

    public function test_a_store_proposal_becomes_an_offer_only_when_an_admin_sends_it(): void
    {
        $id = $this->ask(['topic' => 'price'])->json('data.id');
        $messages = $this->actAs($this->seller->user)->postJson("/api/seller/requests/$id/messages", [
            'body' => 'We can do it.',
            'proposal' => ['price' => '44000', 'quantity' => 2, 'production_days' => 25, 'valid_until' => now()->addDays(5)->toDateString()],
        ])->assertCreated()->json('data.messages');
        $proposal = end($messages);
        $this->assertSame(8_800_000, $proposal['proposal']['total_santim']);
        $this->assertFalse($proposal['proposal']['sent']);

        // Not yet visible to the customer.
        $this->assertSame(0, Quote::count());
        $this->actAs($this->buyer)->getJson("/api/support/tickets/{$this->ticketId}")->assertJsonCount(0, 'data.offers');

        // The store cannot send it itself.
        $this->actAs($this->seller->user)->postJson("/api/admin/support/requests/$id/proposals/{$proposal['id']}/send", [])->assertForbidden();

        $send = fn (array $data = []) => $this->actAs($this->admin)->postJson("/api/admin/support/requests/$id/proposals/{$proposal['id']}/send", array_merge([
            'title' => 'Walnut sofa', 'description' => 'Walnut finish, two pieces.', 'valid_until' => now()->addDays(5)->toDateString(),
        ], $data));
        // Not longer than the store agreed, and only once.
        $send(['valid_until' => now()->addDays(6)->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('proposal');
        $quoteId = $send()->assertCreated()
            ->assertJsonPath('data.price_santim', 4_400_000)
            ->assertJsonPath('data.quantity', 2)
            ->assertJsonPath('data.support_ticket_id', $this->ticketId)
            ->assertJsonPath('data.conversation_id', null)
            ->json('data.id');
        $send()->assertUnprocessable()->assertJsonPath('errors.proposal.0', 'This proposal has already been sent to the customer.');

        // The admin's own message cannot be sent as an offer.
        $adminMessage = SellerRequest::find($id)->messages()->where('author_role', 'admin')->value('id');
        $this->postJson("/api/admin/support/requests/$id/proposals/$adminMessage/send", [
            'title' => 'x x x', 'description' => 'Nothing to send here.', 'valid_until' => now()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('proposal');

        $this->actAs($this->buyer)->getJson('/api/support/unread-count')->assertJsonPath('data.unread', 1);
        $this->getJson("/api/support/tickets/{$this->ticketId}")->assertOk()
            ->assertJsonPath('data.offers.0.id', $quoteId)
            ->assertJsonPath('data.offers.0.status', 'pending')
            ->assertJsonPath('data.offers.0.store.store_name', $this->seller->store_name)
            ->assertJsonPath('data.messages.1.kind', 'offer')
            ->assertJsonPath('data.messages.1.from', 'Betoch Support');

        $this->postJson("/api/quotes/$quoteId/accept")->assertOk()->assertJsonPath('data.status', 'accepted');
        // The store hears that it was accepted, without the customer's name.
        $this->actAs($this->seller->user)->getJson("/api/seller/requests/$id")->assertOk()
            ->assertJsonPath('data.messages.2.from', 'Betoch')
            ->assertJsonPath('data.messages.3.body', 'The customer accepted your offer "Walnut sofa". The order appears under Orders once they check out.')
            ->assertJsonPath('data.messages.1.proposal.offer_status', 'accepted')
            ->assertDontSee($this->buyer->name);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->seller->user_id, 'type' => 'quote.answered']);
    }

    public function test_proposal_rules(): void
    {
        $id = $this->ask(['topic' => 'price'])->json('data.id');
        $this->actAs($this->seller->user);
        $post = fn (array $proposal) => $this->postJson("/api/seller/requests/$id/messages", ['body' => 'Offer', 'proposal' => $proposal]);
        $valid = ['price' => '1000', 'valid_until' => now()->addDays(3)->toDateString()];

        $post(['price' => '0.50'] + $valid)->assertUnprocessable()->assertJsonValidationErrors('price');
        $post(['price' => 'abc'] + $valid)->assertUnprocessable()->assertJsonValidationErrors('proposal.price');
        $post(['valid_until' => now()->subDay()->toDateString()] + $valid)->assertUnprocessable()->assertJsonValidationErrors('proposal.valid_until');
        $post(['valid_until' => now()->addDays(61)->toDateString()] + $valid)->assertUnprocessable()->assertJsonValidationErrors('proposal.valid_until');
        $post(['quantity' => 0] + $valid)->assertUnprocessable()->assertJsonValidationErrors('proposal.quantity');
        // While the marketplace prices delivery, a store can offer free delivery but not charge for it.
        config(['marketplace.distance.stores_set_fee' => false]);
        $post(['delivery_fee' => '500'] + $valid)->assertUnprocessable()->assertJsonValidationErrors('delivery_fee');
        $post(['delivery_fee' => '0'] + $valid)->assertCreated();
        $this->postJson("/api/seller/requests/$id/messages", ['body' => '   '])->assertUnprocessable();
    }

    public function test_offers_from_an_inactive_store_or_on_a_closed_inquiry_cannot_be_sent_or_accepted(): void
    {
        ['quote' => $quoteId] = $this->relayOffer($this->ticketId, $this->admin, $this->seller);

        $this->seller->forceFill(['status' => 'suspended'])->save();
        $this->actAs($this->buyer)->postJson("/api/quotes/$quoteId/accept")->assertUnprocessable();
        $this->postJson("/api/quotes/$quoteId/decline")->assertOk();

        $this->seller->forceFill(['status' => 'approved'])->save();
        ['quote' => $second] = $this->relayOffer($this->ticketId, $this->admin, $this->seller);
        $this->actAs($this->buyer)->postJson("/api/support/tickets/{$this->ticketId}/close")->assertOk();
        // Closing declined the pending offer.
        $this->assertSame('declined', Quote::find($second)->status->value);
        $this->postJson("/api/quotes/$second/accept")->assertUnprocessable();
    }

    public function test_a_newer_offer_replaces_the_pending_one_and_admins_can_withdraw(): void
    {
        ['quote' => $first] = $this->relayOffer($this->ticketId, $this->admin, $this->seller);
        ['quote' => $second] = $this->relayOffer($this->ticketId, $this->admin, $this->seller, ['price' => '43000']);
        $this->assertSame('withdrawn', Quote::find($first)->status->value);

        $this->actAs($this->admin)->postJson("/api/admin/support/offers/$second/withdraw")->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->postJson("/api/admin/support/offers/$second/withdraw")->assertUnprocessable();
        $this->actAs($this->buyer)->getJson("/api/support/tickets/{$this->ticketId}")
            ->assertJsonPath('data.offers.1.status', 'withdrawn');
        $this->postJson("/api/quotes/$second/accept")->assertUnprocessable();

        // Stores withdraw only quotes from their old chats; support offers are Betoch's.
        ['quote' => $third] = $this->relayOffer($this->ticketId, $this->admin, $this->seller);
        $this->actAs($this->seller->user)->postJson("/api/seller/quotes/$third/withdraw")->assertNotFound();
        $this->assertSame('pending', Quote::find($third)->status->value);
    }

    public function test_offers_belong_to_the_inquirys_customer_only(): void
    {
        ['quote' => $quoteId] = $this->relayOffer($this->ticketId, $this->admin, $this->seller);
        $stranger = $this->customer();

        $this->actAs($stranger);
        $this->postJson("/api/quotes/$quoteId/accept")->assertNotFound();
        $this->postJson("/api/quotes/$quoteId/decline")->assertNotFound();
        $this->postJson("/api/quotes/$quoteId/add-to-cart")->assertNotFound();
        $this->assertSame('pending', Quote::find($quoteId)->status->value);
    }

    public function test_an_accepted_offer_is_ordered_through_normal_checkout(): void
    {
        ['quote' => $quoteId] = $this->relayOffer($this->ticketId, $this->admin, $this->seller);
        $this->actAs($this->buyer)->postJson("/api/quotes/$quoteId/add-to-cart")->assertUnprocessable()->assertJsonValidationErrors('quote');
        $this->postJson("/api/quotes/$quoteId/accept")->assertOk();

        $this->postJson("/api/quotes/$quoteId/add-to-cart")->assertCreated()
            ->assertJsonPath('data.groups.0.lines.0.kind', 'quote')
            ->assertJsonPath('data.groups.0.lines.0.quote.support_ticket_id', $this->ticketId)
            ->assertJsonPath('data.groups.0.lines.0.line_total_santim', 8_800_000);
        $expected = $this->getJson('/api/cart?address_id='.$this->address->id)->json('data.grand_total_santim');
        $this->postJson('/api/checkout', ['address_id' => $this->address->id, 'payment_method' => 'cod', 'expected_total_santim' => $expected],
            ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        $order = Order::latest('id')->first();
        $this->assertSame($this->seller->id, $order->seller_profile_id);
        $this->assertSame($quoteId, $order->items()->value('quote_id'));
        $this->postJson("/api/quotes/$quoteId/add-to-cart")->assertUnprocessable()->assertJsonPath('errors.quote.0', 'This quote has already been ordered.');
        $this->assertSame('open', SupportTicket::find($this->ticketId)->status->value);
    }
}
