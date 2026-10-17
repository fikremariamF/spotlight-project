<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

/**
 * Customers reach stores only through Betoch Support, so a store's phone is never shown to shoppers.
 * Admins and the store itself still see it.
 */
class SellerPhoneVisibilityTest extends TestCase
{
    use PlacesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrders();
    }

    public function test_shoppers_never_see_a_store_phone(): void
    {
        $phone = $this->seller->phone;
        $digits = substr(preg_replace('/\D/', '', $phone), -9);
        $pages = [
            "/api/sellers/{$this->seller->slug}",
            "/api/products/{$this->sofa->slug}",
            '/api/products',
            '/api/config',
        ];
        foreach ($pages as $url) {
            $body = $this->getJson($url)->assertOk()->getContent();
            $this->assertStringNotContainsString($digits, $body, $url);
        }
        $this->getJson('/api/config')->assertJsonMissingPath('show_seller_phone');

        // Signed in, and on the customer's own order.
        $order = $this->placeOrder(1);
        $this->as($this->buyer)->getJson("/api/orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.seller.store_name', $this->seller->store_name)
            ->assertJsonMissingPath('data.seller.phone');
        $this->assertStringNotContainsString($digits, $this->getJson('/api/orders')->getContent());
        $this->assertStringNotContainsString($digits, $this->getJson("/api/products/{$this->sofa->slug}")->getContent());
    }

    public function test_the_store_and_admins_still_see_it(): void
    {
        $order = $this->placeOrder(1);
        $this->as($this->seller->user)->getJson("/api/seller/orders/{$order->id}")->assertOk()->assertJsonPath('data.seller.phone', $this->seller->phone);
        $this->as($this->admin())->getJson("/api/admin/orders/{$order->id}")->assertOk()->assertJsonPath('data.seller.phone', $this->seller->phone);
    }
}
