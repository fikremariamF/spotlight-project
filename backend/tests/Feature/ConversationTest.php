<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesSupportData;
use Tests\TestCase;

/**
 * Customer-store chats from before support messaging: kept as read-only history. The customer and
 * admins can read them; stores no longer can; nobody can write in them or start a new one.
 */
class ConversationTest extends TestCase
{
    use MakesSupportData, RefreshDatabase;

    private SellerProfile $seller;

    private Product $product;

    private User $buyer;

    private Conversation $chat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->seller = $this->approvedSeller();
        $this->product = Product::factory()->published()->create([
            'seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id,
            'price_negotiable' => true, 'custom_order_available' => true,
        ]);
        $this->buyer = $this->customer();
        $this->chat = $this->oldConversation($this->buyer, $this->seller, $this->product);
    }

    public function test_the_customer_still_reads_old_chats(): void
    {
        $this->actAs($this->buyer)->getJson('/api/conversations')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->chat->id)
            ->assertJsonPath('data.0.unread', true);

        $this->getJson("/api/conversations/{$this->chat->id}")->assertOk()
            ->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.messages.1.body', 'Yes, two left. Call me on 0911 000 000.')
            ->assertJsonPath('data.seller.store_name', $this->seller->store_name);
        // Reading it marks it read, as before.
        $this->getJson('/api/conversations')->assertJsonPath('data.0.unread', false);
    }

    public function test_nobody_can_start_or_write_in_a_store_chat_any_more(): void
    {
        $id = $this->chat->id;
        foreach ([$this->buyer, $this->seller->user] as $user) {
            $this->actAs($user);
            $this->postJson('/api/conversations', ['product_slug' => $this->product->slug, 'type' => 'question', 'body' => 'Hello there'])
                ->assertStatus(405);
            $this->postJson("/api/conversations/$id/messages", ['body' => 'hello'])->assertNotFound();
            $this->postJson("/api/conversations/$id/close")->assertNotFound();
            $this->postJson("/api/seller/conversations/$id/quotes", ['title' => 'x'])->assertNotFound();
        }
        $this->assertSame(2, Message::where('conversation_id', $id)->count());
        $this->assertSame(1, Conversation::count());
        $this->assertSame('open', $this->chat->fresh()->status);
    }

    public function test_stores_lose_access_to_customer_chats(): void
    {
        $this->actAs($this->seller->user);
        $this->getJson('/api/conversations')->assertForbidden();
        $this->getJson("/api/conversations/{$this->chat->id}")->assertForbidden();
        // The old unread counter is gone for everyone.
        $this->getJson('/api/conversations/unread-count')->assertNotFound();
    }

    public function test_other_customers_get_404_and_see_nothing(): void
    {
        $this->actAs($this->customer());
        $this->getJson("/api/conversations/{$this->chat->id}")->assertNotFound()->assertExactJson(['message' => 'Not found.']);
        $this->getJson('/api/conversations')->assertJsonCount(0, 'data');

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/conversations')->assertUnauthorized();
    }

    public function test_admins_read_old_chats_in_the_support_inbox(): void
    {
        $other = $this->oldConversation($this->customer(), $this->approvedSeller());
        $admin = $this->admin();

        $this->actAs($admin)->getJson('/api/admin/support/old-chats')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/admin/support/old-chats?q='.urlencode($this->seller->store_name))->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->chat->id);
        $this->getJson("/api/admin/support/old-chats/{$this->chat->id}")->assertOk()
            ->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.customer.id', $this->buyer->id)
            ->assertJsonPath('data.unread', false);
        $this->assertDatabaseHas('audit_logs', ['action' => 'support.legacy_viewed', 'subject_id' => $this->chat->id]);

        // Only admins.
        foreach ([$this->buyer, $this->seller->user] as $user) {
            $this->actAs($user)->getJson('/api/admin/support/old-chats')->assertForbidden();
            $this->getJson("/api/admin/support/old-chats/{$other->id}")->assertForbidden();
        }
    }

    public function test_deleted_listing_still_shows_in_an_old_chat(): void
    {
        $this->product->delete();

        $this->actAs($this->buyer)->getJson("/api/conversations/{$this->chat->id}")->assertOk()
            ->assertJsonPath('data.product.name', $this->product->name);
    }

    public function test_the_old_inbox_filters_still_work(): void
    {
        $closed = $this->oldConversation($this->buyer, $this->approvedSeller(), null, 'closed');

        $this->actAs($this->buyer);
        $this->getJson('/api/conversations?status=closed')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $closed->id);
        $this->getJson('/api/conversations?status=open')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->chat->id);
        $this->getJson('/api/conversations?status=nonsense')->assertUnprocessable();
    }
}
