<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProductCondition;
use App\Enums\StockType;
use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\PublicSellerResource;
use App\Models\Area;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class CatalogController extends Controller
{
    public const SORTS = ['recommended', 'newest', 'price_asc', 'price_desc'];

    public const MAX_SLUGS = 24;

    public function categories(): AnonymousResourceCollection
    {
        $categories = Category::active()
            ->whereNull('parent_id')
            ->with(['children' => fn ($q) => $q->active()->orderBy('sort_order')->orderBy('name')])
            ->withCount(['products' => fn (Builder $q) => $q->visible()])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return CategoryResource::collection($categories);
    }

    public function areas(): JsonResponse
    {
        $areas = Area::query()
            ->where('is_active', true)
            ->whereHas('city', fn ($q) => $q->where('is_active', true))
            ->with('city:id,name,slug')
            ->orderBy('name')
            ->get()
            ->map(fn (Area $a) => ['id' => $a->id, 'name' => $a->name, 'city' => $a->city->name]);

        return response()->json(['data' => $areas]);
    }

    public function products(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:120'],
            'seller' => ['nullable', 'string', 'max:140'],
            'min_price' => ['nullable', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'max_price' => ['nullable', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'condition' => ['nullable', Rule::enum(ProductCondition::class)],
            'stock_type' => ['nullable', Rule::enum(StockType::class)],
            'material' => ['nullable', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:60'],
            'area' => ['nullable', 'integer'],
            'custom' => ['nullable', 'boolean'],
            'delivery' => ['nullable', 'boolean'],
            'in_stock' => ['nullable', 'boolean'],
            // Comma-separated listing slugs, for recently viewed and compare. Unknown or hidden ones are skipped.
            'slugs' => ['nullable', 'string', 'max:3000'],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('marketplace.catalog.per_page_max')],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $min = isset($filters['min_price']) ? Money::toSantim($filters['min_price']) : null;
        $max = isset($filters['max_price']) ? Money::toSantim($filters['max_price']) : null;
        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        $query = Product::query()
            ->visible()
            ->search($filters['q'] ?? null)
            ->with(['primaryImage', 'category:id,name,slug', 'seller:id,store_name,slug,area_id', 'seller.area:id,name', 'area:id,name'])
            ->withCount('variants');

        if (! empty($filters['category'])) {
            $category = Category::active()->where('slug', $filters['category'])->first();
            // Unknown or inactive category: return an empty page rather than every product.
            $query->whereIn('category_id', $category ? $category->selfAndChildIds() : [0]);
        }

        if (! empty($filters['slugs'])) {
            $slugs = collect(explode(',', $filters['slugs']))->map(fn ($s) => trim($s))->filter()->unique()->take(self::MAX_SLUGS)->values();
            $query->whereIn('products.slug', $slugs->all());
        }

        if (! empty($filters['seller'])) {
            $query->whereHas('seller', fn ($q) => $q->where('slug', $filters['seller']));
        }

        $query
            ->when($min !== null, fn ($q) => $q->where('price_santim', '>=', $min))
            ->when($max !== null, fn ($q) => $q->where('price_santim', '<=', $max))
            ->when($filters['condition'] ?? null, fn ($q, $v) => $q->where('condition', $v))
            ->when($filters['stock_type'] ?? null, fn ($q, $v) => $q->where('stock_type', $v))
            ->when($filters['material'] ?? null, fn ($q, $v) => $q->whereRaw('LOWER(material) = ?', [mb_strtolower($v)]))
            ->when($filters['color'] ?? null, fn ($q, $v) => $q->whereRaw('LOWER(color) = ?', [mb_strtolower($v)]))
            ->when($filters['area'] ?? null, fn ($q, $v) => $q->where('products.area_id', $v))
            ->when($request->boolean('custom'), fn ($q) => $q->where('custom_order_available', true))
            ->when($request->boolean('delivery'), fn ($q) => $q->where('offers_delivery', true))
            ->when($request->boolean('in_stock'), fn ($q) => $q->where(fn ($q) => $q
                ->where('stock_type', StockType::MadeToOrder->value)
                ->orWhere('stock_quantity', '>', 0)));

        match ($filters['sort'] ?? 'recommended') {
            'newest' => $query->orderByDesc('published_at'),
            'price_asc' => $query->orderBy('price_santim'),
            'price_desc' => $query->orderByDesc('price_santim'),
            default => $query->orderByDesc('is_featured')->orderByDesc('published_at'),
        };
        // Stable tiebreaker so pagination never repeats or skips rows.
        $query->orderByDesc('products.id');

        $this->withFavoriteFlag($request, $query);

        $perPage = (int) ($filters['per_page'] ?? config('marketplace.catalog.per_page_default'));

        return ProductResource::collection($query->paginate($perPage)->withQueryString());
    }

    public function product(Request $request, string $slug): ProductResource
    {
        $query = Product::visible()
            ->where('slug', $slug)
            ->with(['images', 'category:id,name,slug', 'seller.area', 'seller.city', 'area:id,name', 'variants']);

        $this->withFavoriteFlag($request, $query);

        return new ProductResource($query->firstOrFail());
    }

    public function seller(string $slug): JsonResponse
    {
        $seller = SellerProfile::publiclyVisible()
            ->where('slug', $slug)
            ->with(['city', 'area'])
            ->withCount(['products' => fn (Builder $q) => $q->visible()])
            ->firstOrFail();

        return response()->json(['data' => new PublicSellerResource($seller)]);
    }

    /**
     * Adds an is_favorite flag when the request carries a valid token. Public routes stay public.
     */
    private function withFavoriteFlag(Request $request, Builder $query): void
    {
        $user = $request->user('sanctum');
        if ($user) {
            $query->withExists(['favoritedBy as is_favorite' => fn ($q) => $q->where('users.id', $user->id)]);
        }
    }
}
