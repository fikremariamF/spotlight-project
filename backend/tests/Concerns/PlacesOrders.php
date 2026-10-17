<?php

namespace Tests\Concerns;

use App\Models\Address;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * Shared set-up for tests that need real orders: one approved seller, one listing, one customer with an address.
 */
trait PlacesOrders
{
    protected User $buyer;

    protected Address $address;

    protected SellerProfile $seller;

    protected Product $sofa;

    protected function setUpOrders(): void
    {
        // These fixtures price delivery with the listing's own fee; MarketplaceDeliveryFeeTest covers the switch.
        config(['marketplace.distance.stores_set_fee' => true]);
        $this->seedReferenceData();
        $this->seller = $this->approvedSeller();
        $this->sofa = Product::factory()->published()->create([
            'seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id,
            'price_santim' => 1_000_000, 'stock_quantity' => 50, 'delivery_fee_santim' => 30_000, 'pickup_available' => true,
        ]);
        $this->buyer = $this->customer();
        $this->address = $this->addressFor($this->buyer);
    }

    protected function addressFor(User $user): Address
    {
        return $user->addresses()->forceCreate([
            'user_id' => $user->id, 'recipient_name' => 'Abebe Kebede', 'phone' => '+251911000111',
            'area_id' => $this->area('bole')->id, 'address_line' => 'House 12', 'is_default' => true,
        ]);
    }

    protected function as(User $user): static
    {
        Sanctum::actingAs($user->fresh());

        return $this;
    }

    protected function placeOrder(int $qty = 2, ?User $buyer = null, ?Product $product = null, ?ProductVariant $variant = null): Order
    {
        $buyer ??= $this->buyer;
        $product ??= $this->sofa;
        $address = $buyer->addresses()->first() ?? $this->addressFor($buyer);
        $this->as($buyer)->postJson('/api/cart/items', ['product_slug' => $product->slug, 'quantity' => $qty, 'variant_id' => $variant?->id])->assertCreated();
        $expected = $this->getJson('/api/cart?address_id='.$address->id)->json('data.grand_total_santim');
        $this->postJson('/api/checkout', ['address_id' => $address->id, 'payment_method' => 'cod', 'expected_total_santim' => $expected],
            ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        return Order::latest('id')->first();
    }

    protected function deliver(Order $order): Order
    {
        $sellerUser = $order->seller->user;
        $this->as($sellerUser)->postJson("/api/seller/orders/{$order->id}/confirm")->assertOk();
        $this->postJson("/api/seller/orders/{$order->id}/status", ['status' => 'delivered'])->assertOk();

        return $order->fresh();
    }
}
