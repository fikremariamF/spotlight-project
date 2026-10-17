<?php

namespace App\Http\Controllers\Api\Seller\Erp;

use App\Http\Controllers\Api\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Api\Seller\Concerns\ResolvesStore;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Support\Erp\Period;
use App\Support\Erp\Reports;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports & analytics: one table per report (see Reports), sorted and paged here, and the whole
 * table as CSV in the same order.
 */
class ReportController extends Controller
{
    use ResolvesStore;

    public const PRINT_ROWS = 2000;

    public function index(Request $request): JsonResponse
    {
        $this->currentStore($request);

        return response()->json(['data' => collect(Reports::definitions())->map(fn ($d, $key) => [
            'key' => $key, 'title' => $d['title'], 'about' => $d['about'], 'period' => $d['period'], 'group' => $d['group'] ?? false,
        ])->values()]);
    }

    public function show(Request $request, string $report): JsonResponse
    {
        $store = $this->currentStore($request);
        [$def, $period, $group, $rows] = $this->table($request, $store->id, $report);
        $paging = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:10', 'max:100'], 'all' => ['nullable', 'boolean']]);
        // For printing: every row on one page, up to a limit (the CSV always has them all).
        $perPage = $request->boolean('all') ? self::PRINT_ROWS : (int) ($paging['per_page'] ?? 50);
        $last = max(1, (int) ceil(count($rows) / $perPage));
        $page = min($last, (int) ($paging['page'] ?? 1));

        $totals = [];
        foreach ($def['columns'] as $c) {
            if ($c['sum'] ?? false) {
                $totals[$c['key']] = array_sum(array_map(fn ($r) => (int) ($r[$c['key']] ?? 0), $rows));
            }
        }

        return response()->json([
            'data' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'report' => ['key' => $report, 'title' => $def['title'], 'about' => $def['about'], 'columns' => $def['columns'],
                'period' => $def['period'], 'group' => ($def['group'] ?? false) ? $group : null],
            'totals' => $totals,
            'sort' => ['key' => $def['sort'], 'dir' => $def['dir']],
            'range' => $def['period'] ? $period->toArray() : null,
            'meta' => ['current_page' => $page, 'last_page' => $last, 'per_page' => $perPage, 'total' => count($rows)],
        ]);
    }

    public function export(Request $request, AuditLogger $audit, string $report): StreamedResponse
    {
        $store = $this->currentStore($request);
        [$def, $period, $group, $rows] = $this->table($request, $store->id, $report);
        $audit->log('erp.report_exported', $store, ['report' => $report] + ($def['period'] ? $period->toArray() : []));
        $name = str_replace('_', '-', $report).($def['period'] ? '-'.$period->from->toDateString().'-to-'.$period->to->toDateString() : '-'.now(config('marketplace.timezone'))->toDateString());

        return response()->streamDownload(function () use ($def, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_map(fn ($c) => str_replace('_santim', '_etb', $c['key']), $def['columns']), escape: '');
            foreach ($rows as $row) {
                fputcsv($out, array_map(function ($c) use ($row) {
                    $v = $row[$c['key']] ?? null;

                    return match ($c['type']) {
                        'money' => $v === null ? '' : Money::toBirr((int) $v),
                        'int', 'percent' => $v ?? '',
                        // Names and notes people typed must not run as spreadsheet formulas.
                        default => AdminReportController::cell($v),
                    };
                }, $def['columns']), escape: '');
            }
            fclose($out);
        }, $name.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * The report's rows for the request, sorted (empty values last either way).
     *
     * @return array{array<string, mixed>, Period, string, list<array<string, mixed>>}
     */
    private function table(Request $request, int $storeId, string $report): array
    {
        $def = Reports::definitions()[$report] ?? abort(404);
        $keys = array_column($def['columns'], 'key');
        $data = $request->validate(['sort' => ['nullable', Rule::in($keys)], 'dir' => ['nullable', Rule::in(['asc', 'desc'])]]);
        $period = Period::fromRequest($request);
        $group = FinanceController::group($request, $period);
        $def['sort'] = $data['sort'] ?? $def['sort'];
        $def['dir'] = isset($data['sort']) ? ($data['dir'] ?? 'asc') : ($data['dir'] ?? $def['dir']);

        $rows = Reports::rows($report, $storeId, $period, $group);
        $key = $def['sort'];
        $desc = $def['dir'] === 'desc';
        $type = collect($def['columns'])->firstWhere('key', $key)['type'];
        usort($rows, function ($a, $b) use ($key, $desc, $type) {
            $x = $a[$key] ?? null;
            $y = $b[$key] ?? null;
            if ($x === null || $x === '' || $y === null || $y === '') {
                return ($x === null || $x === '') <=> ($y === null || $y === '');
            }
            $cmp = in_array($type, ['money', 'int', 'percent'], true) ? $x <=> $y : strnatcasecmp((string) $x, (string) $y);

            return $desc ? -$cmp : $cmp;
        });

        return [$def, $period, $group, $rows];
    }
}
