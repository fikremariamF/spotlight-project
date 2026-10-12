<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\SellerStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SellerProfileResource;
use App\Models\SellerProfile;
use App\Services\Alerts;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SellerController extends Controller
{
    /**
     * Allowed review transitions: action => [from statuses, to status, reason required].
     */
    private const TRANSITIONS = [
        'approve' => [[SellerStatus::Pending, SellerStatus::Rejected], SellerStatus::Approved, false],
        'reject' => [[SellerStatus::Pending], SellerStatus::Rejected, true],
        'suspend' => [[SellerStatus::Approved], SellerStatus::Suspended, true],
        'reinstate' => [[SellerStatus::Suspended], SellerStatus::Approved, false],
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(SellerStatus::class)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $sellers = SellerProfile::query()
            ->with(['user:id,name,email', 'city', 'area'])
            ->withCount('products')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';
                $q->where(fn ($q) => $q
                    ->whereRaw("LOWER(store_name) LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("LOWER(phone) LIKE ? ESCAPE '!'", [$like]));
            })
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest()
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return SellerProfileResource::collection($sellers);
    }

    public function show(SellerProfile $seller): SellerProfileResource
    {
        return new SellerProfileResource($seller->load(['user:id,name,email', 'city', 'area'])->loadCount('products'));
    }

    public function review(Request $request, SellerProfile $seller, string $action, AuditLogger $audit, Alerts $alerts): SellerProfileResource
    {
        abort_unless(isset(self::TRANSITIONS[$action]), 404);
        [$from, $to, $reasonRequired] = self::TRANSITIONS[$action];

        $data = $request->validate([
            'reason' => [$reasonRequired ? 'required' : 'nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($seller, $from, $to, $action, $data, $request, $audit, $alerts) {
            // Re-read under lock so two admins acting at once cannot apply conflicting transitions.
            $locked = SellerProfile::whereKey($seller->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $from, true)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot {$action} a seller whose status is {$locked->status->value}.",
                ]);
            }

            $previous = $locked->status;
            $locked->status = $to;
            $locked->status_reason = $data['reason'] ?? null;
            $locked->reviewed_at = now();
            $locked->reviewed_by = $request->user()->id;
            $locked->save();

            $audit->log("seller.{$action}", $locked, [
                'from' => $previous->value,
                'to' => $to->value,
                'reason' => $data['reason'] ?? null,
            ]);
            $alerts->sellerReviewed($locked, $action, $data['reason'] ?? null);

            $seller->setRawAttributes($locked->getAttributes(), true);
        });

        return new SellerProfileResource($seller->load(['user:id,name,email', 'city', 'area'])->loadCount('products'));
    }
}
