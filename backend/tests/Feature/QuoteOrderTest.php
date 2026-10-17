<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Quote;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\MakesSupportData;
use Tests\TestCase;

/**
 * Ordering a priced offer: Betoch sends a store's proposal, the customer accepts and checks out as usual.
 * Quotes from the old store chats still order the same way.
 */
class QuoteOrderTest extends TestCase
{
    use MakesSupportData, RefreshDatabase;

    private User $buyer;

    private User $admin;

    private Address $address;

    private SellerProfile $seller;

    private int $ticketId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['marketplace.distance.stores_set_fee' => true]);
        $this->seedReferenceData();
        $this->seller = $this->approvedSeller();
        $this->seller->forceFill(['offers_custom_orders' => true])->save();
        $this->buyer = $this->customer();
        $this->admin = $this->admin();
        $this->address = $this->buyer->addresses()->forceCreate([
            'user_id' => $this->buyer->id, 'recipient_name' => 'Abebe', 'phone' => '+251911000111',
            'area_id' => $this->area('bole')->id, 'address_line' => 'House 12', 'is_default' => true,
        ]);
        $this->ticketId = $this->askSupport($this->buyer, [
            'topic' => 'custom', 'seller_slug' => $this->seller->slug, 'body' => 'Built-in wardrobe please',
        ])->assertCreated()->json('data.id');
    }

    private function offer(array $proposal = [], array $send = []): int
    {
        return $this->relayOffer($this->ticketId, $this->admin, $this->seller, array_merge(['delivery_fee' => '800'], $proposal), $send)['quote'];
    }

    private function checkout(): TestResponse
    {
        $expected = $this->getJson('/api/cart?address_id='.$this->address->id)->json('data.grand_total_santim');

        return $this->postJson('/api/checkout', ['address_id' => $this->address->id, 'payment_method' => 'cod', 'expected_total_santim' => $expected],
            ['Idempotency-Key' => (string) Str::uuid()]);
    }

    public function test_accepted_offer_is_ordered_at_its_price(): void
    {
        $id = $this->offer();
        $this->actAs($this->buyer)->postJson("/api/quotes/$id/add-to-cart")->assertUnprocessable()->assertJsonValidationErrors('quote');
        $this->postJson("/api/quotes/$id/accept")->assertOk();

        $this->postJson("/api/quotes/$id/add-to-cart")->assertCreated()
            ->assertJsonPath('data.groups.0.lines.0.kind', 'quote')
            ->assertJsonPath('data.groups.0.lines.0.line_total_santim', 8_800_000);
        // Adding twice keeps one line; the quantity is fixed by the offer.
        $this->postJson("/api/quotes/$id/add-to-cart")->assertCreated();
        $this->assertSame(1, CartItem::count());
        $this->putJson('/api/cart/items/'.CartItem::first()->id, ['quantity' => 1])->assertUnprocessable();

        $this->checkout()->assertCreated()->assertJsonPath('data.grand_total_santim', 8_800_000 + 80_000);
        $item = OrderItem::first();
        $this->assertSame($id, $item->quote_id);
        $this->assertSame(25, $item->production_days);
        $this->assertSame(0, $item->reserved_quantity);
        $this->assertSame(80_000, Order::first()->delivery_fee_santim);
        $this->assertSame($this->seller->id, Order::first()->seller_profile_id);

        // Ordered once: cannot be added again.
        $this->postJson("/api/quotes/$id/add-to-cart")->assertUnprocessable()->assertJsonPath('errors.quote.0', 'This quote has already been ordered.');

        // After the order is cancelled the agreed offer can be ordered again.
        $this->postJson('/api/orders/'.Order::first()->id.'/cancel', ['reason' => 'Wrong address'])->assertOk();
        $this->postJson("/api/quotes/$id/add-to-cart")->assertCreated();
    }

    public function test_other_customers_and_declined_offers_cannot_be_ordered(): void
    {
        $id = $this->offer();
        $this->actAs($this->customer())->postJson("/api/quotes/$id/add-to-cart")->assertNotFound();
        $this->actAs($this->buyer)->postJson("/api/quotes/$id/decline")->assertOk();
        $this->postJson("/api/quotes/$id/add-to-cart")->assertUnprocessable();
    }

    public function test_accepted_offer_has_a_grace_period_then_expires(): void
    {
        $id = $this->offer(['valid_until' => now()->toDateString()], ['valid_until' => now()->toDateString()]);
        $this->actAs($this->buyer)->postJson("/api/quotes/$id/accept")->assertOk();

        // Validity ended, but acceptance was within the 7-day grace window.
        $this->travel(3)->days();
        $this->postJson("/api/quotes/$id/add-to-cart")->assertCreated();

        $this->travel(5)->days();
        $this->getJson('/api/cart')->assertJsonPath('data.groups.0.lines.0.problem', 'This offer has expired. Ask Betoch Support for a new one.');
        $this->checkout()->assertStatus(409);
        $this->assertSame(0, Order::count());
    }

    public function test_offer_in_cart_blocks_checkout_if_store_is_suspended(): void
    {
        $id = $this->offer();
        $this->actAs($this->buyer)->postJson("/api/quotes/$id/accept")->assertOk();
        $this->postJson("/api/quotes/$id/add-to-cart")->assertCreated();
        $this->seller->forceFill(['status' => 'suspended'])->save();

        $this->checkout()->assertStatus(409)->assertJsonPath('data.groups.0.lines.0.problem', 'This store is not taking orders right now.');
    }

    public function test_offer_and_listing_from_same_store_make_one_order(): void
    {
        $product = Product::factory()->published()->create([
            'seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id,
            'price_santim' => 500_000, 'stock_quantity' => 2, 'delivery_fee_santim' => 30_000,
        ]);
        $id = $this->offer();
        $this->actAs($this->buyer)->postJson("/api/quotes/$id/accept")->assertOk();
        $this->postJson("/api/quotes/$id/add-to-cart")->assertCreated();
        $this->postJson('/api/cart/items', ['product_slug' => $product->slug])->assertCreated();

        $this->checkout()->assertCreated()->assertJsonCount(1, 'data.orders');
        $order = Order::first();
        $this->assertSame(2, $order->items()->count());
        // One trip: the higher of the two fees.
        $this->assertSame(80_000, $order->delivery_fee_santim);
        $this->assertSame(1, $product->fresh()->stock_quantity);
        $this->assertSame('accepted', Quote::find($id)->status->value);
    }

    public function test_the_inquiry_shows_whether_an_accepted_offer_can_still_be_ordered(): void
    {
        $id = $this->offer(['valid_until' => now()->addDay()->toDateString()], ['valid_until' => now()->addDay()->toDateString()]);
        $offer = fn () => collect($this->getJson("/api/support/tickets/{$this->ticketId}")->assertOk()->json('data.offers'))->firstWhere('id', $id);

        $this->actAs($this->buyer);
        $this->assertNull($offer()['order_by']);
        $this->assertFalse($offer()['ordered']);

        $this->postJson("/api/quotes/$id/accept")->assertOk();
        // The grace period (7 days after acceptance) outlasts the 1-day validity.
        $this->assertSame(now()->addDays(7)->toDateString(), substr($offer()['order_by'], 0, 10));

        $this->postJson("/api/quotes/$id/add-to-cart")->assertCreated();
        $this->checkout()->assertCreated();
        $this->assertTrue($offer()['ordered']);

        // Cancelling the order makes the offer orderable again.
        $this->actAs($this->seller->user)->postJson('/api/seller/orders/'.Order::first()->id.'/cancel', ['reason' => 'Walnut ran out'])->assertOk();
        $this->actAs($this->buyer);
        $this->assertFalse($offer()['ordered']);
    }

    public function test_an_accepted_quote_from_an_old_store_chat_still_orders(): void
    {
        $chat = $this->oldConversation($this->buyer, $this->seller);
        $id = $this->oldQuote($chat, ['price_santim' => 2_000_000, 'delivery_fee_santim' => 0])->id;

        $this->actAs($this->buyer)->postJson("/api/quotes/$id/accept")->assertOk();
        $this->postJson("/api/quotes/$id/add-to-cart")->assertCreated()
            ->assertJsonPath('data.groups.0.lines.0.quote.conversation_id', $chat->id)
            ->assertJsonPath('data.groups.0.lines.0.quote.support_ticket_id', null);
        $this->checkout()->assertCreated()->assertJsonPath('data.grand_total_santim', 2_000_000);
        $this->getJson("/api/conversations/{$chat->id}")->assertJsonPath('data.quotes.0.ordered', true);
    }
}
