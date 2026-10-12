<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Area;
use App\Models\Category;
use App\Models\DeliveryZone;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\ImageVariants;
use App\Support\Slug;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sample sellers and listings for local development and demos.
 * Refuses to run in production. Images are generated placeholders, not real photos.
 */
class DemoSeeder extends Seeder
{
    private const SELLERS = [
        ['Merkato Woodworks', 'workshop', 'addis-ketema', true],
        ['Bole Home Interiors', 'shop', 'bole', false],
        ['Entoto Craft Furniture', 'manufacturer', 'gulele', true],
        ['Kazanchis Office Supply', 'shop', 'kirkos', false],
    ];

    // [category slug, name, price birr, material, color, condition, stock type, qty/days, w, d, h]
    private const PRODUCTS = [
        ['sofas-living-room', 'Three-Seater Fabric Sofa', 38500, 'Fabric, Eucalyptus', 'Grey', 'new', 'in_stock', 4, 210, 90, 85],
        ['sofas-living-room', 'L-Shaped Sectional Sofa', 72000, 'Velvet, Hardwood', 'Emerald', 'new', 'made_to_order', 21, 280, 180, 88],
        ['sofas-living-room', 'Mahogany Coffee Table', 14500, 'Mahogany', 'Dark Brown', 'new', 'in_stock', 6, 120, 60, 45],
        ['sofas-living-room', 'Used Leather Armchair', 9800, 'Leather', 'Tan', 'used', 'in_stock', 1, 85, 90, 95],
        ['beds-mattresses', 'Queen Bed Frame with Headboard', 42000, 'Wanza Wood', 'Walnut', 'new', 'in_stock', 3, 165, 210, 110],
        ['beds-mattresses', 'Orthopedic Spring Mattress 160x200', 26000, 'Foam, Springs', 'White', 'new', 'in_stock', 10, 160, 200, 28],
        ['beds-mattresses', 'Kids Bunk Bed', 31000, 'Pine', 'Natural', 'new', 'made_to_order', 14, 100, 200, 160],
        ['wardrobes-storage', 'Four-Door Wardrobe with Mirror', 54000, 'MDF, Mirror', 'White', 'new', 'in_stock', 2, 180, 60, 210],
        ['wardrobes-storage', 'Six-Drawer Dresser', 19500, 'Zigba Wood', 'Honey', 'new', 'in_stock', 5, 120, 45, 90],
        ['dining', 'Six-Seat Dining Set', 48000, 'Hardwood, Fabric', 'Espresso', 'new', 'in_stock', 2, 180, 90, 76],
        ['dining', 'Round Four-Seat Dining Table', 22500, 'Oak Veneer', 'Light Oak', 'new', 'in_stock', 4, 110, 110, 75],
        ['dining', 'Upholstered Dining Chair (each)', 4200, 'Fabric, Steel', 'Beige', 'new', 'in_stock', 24, 48, 55, 92],
        ['office', 'Executive Office Desk', 36000, 'Mahogany Veneer', 'Mahogany', 'new', 'in_stock', 3, 180, 85, 76],
        ['office', 'Ergonomic Mesh Office Chair', 12800, 'Mesh, Nylon', 'Black', 'new', 'in_stock', 15, 65, 65, 120],
        ['office', 'Used Steel Filing Cabinet', 5200, 'Steel', 'Grey', 'used', 'in_stock', 3, 47, 62, 132],
        ['cabinets-shelves', 'Five-Tier Bookshelf', 11500, 'Pine', 'White', 'new', 'in_stock', 7, 80, 30, 180],
        ['cabinets-shelves', 'Glass Display Cabinet', 27500, 'Wood, Glass', 'Black', 'new', 'in_stock', 2, 90, 40, 190],
        ['cabinets-shelves', 'Kitchen Wall Cabinet Set', 46000, 'MDF, Laminate', 'Cream', 'new', 'made_to_order', 25, 240, 35, 70],
        ['custom-made', 'Custom Built-In Wardrobe', 95000, 'Your choice', 'Your choice', 'new', 'made_to_order', 30, null, null, null],
        ['custom-made', 'Custom Carved Traditional Bench', 18000, 'Wanza Wood', 'Natural', 'new', 'made_to_order', 20, 160, 45, 85],
        ['other-furniture', 'Outdoor Patio Set', 24500, 'Rattan, Steel', 'Brown', 'new', 'in_stock', 3, 120, 120, 75],
        ['other-furniture', 'Kids Study Desk and Chair', 8900, 'MDF', 'Blue', 'new', 'in_stock', 6, 90, 50, 70],
    ];

    // [zone name, demo fee birr, min days, max days, area slugs]
    private const ZONES = [
        ['Central Addis (demo)', 400, 1, 2, ['kirkos', 'arada', 'lideta', 'addis-ketema']],
        ['Inner ring (demo)', 600, 1, 3, ['bole', 'yeka', 'gulele', 'kolfe-keranio', 'nifas-silk-lafto']],
        ['Outer sub-cities (demo)', 900, 2, 4, ['akaky-kaliti', 'lemi-kura']],
    ];

    /** File name prefix of drawn demo pictures; older demo installs used plain "demo-". */
    public const ART_PREFIX = 'demo-art-';

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSeeder must not run in production.');
        }

        $this->call([LocationSeeder::class, CategorySeeder::class]);

        $password = env('DEMO_USER_PASSWORD') ?: Str::password(16);

        $sellers = collect(self::SELLERS)->map(function (array $row, int $i) use ($password) {
            [$store, $type, $areaSlug, $custom] = $row;
            $email = 'seller'.($i + 1).'@demo.local';

            $user = User::firstOrNew(['email' => $email]);
            if (! $user->exists) {
                $user->fill(['name' => $store.' Owner', 'password' => $password, 'phone' => '+25191100000'.$i]);
                $user->role = Role::Seller;
                $user->save();
            }

            $area = Area::where('slug', $areaSlug)->firstOrFail();

            return SellerProfile::firstOrCreate(['user_id' => $user->id], [
                'store_name' => $store,
                'slug' => Slug::unique(SellerProfile::class, $store),
                'description' => "{$store} builds and sells quality furniture in Addis Ababa.",
                'business_type' => $type,
                'phone' => '+25191100000'.$i,
                'city_id' => $area->city_id,
                'area_id' => $area->id,
                'offers_custom_orders' => $custom,
                'offers_delivery' => true,
                'status' => 'approved',
                'reviewed_at' => now(),
            ]);
        });

        $customer = User::firstOrNew(['email' => 'customer@demo.local']);
        if (! $customer->exists) {
            $customer->fill(['name' => 'Demo Customer', 'password' => $password])->save();
        }

        foreach (self::PRODUCTS as $i => $row) {
            [$cat, $name, $price, $material, $color, $condition, $stockType, $qtyOrDays, $w, $d, $h] = $row;
            $seller = $sellers[$i % $sellers->count()];

            if (Product::where('seller_profile_id', $seller->id)->where('name', $name)->exists()) {
                continue;
            }

            $product = new Product([
                'category_id' => Category::where('slug', $cat)->value('id'),
                'name' => $name,
                'description' => "{$name} from {$seller->store_name}. Solid construction, finished by hand in Addis Ababa. Contact the seller for other sizes or finishes.",
                'price_santim' => $price * 100,
                'price_negotiable' => $i % 3 === 0,
                'condition' => $condition,
                'stock_type' => $stockType,
                'stock_quantity' => $stockType === 'in_stock' ? $qtyOrDays : 0,
                'production_days' => $stockType === 'made_to_order' ? $qtyOrDays : null,
                'custom_order_available' => $stockType === 'made_to_order' || $seller->offers_custom_orders,
                'width_cm' => $w, 'depth_cm' => $d, 'height_cm' => $h,
                'material' => $material,
                'color' => $color,
                'area_id' => $seller->area_id,
                'offers_delivery' => true,
                'pickup_available' => $i % 2 === 0,
                'delivery_fee_santim' => $i % 4 === 0 ? null : (300 + ($i % 3) * 200) * 100,
                'delivery_notes' => 'Delivery within Addis Ababa in 2 to 5 days after confirmation.',
            ]);
            $product->seller_profile_id = $seller->id;
            $product->slug = Slug::unique(Product::class, $name);
            $product->status = 'published';
            $product->published_at = now()->subDays(count(self::PRODUCTS) - $i);
            $product->is_featured = $i % 5 === 0;
            $product->save();

            $path = "products/{$product->id}/".self::ART_PREFIX.Str::random(8).'.jpg';
            Storage::disk('public')->put($path, (new DemoFurnitureArt)->jpeg($name, $color, $i));
            $product->images()->create(['path' => $path, ...app(ImageVariants::class)->make($path), 'alt' => $name, 'sort_order' => 0]);
        }

        // Example zones so delivery estimates show something locally. The fees are made up;
        // real fees are set by an administrator under Admin > Delivery.
        foreach (self::ZONES as [$zoneName, $feeBirr, $min, $max, $areaSlugs]) {
            $zone = DeliveryZone::firstOrCreate(['name' => $zoneName], [
                'fee_santim' => $feeBirr * 100, 'min_days' => $min, 'max_days' => $max, 'is_active' => true,
            ]);
            Area::whereIn('slug', $areaSlugs)->whereNull('delivery_zone_id')->update(['delivery_zone_id' => $zone->id]);
        }

        if ($this->command) {
            $this->command->info('Demo users: seller1..4@demo.local and customer@demo.local');
            $this->command->info(env('DEMO_USER_PASSWORD')
                ? 'Password: the value of DEMO_USER_PASSWORD.'
                : "Generated password (shown once): {$password}");
        }
    }
}
