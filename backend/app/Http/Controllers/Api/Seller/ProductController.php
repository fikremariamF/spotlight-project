<?php

namespace App\Http\Controllers\Api\Seller;

use App\Enums\ProductStatus;
use App\Enums\SellerStatus;
use App\Enums\StockType;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProductRequest;
use App\Http\Resources\ManagedProductResource;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Services\Alerts;
use App\Services\AuditLogger;
use App\Services\ProductImageService;
use App\Support\Slug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A seller's own listings. Every lookup is scoped to the authenticated seller's profile,
 * so another seller's product id resolves to 404 instead of leaking its existence.
 */
class ProductController extends Controller
{
    public function __construct(private readonly ProductImageService $images) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ProductStatus::class)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $products = $this->profile($request)->products()
            ->with(['primaryImage', 'category:id,name,slug'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->search($filters['q'] ?? null)
            ->latest('updated_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return ManagedProductResource::collection($products);
    }

    public function show(Request $request, int $id): ManagedProductResource
    {
        $product = $this->findOwned($request, $id)->load(['images', 'category:id,name,slug', 'area:id,name']);

        return new ManagedProductResource($product);
    }

    public function store(ProductRequest $request, AuditLogger $audit): JsonResponse
    {
        $profile = $this->profile($request, writable: true);
        $submit = $request->input('action', 'draft') === 'submit';

        if ($submit && ! $profile->isApproved()) {
            throw ValidationException::withMessages([
                'action' => 'Your store must be approved before listings can be submitted for review. Save as a draft for now.',
            ]);
        }
        if ($submit && ! $request->hasFile('images')) {
            throw ValidationException::withMessages(['images' => 'Add at least one photo before submitting for review.']);
        }

        $product = DB::transaction(function () use ($request, $profile, $submit, $audit) {
            $product = new Product($this->normalizeStock($request->productAttributes()));
            $product->seller_profile_id = $profile->id;
            $product->slug = Slug::unique(Product::class, $request->input('name'));
            $product->status = $submit ? ProductStatus::PendingReview : ProductStatus::Draft;
            $product->area_id ??= $profile->area_id;
            $product->save();

            $this->images->storeMany($product, $request->file('images', []));

            $audit->log('product.created', $product, ['status' => $product->status->value]);
            if ($submit) {
                app(Alerts::class)->listingSubmitted($product);
            }

            return $product;
        });

        return (new ManagedProductResource($product->load(['images', 'category:id,name,slug'])))
            ->response()->setStatusCode(201);
    }

    public function update(ProductRequest $request, int $id, AuditLogger $audit): ManagedProductResource
    {
        $this->profile($request, writable: true);
        $product = $this->findOwned($request, $id);

        if ($product->status === ProductStatus::Archived) {
            throw ValidationException::withMessages(['status' => 'Restore this listing before editing it.']);
        }

        DB::transaction(function () use ($request, $product, $audit) {
            $product->fill($this->normalizeStock($request->productAttributes(), $product));

            if (! $product->offers_delivery && ! $product->pickup_available) {
                throw ValidationException::withMessages(['offers_delivery' => 'Offer delivery, pickup, or both.']);
            }
            if ($product->stock_type === StockType::MadeToOrder && ! $product->production_days) {
                throw ValidationException::withMessages(['production_days' => 'Enter the estimated production time in days.']);
            }

            if ($product->isDirty('name') && $product->getOriginal('status') !== ProductStatus::Published) {
                $product->slug = Slug::unique(Product::class, $product->name, $product->id);
            }

            $needsReview = $product->status === ProductStatus::Published
                && config('marketplace.products.reapprove_on_edit')
                && $product->isDirty(Product::MODERATED_FIELDS);

            if ($needsReview) {
                $product->status = ProductStatus::PendingReview;
            }

            $changed = array_keys($product->getDirty());
            $product->save();

            if ($request->hasFile('images')) {
                $this->images->storeMany($product, $request->file('images'));
            }

            if ($changed) {
                $audit->log('product.updated', $product, ['fields' => $changed, 'sent_to_review' => $needsReview]);
            }
            if ($needsReview) {
                app(Alerts::class)->listingSubmitted($product);
            }
        });

        return new ManagedProductResource($product->fresh(['images', 'category:id,name,slug']));
    }

    /**
     * Move a draft, rejected or archived listing into the moderation queue.
     */
    public function submit(Request $request, int $id, AuditLogger $audit): ManagedProductResource
    {
        $profile = $this->profile($request, writable: true);
        $product = $this->findOwned($request, $id);

        if (! $profile->isApproved()) {
            throw ValidationException::withMessages(['status' => 'Your store must be approved before listings can be submitted.']);
        }
        if (! in_array($product->status, [ProductStatus::Draft, ProductStatus::Rejected, ProductStatus::Archived], true)) {
            throw ValidationException::withMessages(['status' => 'This listing is already published or waiting for review.']);
        }
        if (! $product->images()->exists()) {
            throw ValidationException::withMessages(['images' => 'Add at least one photo before submitting for review.']);
        }

        $product->status = ProductStatus::PendingReview;
        $product->save();
        $audit->log('product.submitted', $product);
        app(Alerts::class)->listingSubmitted($product);

        return new ManagedProductResource($product->load(['images', 'category:id,name,slug']));
    }

    /**
     * Hide a listing from the marketplace without deleting its history.
     */
    public function archive(Request $request, int $id, AuditLogger $audit): ManagedProductResource
    {
        $product = $this->findOwned($request, $id);
        $product->status = ProductStatus::Archived;
        $product->save();
        $audit->log('product.archived', $product);

        return new ManagedProductResource($product->load(['images', 'category:id,name,slug']));
    }

    public function destroy(Request $request, int $id, AuditLogger $audit): JsonResponse
    {
        $product = $this->findOwned($request, $id);
        // Soft delete keeps the row for future order history; images stay until a cleanup job runs.
        $product->delete();
        $audit->log('product.deleted', $product);

        return response()->json(['message' => 'Listing deleted.']);
    }

    public function uploadImages(Request $request, int $id): ManagedProductResource
    {
        $this->profile($request, writable: true);
        $product = $this->findOwned($request, $id);

        $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:'.config('marketplace.products.max_images')],
            'images.*' => ['image', 'mimes:'.implode(',', config('marketplace.products.image_mimes')),
                'max:'.config('marketplace.products.max_image_kb'), 'dimensions:min_width=200,min_height=200,max_width=8000,max_height=8000'],
        ]);

        $this->images->storeMany($product, $request->file('images'));

        return new ManagedProductResource($product->load(['images', 'category:id,name,slug']));
    }

    public function deleteImage(Request $request, int $id, int $imageId): ManagedProductResource
    {
        $product = $this->findOwned($request, $id);
        $image = $product->images()->whereKey($imageId)->firstOrFail();

        if ($product->status === ProductStatus::Published && $product->images()->count() <= 1) {
            throw ValidationException::withMessages(['images' => 'A published listing needs at least one photo. Upload another first.']);
        }

        $this->images->delete($image);

        return new ManagedProductResource($product->load(['images', 'category:id,name,slug']));
    }

    private function profile(Request $request, bool $writable = false): SellerProfile
    {
        $profile = $request->user()->sellerProfile;
        abort_unless($profile, 403, 'No seller profile found for this account.');

        if ($writable && in_array($profile->status, [SellerStatus::Suspended, SellerStatus::Rejected], true)) {
            abort(403, 'Your store is '.$profile->status->value.'. Contact support.');
        }

        return $profile;
    }

    private function findOwned(Request $request, int $id): Product
    {
        return $this->profile($request)->products()->whereKey($id)->firstOrFail();
    }

    /**
     * Keep stock fields consistent: made-to-order items carry no stock count,
     * in-stock items carry no production time.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeStock(array $data, ?Product $existing = null): array
    {
        $type = $data['stock_type'] ?? $existing?->stock_type?->value;

        if ($type === StockType::MadeToOrder->value) {
            $data['stock_quantity'] = 0;
        } elseif ($type === StockType::InStock->value) {
            $data['production_days'] = null;
            if (array_key_exists('stock_quantity', $data) && $data['stock_quantity'] === null) {
                $data['stock_quantity'] = 0;
            }
        }

        return $data;
    }
}
