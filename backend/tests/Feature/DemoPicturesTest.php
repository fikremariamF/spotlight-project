<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use Database\Seeders\DemoFurnitureArt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoPicturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_redraws_only_old_demo_pictures_and_is_safe_to_rerun(): void
    {
        Storage::fake('public');
        $this->seedReferenceData();
        $seller = $this->approvedSeller();
        $sofa = Product::factory()->published()->create(['seller_profile_id' => $seller->id, 'category_id' => $this->category()->id, 'name' => 'Grey Sofa', 'color' => 'Grey']);

        $old = $sofa->images()->create(['path' => "products/{$sofa->id}/demo-abcd1234.jpg", 'sort_order' => 0]);
        $photo = $sofa->images()->create(['path' => "products/{$sofa->id}/real-photo.jpg", 'sort_order' => 1]);
        $tricky = $sofa->images()->create(['path' => "products/{$sofa->id}/demo-../../secret.jpg", 'sort_order' => 2]);
        foreach ([$old, $photo] as $img) {
            Storage::disk('public')->put($img->path, 'x');
        }

        $before = ProductImage::count();
        $this->artisan('marketplace:demo-pictures')->expectsOutput('Redrew 1 demo picture.')->assertSuccessful();

        $redrawn = $old->fresh()->path;
        $this->assertMatchesRegularExpression("#^products/{$sofa->id}/demo-art-[A-Za-z0-9]{8}\.jpg$#", $redrawn);
        Storage::disk('public')->assertExists($redrawn);
        Storage::disk('public')->assertMissing("products/{$sofa->id}/demo-abcd1234.jpg");
        $this->assertSame("products/{$sofa->id}/real-photo.jpg", $photo->fresh()->path, 'seller photos are never touched');
        Storage::disk('public')->assertExists($photo->path);
        $this->assertSame("products/{$sofa->id}/demo-../../secret.jpg", $tricky->fresh()->path, 'odd paths are skipped');
        $this->assertSame('image/jpeg', getimagesizefromstring(Storage::disk('public')->get($redrawn))['mime']);
        $copies = [$old->fresh()->thumb_path, $old->fresh()->large_path];
        $this->assertNotContains(null, $copies, 'the redrawn picture gets its smaller copies');
        Storage::disk('public')->assertExists($copies);

        $this->artisan('marketplace:demo-pictures')->expectsOutput('Demo pictures are up to date.')->assertSuccessful();
        $this->assertSame($redrawn, $old->fresh()->path);
        $this->assertSame($before, ProductImage::count(), 'pictures are replaced, never added');
    }

    public function test_draws_every_kind_of_demo_listing(): void
    {
        $art = new DemoFurnitureArt;
        foreach (['Sofa', 'Armchair', 'Bed', 'Bunk Bed', 'Mattress', 'Wardrobe', 'Dresser', 'Filing Cabinet', 'Bookshelf', 'Display Cabinet', 'Desk', 'Kids Desk', 'Office Chair', 'Dining Set', 'Chair', 'Bench', 'Patio Set', 'Lamp'] as $i => $name) {
            $info = getimagesizefromstring($art->jpeg($name, $i % 2 ? 'Unknown colour' : 'walnut', $i));
            $this->assertSame([800, 600, 'image/jpeg'], [$info[0], $info[1], $info['mime']], $name);
        }
    }
}
