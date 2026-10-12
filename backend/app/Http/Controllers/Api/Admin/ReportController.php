<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\SellerLedgerEntry;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sales report for a date range in Addis Ababa time. Gross sales, commission and cash collected
 * are reported separately; none of them is profit.
 */
class ReportController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        [$from, $to, $start, $end] = $this->range($request);

        $delivered = Order::where('status', OrderStatus::Delivered->value)->whereBetween('delivered_at', [$start, $end]);
        // Online orders that were never paid are not sales; they are counted separately.
        $placed = Order::visibleToSeller()->whereBetween('created_at', [$start, $end]);
        $tz = config('marketplace.timezone');

        // Daily series in PHP: day boundaries follow Addis Ababa time on every database engine.
        $days = [];
        for ($d = $from; $d <= $to; $d = $d->addDay()) {
            $days[$d->toDateString()] = ['date' => $d->toDateString(), 'orders' => 0, 'gross_santim' => 0, 'commission_santim' => 0];
        }
        foreach ((clone $delivered)->select(['delivered_at', 'total_santim', 'commission_santim'])->cursor() as $o) {
            $key = $o->delivered_at->setTimezone($tz)->toDateString();
            $days[$key]['orders']++;
            $days[$key]['gross_santim'] += $o->total_santim;
            $days[$key]['commission_santim'] += $o->commission_santim;
        }

        $topSellers = (clone $delivered)
            ->selectRaw('seller_profile_id, count(*) as orders, sum(total_santim) as gross, sum(commission_santim) as commission')
            ->groupBy('seller_profile_id')->orderByDesc('gross')->limit(10)
            ->with('seller:id,store_name,slug')->get()
            ->map(fn ($r) => ['store_name' => $r->seller?->store_name, 'slug' => $r->seller?->slug, 'orders' => (int) $r->orders, 'gross_santim' => (int) $r->gross, 'commission_santim' => (int) $r->commission]);

        $cancelled = Order::visibleToSeller()->where('status', OrderStatus::Cancelled->value)->whereBetween('cancelled_at', [$start, $end])
            ->selectRaw('cancelled_by, count(*) as c')->groupBy('cancelled_by')->pluck('c', 'cancelled_by');
        $unpaid = Order::where('payment_method', 'online')->whereNull('paid_at')->where('status', OrderStatus::Cancelled->value)
            ->whereBetween('cancelled_at', [$start, $end])->count();

        return response()->json(['data' => [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'timezone' => $tz,
            'placed' => ['orders' => (clone $placed)->count(), 'value_santim' => (int) (clone $placed)->sum('total_santim')],
            'delivered' => [
                'orders' => (clone $delivered)->count(),
                'gross_santim' => (int) (clone $delivered)->sum('total_santim'),
                'items_santim' => (int) (clone $delivered)->sum('items_total_santim'),
                'delivery_fees_santim' => (int) (clone $delivered)->sum('delivery_fee_santim'),
                'commission_santim' => (int) (clone $delivered)->sum('commission_santim'),
            ],
            'cancelled' => [
                'orders' => (int) $cancelled->sum(),
                'by_customer' => (int) ($cancelled['customer'] ?? 0),
                'by_seller' => (int) ($cancelled['seller'] ?? 0),
                'by_admin' => (int) ($cancelled['admin'] ?? 0),
            ],
            'online' => [
                'paid_santim' => (int) Payment::where('status', 'paid')->whereBetween('paid_at', [$start, $end])->sum('amount_santim'),
                'unpaid_cancelled_orders' => $unpaid,
                'refunds_due_santim' => (int) Refund::where('status', 'due')->sum('amount_santim'),
                'refunded_santim' => (int) Refund::where('status', 'refunded')->whereBetween('refunded_at', [$start, $end])->sum('amount_santim'),
                'paid_out_santim' => (int) SellerLedgerEntry::where('type', 'payout')->whereBetween('created_at', [$start, $end])->sum('amount_santim'),
            ],
            'commission_collected_santim' => -(int) SellerLedgerEntry::where('type', 'payment')->whereBetween('created_at', [$start, $end])->sum('amount_santim'),
            'top_sellers' => $topSellers,
            'days' => array_values($days),
        ]]);
    }

    /**
     * Orders placed in the range as CSV, for spreadsheets and accounting.
     */
    public function export(Request $request, AuditLogger $audit): StreamedResponse
    {
        [$from, $to, $start, $end] = $this->range($request);
        $tz = config('marketplace.timezone');
        $audit->log('report.exported', null, ['from' => $from->toDateString(), 'to' => $to->toDateString()]);

        return response()->streamDownload(function () use ($start, $end, $tz) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['order', 'placed_at', 'status', 'store', 'customer', 'area', 'fulfillment', 'items_etb', 'delivery_etb', 'total_etb', 'commission_rate_percent', 'commission_etb', 'payment_method', 'payment_status', 'delivered_at', 'cancelled_at', 'cancelled_by'], escape: '');
            Order::whereBetween('created_at', [$start, $end])
                ->with(['seller:id,store_name', 'customer:id,name', 'checkout:id,area_name'])
                ->orderBy('id')
                ->chunkById(500, function ($orders) use ($out, $tz) {
                    foreach ($orders as $o) {
                        fputcsv($out, array_map(self::cell(...), [
                            $o->number,
                            $o->created_at?->setTimezone($tz)->format('Y-m-d H:i'),
                            $o->status->value,
                            $o->seller?->store_name,
                            $o->customer?->name,
                            $o->checkout?->area_name,
                            $o->fulfillment,
                            self::birr($o->items_total_santim),
                            $o->delivery_fee_santim === null ? '' : self::birr($o->delivery_fee_santim),
                            self::birr($o->total_santim),
                            number_format($o->commission_rate_bp / 100, 2, '.', ''),
                            self::birr($o->commission_santim),
                            $o->payment_method,
                            $o->payment_status,
                            $o->delivered_at?->setTimezone($tz)->format('Y-m-d H:i'),
                            $o->cancelled_at?->setTimezone($tz)->format('Y-m-d H:i'),
                            $o->cancelled_by,
                        ]), escape: '');
                    }
                });
            fclose($out);
        }, 'orders-'.$from->toDateString().'-to-'.$to->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Spreadsheet apps run cells starting with = + - @ as formulas; prefix them so names and notes stay text.
     */
    public static function cell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    private static function birr(int $santim): string
    {
        return number_format($santim / 100, 2, '.', '');
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable, CarbonImmutable, CarbonImmutable} local from/to dates and their UTC bounds
     */
    private function range(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $tz = config('marketplace.timezone');
        $to = isset($data['to']) ? CarbonImmutable::createFromFormat('Y-m-d', $data['to'], $tz)->startOfDay() : CarbonImmutable::now($tz)->startOfDay();
        $from = isset($data['from']) ? CarbonImmutable::createFromFormat('Y-m-d', $data['from'], $tz)->startOfDay() : $to->subDays(29);
        if ($from->greaterThan($to)) {
            abort(response()->json(['message' => 'The start date must be on or before the end date.', 'errors' => ['from' => ['The start date must be on or before the end date.']]], 422));
        }
        if ($from->diffInDays($to) > 366) {
            abort(response()->json(['message' => 'Pick a range of one year or less.', 'errors' => ['from' => ['Pick a range of one year or less.']]], 422));
        }

        return [$from, $to, $from->utc(), $to->endOfDay()->utc()];
    }
}
