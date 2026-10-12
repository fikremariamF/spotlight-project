<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Seller\BalanceController;
use App\Http\Controllers\Controller;
use App\Http\Resources\LedgerEntryResource;
use App\Models\SellerLedgerEntry;
use App\Models\SellerProfile;
use App\Models\Setting;
use App\Services\LedgerService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Balances with sellers: commission they owe on cash orders, and online sales the platform owes
 * them. Admins record payments from sellers and payouts to them.
 */
class SettlementController extends Controller
{
    private const BALANCE_SQL = '(select coalesce(sum(l.amount_santim), 0) from seller_ledger_entries l where l.seller_profile_id = seller_profiles.id)';

    public function __construct(private readonly LedgerService $ledger) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'owing' => ['nullable', 'boolean'],
            'owed' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $sellers = SellerProfile::query()
            ->select(['id', 'store_name', 'slug', 'status', 'phone'])
            ->selectRaw(self::BALANCE_SQL.' as balance_santim')
            ->when(filter_var($filters['owing'] ?? false, FILTER_VALIDATE_BOOL), fn ($q) => $q->whereRaw(self::BALANCE_SQL.' > 0'))
            ->when(filter_var($filters['owed'] ?? false, FILTER_VALIDATE_BOOL), fn ($q) => $q->whereRaw(self::BALANCE_SQL.' < 0'))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';
                $q->whereRaw("LOWER(store_name) LIKE ? ESCAPE '!'", [$like]);
            })
            ->orderByDesc('balance_santim')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        $limit = Setting::creditLimitSantim();
        $balances = DB::query()->fromSub(SellerLedgerEntry::query()->selectRaw('seller_profile_id, sum(amount_santim) as b')->groupBy('seller_profile_id'), 'x');
        $outstanding = (int) (clone $balances)->where('b', '>', 0)->sum('b');
        $payable = -(int) (clone $balances)->where('b', '<', 0)->sum('b');

        return response()->json([
            'data' => $sellers->getCollection()->map(fn (SellerProfile $s) => [
                'id' => $s->id,
                'store_name' => $s->store_name,
                'slug' => $s->slug,
                'status' => $s->status->value,
                'phone' => $s->phone,
                'balance_santim' => (int) $s->balance_santim,
                'over_limit' => $limit > 0 && (int) $s->balance_santim > $limit,
            ]),
            'meta' => [
                'current_page' => $sellers->currentPage(),
                'last_page' => $sellers->lastPage(),
                'total' => $sellers->total(),
                'outstanding_santim' => $outstanding,
                'collected_santim' => -(int) SellerLedgerEntry::where('type', 'payment')->sum('amount_santim'),
                'payable_santim' => $payable,
                'paid_out_santim' => (int) SellerLedgerEntry::where('type', 'payout')->sum('amount_santim'),
                'credit_limit_santim' => $limit ?: null,
            ],
        ]);
    }

    public function show(SellerProfile $seller): JsonResponse
    {
        $entries = SellerLedgerEntry::where('seller_profile_id', $seller->id)
            ->with(['order:id,number', 'creator:id,name'])->orderByDesc('id')->paginate(25);

        return response()->json([
            'data' => [
                'seller' => ['id' => $seller->id, 'store_name' => $seller->store_name, 'slug' => $seller->slug, 'status' => $seller->status->value, 'phone' => $seller->phone],
                ...BalanceController::summary($seller->id),
            ],
            'entries' => LedgerEntryResource::collection($entries)->response()->getData(true),
        ]);
    }

    public function payment(Request $request, SellerProfile $seller): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'string', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'method' => ['required', Rule::in(SellerLedgerEntry::METHODS)],
            'reference' => ['nullable', 'string', 'max:100', Rule::requiredIf(fn () => in_array($request->input('method'), ['bank_transfer', 'telebirr'], true))],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['reference.required' => 'Enter the transfer or transaction reference so it cannot be recorded twice.']);

        $amount = Money::toSantim($data['amount']);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount above zero.']);
        }
        $reference = isset($data['reference']) ? (mb_strtoupper(trim($data['reference'])) ?: null) : null;

        $this->ledger->recordPayment($seller->id, $amount, $data['method'], $reference, isset($data['note']) ? trim($data['note']) ?: null : null, $request->user());

        return $this->show($seller)->setStatusCode(201);
    }

    public function payout(Request $request, SellerProfile $seller): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'string', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'method' => ['required', Rule::in(SellerLedgerEntry::METHODS)],
            'reference' => ['nullable', 'string', 'max:100', Rule::requiredIf(fn () => in_array($request->input('method'), ['bank_transfer', 'telebirr'], true))],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['reference.required' => 'Enter the transfer or transaction reference so it cannot be recorded twice.']);

        $amount = Money::toSantim($data['amount']);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount above zero.']);
        }
        $reference = isset($data['reference']) ? (mb_strtoupper(trim($data['reference'])) ?: null) : null;

        $this->ledger->recordPayout($seller->id, $amount, $data['method'], $reference, isset($data['note']) ? trim($data['note']) ?: null : null, $request->user());

        return $this->show($seller)->setStatusCode(201);
    }

    public function adjust(Request $request, SellerProfile $seller): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'string', 'regex:/^-?\d{1,10}(\.\d{1,2})?$/'],
            'note' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $negative = str_starts_with($data['amount'], '-');
        $amount = Money::toSantim(ltrim($data['amount'], '-')) * ($negative ? -1 : 1);
        if ($amount === 0) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount other than zero.']);
        }

        $this->ledger->adjust($seller->id, $amount, trim($data['note']), $request->user());

        return $this->show($seller)->setStatusCode(201);
    }
}
