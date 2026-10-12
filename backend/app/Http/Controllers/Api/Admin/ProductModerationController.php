<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ManagedProductResource;
use App\Models\Product;
use App\Services\Alerts;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductModerationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ProductStatus::class)],
            'q' => ['nullable', 'string', 'max:100'],
            'seller' => ['nullable', 'integer'],
        ]);

        $products = Product::query()
            ->with(['primaryImage', 'category:id,name,slug', 'seller:id,store_name,slug,status'])
            ->where('status', $filters['status'] ?? ProductStatus::PendingReview->value)
            ->when($filters['seller'] ?? null, fn ($q, $id) => $q->where('seller_profile_id', $id))
            ->search($filters['q'] ?? null)
            ->oldest('updated_at')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        return ManagedProductResource::collection($products);
    }

    public function show(int $id): ManagedProductResource
    {
        $product = Product::with(['images', 'category:id,name,slug', 'seller', 'area:id,name'])->findOrFail($id);

        return new ManagedProductResource($product);
    }

    public function approve(Request $request, int $id, AuditLogger $audit): ManagedProductResource
    {
        return $this->transition($request, $id, $audit, 'approve', [ProductStatus::PendingReview], ProductStatus::Published, false);
    }

    /**
     * Reject a pending listing, or take down a published one. A note for the seller is required.
     */
    public function reject(Request $request, int $id, AuditLogger $audit): ManagedProductResource
    {
        return $this->transition($request, $id, $audit, 'reject', [ProductStatus::PendingReview, ProductStatus::Published], ProductStatus::Rejected, true);
    }

    /**
     * @param  list<ProductStatus>  $from
     */
    private function transition(Request $request, int $id, AuditLogger $audit, string $action, array $from, ProductStatus $to, bool $noteRequired): ManagedProductResource
    {
        $data = $request->validate([
            'note' => [$noteRequired ? 'required' : 'nullable', 'string', 'max:500'],
        ]);

        $product = DB::transaction(function () use ($id, $from, $to, $action, $data, $audit) {
            $product = Product::with('seller')->lockForUpdate()->findOrFail($id);

            if (! in_array($product->status, $from, true)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot {$action} a listing whose status is {$product->status->value}.",
                ]);
            }
            if ($to === ProductStatus::Published && ! $product->seller->isApproved()) {
                throw ValidationException::withMessages(['status' => 'The seller is not approved, so this listing cannot be published.']);
            }
            if ($to === ProductStatus::Published && ! $product->images()->exists()) {
                throw ValidationException::withMessages(['status' => 'A listing needs at least one photo to be published.']);
            }

            $previous = $product->status;
            $product->status = $to;
            $product->moderation_note = $data['note'] ?? null;
            if ($to === ProductStatus::Published) {
                $product->published_at ??= now();
            }
            $product->save();

            $audit->log("product.{$action}", $product, ['from' => $previous->value, 'to' => $to->value, 'note' => $data['note'] ?? null]);
            app(Alerts::class)->listingReviewed($product, $action, $data['note'] ?? null);

            return $product;
        });

        return new ManagedProductResource($product->load(['images', 'category:id,name,slug', 'seller']));
    }
}
