<?php

namespace App\Http\Controllers\Api\Seller\Erp;

use App\Http\Controllers\Api\Admin\ReportController;
use App\Http\Controllers\Api\Seller\Concerns\ResolvesStore;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\AuditLogger;
use App\Services\ExpenseService;
use App\Support\Erp\Period;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The store's expenses, with receipt photos or PDFs. Private to the store.
 */
class ExpenseController extends Controller
{
    use ResolvesStore;

    private const MONEY = 'regex:/^\d{1,9}(\.\d{1,2})?$/';

    /** Receipt files: photos or PDFs up to 5 MB, checked by their content. */
    private const FILE = ['file', 'max:5120', 'mimes:jpg,jpeg,png,webp,pdf'];

    public function __construct(private readonly ExpenseService $expenses) {}

    public function index(Request $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $period = Period::fromRequest($request);
        $filters = $this->filters($request);
        $categories = ExpenseCategory::forStore($store->id);

        $page = $this->query($store->id, $period, $filters)->with(['category:id,name', 'creator:id,name'])
            ->orderByDesc('spent_on')->orderByDesc('id')->paginate(25)->withQueryString();
        // Totals for the period count recorded expenses only, whatever the list shows.
        $recorded = Expense::where('seller_profile_id', $store->id)->where('status', 'recorded')
            ->whereDate('spent_on', '>=', $period->from->toDateString())->whereDate('spent_on', '<=', $period->to->toDateString());
        $byCategory = (clone $recorded)->selectRaw('expense_category_id, sum(amount_santim) as total, count(*) as n')->groupBy('expense_category_id')->get()->keyBy('expense_category_id');

        return response()->json([
            'data' => $page->getCollection()->map(fn ($e) => self::data($e))->values(),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
                'range' => $period->toArray(),
                'total_santim' => (int) $byCategory->sum('total'),
                'count' => (int) $byCategory->sum('n'),
                'without_receipt' => (clone $recorded)->whereNull('attachment_path')->count(),
                'by_category' => $categories->map(fn ($c) => [
                    'id' => $c->id, 'name' => $c->name, 'is_active' => $c->is_active,
                    'total_santim' => (int) ($byCategory[$c->id]->total ?? 0), 'count' => (int) ($byCategory[$c->id]->n ?? 0),
                ])->filter(fn ($c) => $c['count'] > 0)->sortByDesc('total_santim')->values(),
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $store = $this->currentStore($request);
        $expense = Expense::where('seller_profile_id', $store->id)->with(['category:id,name,is_active', 'creator:id,name'])->findOrFail($id);

        return response()->json(['data' => self::data($expense)]);
    }

    public function store(Request $request): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);
        $data = $request->validate([
            'expense_category_id' => ['required', 'integer'],
            'spent_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01'],
            'amount' => ['required', 'string', self::MONEY],
            'description' => ['required', 'string', 'min:2', 'max:255'],
            'paid_to' => ['nullable', 'string', 'max:120'],
            'payment_method' => ['required', Rule::in(Expense::METHODS)],
            'reference' => ['nullable', 'string', 'max:100'],
            'attachment' => ['nullable', ...self::FILE],
            'request_id' => ['nullable', 'string', 'max:64'],
        ], $this->messages());
        $expense = $this->expenses->record($store, $data, $request->file('attachment'), $request->user());

        return $this->show($request, $expense->id)->setStatusCode(201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);
        $data = $request->validate([
            'expense_category_id' => ['sometimes', 'integer'],
            'description' => ['sometimes', 'string', 'min:2', 'max:255'],
            'paid_to' => ['sometimes', 'nullable', 'string', 'max:120'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
        $this->expenses->updateDetails($store, $id, $data, $request->user());

        return $this->show($request, $id);
    }

    public function attach(Request $request, int $id): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);
        $request->validate(['attachment' => ['required', ...self::FILE]], $this->messages());
        $this->expenses->attach($store, $id, $request->file('attachment'), $request->user());

        return $this->show($request, $id);
    }

    public function void(Request $request, int $id): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $this->expenses->void($store, $id, $data['reason'], $request->user());

        return $this->show($request, $id);
    }

    /**
     * The receipt file, only to the store that owns it. Sent as its stored type with sniffing off
     * and a sandbox policy, so a file can never run as a page.
     */
    public function attachment(Request $request, int $id): Response
    {
        $store = $this->currentStore($request);
        $expense = Expense::where('seller_profile_id', $store->id)->findOrFail($id);
        $disk = Storage::disk(ExpenseService::DISK);
        abort_unless($expense->attachment_path && $disk->exists($expense->attachment_path), 404, 'No receipt is attached to this expense.');

        return $disk->response($expense->attachment_path, $expense->attachment_name, [
            'Content-Type' => $expense->attachment_mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Recorded expenses in the period, for a spreadsheet. Voided ones are left out. */
    public function export(Request $request, AuditLogger $audit): StreamedResponse
    {
        $store = $this->currentStore($request);
        $period = Period::fromRequest($request);
        $filters = ['status' => 'recorded'] + $this->filters($request);
        $audit->log('erp.expenses_exported', $store, $period->toArray());

        return response()->streamDownload(function () use ($store, $period, $filters) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['date', 'number', 'category', 'description', 'paid_to', 'payment_method', 'reference', 'amount_etb', 'receipt_attached'], escape: '');
            $this->query($store->id, $period, $filters)->with('category:id,name')->orderBy('spent_on')->orderBy('id')
                ->chunk(500, function ($rows) use ($out) {
                    foreach ($rows as $e) {
                        fputcsv($out, [$e->spent_on->toDateString(), $e->number, ReportController::cell($e->category?->name), ReportController::cell($e->description),
                            ReportController::cell($e->paid_to), $e->payment_method, ReportController::cell($e->reference), Money::toBirr($e->amount_santim), $e->attachment_path ? 'yes' : 'no'], escape: '');
                    }
                });
            fclose($out);
        }, 'expenses-'.$period->from->toDateString().'-to-'.$period->to->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function categories(Request $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $counts = Expense::where('seller_profile_id', $store->id)->selectRaw('expense_category_id, count(*) as n')->groupBy('expense_category_id')->pluck('n', 'expense_category_id');

        return response()->json(['data' => ExpenseCategory::forStore($store->id)->map(fn ($c) => self::category($c) + ['expenses' => (int) ($counts[$c->id] ?? 0)])->values()]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:60']]);
        $category = $this->expenses->addCategory($store, trim($data['name']), $request->user());

        return response()->json(['data' => self::category($category) + ['expenses' => 0]], 201);
    }

    public function updateCategory(Request $request, int $id): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:60'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }
        $category = $this->expenses->updateCategory($store, $id, $data, $request->user());

        return response()->json(['data' => self::category($category) + ['expenses' => Expense::where('expense_category_id', $category->id)->count()]]);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'category_id' => ['nullable', 'integer'],
            'method' => ['nullable', Rule::in(Expense::METHODS)],
            'status' => ['nullable', Rule::in(['recorded', 'voided'])],
            'receipt' => ['nullable', Rule::in(['with', 'without'])],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    private function query(int $storeId, Period $period, array $f): Builder
    {
        $term = trim((string) ($f['q'] ?? ''));
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';

        return Expense::where('seller_profile_id', $storeId)
            ->whereDate('spent_on', '>=', $period->from->toDateString())->whereDate('spent_on', '<=', $period->to->toDateString())
            ->when($f['category_id'] ?? null, fn ($q, $v) => $q->where('expense_category_id', $v))
            ->when($f['method'] ?? null, fn ($q, $v) => $q->where('payment_method', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when(($f['receipt'] ?? null) === 'with', fn ($q) => $q->whereNotNull('attachment_path'))
            ->when(($f['receipt'] ?? null) === 'without', fn ($q) => $q->whereNull('attachment_path'))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->whereRaw("LOWER(description) LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("LOWER(paid_to) LIKE ? ESCAPE '!'", [$like])->orWhereRaw("LOWER(number) LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("LOWER(reference) LIKE ? ESCAPE '!'", [$like])));
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'amount.regex' => 'Enter the amount in birr, for example 2500 or 2500.50.',
            'attachment.max' => 'The receipt file can be up to 5 MB.',
            'attachment.mimes' => 'Attach a photo (JPG, PNG or WebP) or a PDF.',
            'attachment.uploaded' => 'The receipt file could not be uploaded. Try a smaller file.',
            'spent_on.after_or_equal' => 'Use a date from 2000 on.',
        ];
    }

    /** @return array<string, mixed> */
    private static function category(ExpenseCategory $c): array
    {
        return ['id' => $c->id, 'name' => $c->name, 'is_active' => $c->is_active];
    }

    /** @return array<string, mixed> */
    public static function data(Expense $e): array
    {
        return [
            'id' => $e->id,
            'number' => $e->number,
            'spent_on' => $e->spent_on->toDateString(),
            'category' => $e->category ? ['id' => $e->category->id, 'name' => $e->category->name] : null,
            'amount_santim' => $e->amount_santim,
            'description' => $e->description,
            'paid_to' => $e->paid_to,
            'payment_method' => $e->payment_method,
            'reference' => $e->reference,
            'attachment' => $e->attachment_path ? ['name' => $e->attachment_name, 'mime' => $e->attachment_mime, 'size' => $e->attachment_size] : null,
            'status' => $e->status,
            'void_reason' => $e->void_reason,
            'voided_at' => $e->voided_at?->toIso8601String(),
            'by' => $e->relationLoaded('creator') ? $e->creator?->name : null,
            'created_at' => $e->created_at?->toIso8601String(),
        ];
    }
}
