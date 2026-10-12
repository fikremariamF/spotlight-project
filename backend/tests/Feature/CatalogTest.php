<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    private SellerProfile $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->seller = $this->approvedSeller();
    }

    private function published(array $attrs = [], ?SellerProfile $seller = null): Product
    {
        return Product::factory()->published()->create(array_merge([
            'seller_profile_id' => ($seller ?? $this->seller)->id,
            'category_id' => $this->category()->id,
        ], $attrs));
    }

    private function listedIds(string $query = ''): array
    {
        return collect($this->getJson('/api/products'.$query)->assertOk()->json('data'))->pluck('id')->all();
    }

    public function test_slugs_filter_returns_only_those_visible_listings(): void
    {
        $a = $this->published(['slug' => 'oak-table']);
        $b = $this->published(['slug' => 'grey-sofa']);
        $this->published(['slug' => 'not-asked']);
        $hidden = Product::factory()->create(['seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id, 'slug' => 'draft-bed']);

        $ids = $this->listedIds('?slugs='.urlencode(' oak-table, grey-sofa,draft-bed,unknown,,oak-table '));
        sort($ids);
        $this->assertSame([$a->id, $b->id], $ids, 'draft and unknown slugs are skipped');
        $this->assertNotContains($hidden->id, $ids);

        // Only the first 24 distinct slugs count, so the list cannot be used to pull the whole catalog.
        $many = collect(range(1, 30))->map(fn ($i) => "x{$i}")->push('oak-table')->implode(',');
        $this->assertSame([], $this->listedIds('?slugs='.$many));
        $this->getJson('/api/products?slugs='.str_repeat('a', 3001))->assertUnprocessable();
    }

    public function test_only_publicly_visible_listings_are_returned(): void
    {
        $visible = $this->published(['name' => 'Visible Sofa']);

        $hidden = [];
        foreach (['draft', 'pending_review', 'rejected', 'archived'] as $status) {
            $hidden[] = Product::factory()->create(['seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id, 'status' => $status])->id;
        }
        $hidden[] = $this->published([], $this->pendingSeller())->id;
        $hidden[] = $this->published([], SellerProfile::factory()->suspended()->create())->id;

        $suspendedOwner = $this->approvedSeller();
        $suspendedOwner->user->forceFill(['status' => 'suspended'])->save();
        $hidden[] = $this->published([], $suspendedOwner)->id;

        $inactiveCategory = Category::factory()->create(['is_active' => false]);
        $hidden[] = $this->published(['category_id' => $inactiveCategory->id])->id;

        $deleted = $this->published();
        $deleted->delete();
        $hidden[] = $deleted->id;

        $this->assertSame([$visible->id], $this->listedIds());

        foreach ($hidden as $id) {
            $slug = Product::withTrashed()->find($id)->slug;
            $this->getJson("/api/products/{$slug}")->assertNotFound();
        }
        $this->getJson("/api/products/{$visible->slug}")->assertOk()->assertJsonPath('data.name', 'Visible Sofa');
    }

    public function test_public_payload_hides_moderation_fields_and_formats_money(): void
    {
        $product = $this->published(['price_santim' => 1250050, 'delivery_fee_santim' => null, 'moderation_note' => 'internal']);

        $this->getJson("/api/products/{$product->slug}")
            ->assertOk()
            ->assertJsonPath('data.price', '12500.50')
            ->assertJsonPath('data.currency', 'ETB')
            ->assertJsonPath('data.delivery.fee', null)
            ->assertJsonMissingPath('data.status')
            ->assertJsonMissingPath('data.moderation_note');
    }

    public function test_keyword_search_matches_name_material_and_escapes_wildcards(): void
    {
        $a = $this->published(['name' => 'Walnut Bed', 'material' => 'Walnut']);
        $b = $this->published(['name' => 'Office Chair', 'material' => 'Mesh', 'description' => 'Ergonomic chair 100% mesh back.']);
        $this->published(['name' => 'Plain Table', 'material' => 'Pine', 'description' => 'Simple table.']);

        $this->assertSame([$a->id], $this->listedIds('?q=walnut'));
        $this->assertSame([$a->id], $this->listedIds('?q=WALNUT%20bed'));
        $this->assertSame([$b->id], $this->listedIds('?q=100%25'));
        // A bare "%" or "_" must match literally, not everything.
        $this->assertSame([$b->id], $this->listedIds('?q=%25'));
        $this->assertSame([], $this->listedIds('?q=_'));
        $this->assertSame([], $this->listedIds("?q=' OR 1=1 --"));
    }

    public function test_category_filter_includes_subcategories_and_unknown_category_is_empty(): void
    {
        $parent = $this->category('sofas-living-room');
        $child = Category::factory()->create(['parent_id' => $parent->id, 'slug' => 'armchairs']);
        $inParent = $this->published(['category_id' => $parent->id]);
        $inChild = $this->published(['category_id' => $child->id]);
        $other = $this->published(['category_id' => $this->category('dining')->id]);

        $ids = $this->listedIds('?category=sofas-living-room');
        sort($ids);
        $this->assertSame([$inParent->id, $inChild->id], $ids);
        $this->assertSame([$inChild->id], $this->listedIds('?category=armchairs'));
        $this->assertSame([$other->id], $this->listedIds('?category=dining'));
        $this->assertSame([], $this->listedIds('?category=does-not-exist'));
    }

    public function test_price_filters_are_inclusive_and_tolerate_swapped_bounds(): void
    {
        $cheap = $this->published(['price_santim' => 500000]);   // 5,000
        $mid = $this->published(['price_santim' => 1000000]);    // 10,000
        $dear = $this->published(['price_santim' => 5000000]);   // 50,000

        $this->assertEqualsCanonicalizing([$cheap->id, $mid->id], $this->listedIds('?max_price=10000'));
        $this->assertEqualsCanonicalizing([$mid->id, $dear->id], $this->listedIds('?min_price=10000'));
        $this->assertSame([$mid->id], $this->listedIds('?min_price=10000&max_price=10000'));
        $this->assertSame([$mid->id], $this->listedIds('?min_price=20000&max_price=6000'));
        $this->getJson('/api/products?min_price=-1')->assertUnprocessable();
        $this->getJson('/api/products?max_price=abc')->assertUnprocessable();
    }

    public function test_attribute_filters(): void
    {
        $used = $this->published(['condition' => 'used', 'color' => 'Red']);
        $mto = $this->published(['stock_type' => 'made_to_order', 'stock_quantity' => 0, 'production_days' => 14, 'custom_order_available' => true]);
        $soldOut = $this->published(['stock_quantity' => 0]);

        $this->assertSame([$used->id], $this->listedIds('?condition=used'));
        $this->assertSame([$used->id], $this->listedIds('?color=red'));
        $this->assertSame([$mto->id], $this->listedIds('?stock_type=made_to_order'));
        $this->assertSame([$mto->id], $this->listedIds('?custom=1'));
        $this->assertNotContains($soldOut->id, $this->listedIds('?in_stock=1'));
        $this->assertContains($mto->id, $this->listedIds('?in_stock=1'));
        $this->getJson('/api/products?condition=broken')->assertUnprocessable();
    }

    public function test_sorting_and_stable_pagination(): void
    {
        foreach ([30000, 10000, 20000, 20000, 40000] as $i => $birr) {
            $this->published(['price_santim' => $birr * 100, 'published_at' => now()->subDays($i)]);
        }

        $prices = fn (string $sort) => collect($this->getJson("/api/products?sort={$sort}")->json('data'))->pluck('price_santim')->all();
        $this->assertSame([1000000, 2000000, 2000000, 3000000, 4000000], $prices('price_asc'));
        $this->assertSame([4000000, 3000000, 2000000, 2000000, 1000000], $prices('price_desc'));
        $this->getJson('/api/products?sort=popularity')->assertUnprocessable();

        // Walking pages of 2 returns every product exactly once, even with tied prices.
        $seen = [];
        for ($page = 1; $page <= 3; $page++) {
            $seen = [...$seen, ...collect($this->getJson("/api/products?sort=price_asc&per_page=2&page={$page}")->json('data'))->pluck('id')->all()];
        }
        $this->assertCount(5, $seen);
        $this->assertCount(5, array_unique($seen));

        $this->getJson('/api/products?per_page=1000')->assertUnprocessable();
        $this->getJson('/api/products?page=99')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_categories_endpoint_lists_active_categories_with_visible_counts(): void
    {
        $this->published();
        Product::factory()->create(['seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id, 'status' => 'draft']);
        $this->category('dining')->update(['is_active' => false]);

        $data = collect($this->getJson('/api/categories')->assertOk()->json('data'));

        $this->assertFalse($data->contains('slug', 'dining'));
        $this->assertSame(1, $data->firstWhere('slug', 'sofas-living-room')['products_count']);
    }

    public function test_seller_storefront_visibility(): void
    {
        $this->published();
        $this->getJson("/api/sellers/{$this->seller->slug}")
            ->assertOk()
            ->assertJsonPath('data.products_count', 1)
            ->assertJsonMissingPath('data.tin_number')
            ->assertJsonMissingPath('data.status');

        $this->getJson("/api/products?seller={$this->seller->slug}")->assertOk()->assertJsonCount(1, 'data');

        $pending = $this->pendingSeller();
        $this->getJson("/api/sellers/{$pending->slug}")->assertNotFound();
    }

    public function test_favorite_flag_and_wishlist(): void
    {
        $product = $this->published();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/favorites/{$product->id}")->assertCreated();
        $this->postJson("/api/favorites/{$product->id}")->assertCreated(); // idempotent
        $this->assertDatabaseCount('favorites', 1);

        $this->getJson('/api/products')->assertJsonPath('data.0.is_favorite', true);
        $this->getJson('/api/favorites')->assertOk()->assertJsonCount(1, 'data');

        // A favorite whose listing gets unpublished disappears from the wishlist.
        $product->forceFill(['status' => 'archived'])->save();
        $this->getJson('/api/favorites')->assertOk()->assertJsonCount(0, 'data');

        $this->deleteJson("/api/favorites/{$product->id}")->assertOk();
        $this->assertDatabaseCount('favorites', 0);
    }

    public function test_cannot_favorite_hidden_or_missing_products(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $draft = Product::factory()->create(['seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id]);

        $this->postJson("/api/favorites/{$draft->id}")->assertNotFound();
        $this->postJson('/api/favorites/999999')->assertNotFound();
    }

    public function test_not_found_does_not_leak_model_names(): void
    {
        $this->getJson('/api/products/nope')->assertNotFound()->assertExactJson(['message' => 'Not found.']);
        $this->getJson('/api/sellers/nope')->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    }

    public function test_guest_listing_has_no_favorite_flag(): void
    {
        $this->published();
        $this->getJson('/api/products')->assertOk()->assertJsonMissingPath('data.0.is_favorite');
    }

    public function test_browsing_limit_comes_from_config(): void
    {
        config(['marketplace.catalog.requests_per_minute' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/products')->assertOk();
        }
        $this->getJson('/api/products')->assertStatus(429);
    }
}
