<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Seller\BalanceController;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Product;
use App\Models\SellerLedgerEntry;
use App\Services\LedgerService;
use App\Support\Erp\Finance;
use App\Support\Erp\Reports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

class ErpFinanceTest extends TestCase
{
    use PlacesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->setUpOrders();
        $this->sofa->forceFill(['cost_price_santim' => 600_000])->save();
    }

    private function today(int $minusDays = 0): string
    {
        return now(config('marketplace.timezone'))->subDays($minusDays)->toDateString();
    }

    private function finance(string $query = 'period=30d'): array
    {
        return $this->as($this->seller->user)->getJson('/api/seller/erp/finance?'.$query)->assertOk()->json('data');
    }

    private function report(string $key, string $query = 'period=30d'): TestResponse
    {
        return $this->as($this->seller->user)->getJson("/api/seller/erp/reports/{$key}?{$query}")->assertOk();
    }

    private function csv(string $url): string
    {
        $res = $this->as($this->seller->user)->get($url)->assertOk();

        return $res->streamedContent();
    }

    private function categoryId(string $name): int
    {
        return $this->as($this->seller->user)->getJson('/api/seller/erp/expense-categories')->assertOk()->collect('data')->firstWhere('name', $name)['id'];
    }

    /**
     * One of everything, with every amount worked out by hand:
     * - a cash marketplace order of 2 sofas, delivered (cost 600,000 each);
     * - an online order of 1 sofa, paid and delivered; another paid but still open;
     * - a cash order cancelled by the store;
     * - a shop sale of 3 sofas (1,000 off the line) and an assembly line with no cost, 190 off the
     *   whole sale, 300 delivery (29,610 in all), 24,610 paid then 5,000 more; 1 sofa brought back
     *   into stock with 2,000 given back;
     * - two expenses and a voided one; a supplier bill of 5,000 with 2,000 paid;
     * - a payout, an adjustment and a payment by the store recorded by the marketplace.
     *
     * @return array<string, mixed>
     */
    private function scenario(): array
    {
        $admin = $this->admin();
        $ledger = app(LedgerService::class);
        $cod = $this->deliver($this->placeOrder(2));

        $online = $this->placeOrder(1);
        $online->forceFill(['payment_method' => 'online', 'payment_status' => 'paid', 'paid_at' => now(), 'paid_santim' => $online->total_santim])->save();
        $online = $this->deliver($online);
        $waiting = $this->placeOrder(1);
        $waiting->forceFill(['payment_method' => 'online', 'payment_status' => 'paid', 'paid_at' => now(), 'paid_santim' => $waiting->total_santim])->save();
        $cancelled = $this->placeOrder(1);
        $this->as($this->seller->user)->postJson("/api/seller/orders/{$cancelled->id}/cancel", ['reason' => 'Out of stock'])->assertOk();

        $sale = $this->as($this->seller->user)->postJson('/api/seller/erp/sales', [
            'customer_name' => 'Almaz', 'customer_phone' => '0911 222 333', 'discount' => '190', 'delivery' => '300',
            'paid_now' => '24610', 'payment_method' => 'cash',
            'items' => [
                ['product_id' => $this->sofa->id, 'quantity' => 3, 'unit_price' => '10000', 'discount' => '1000'],
                ['description' => 'Assembly', 'quantity' => 1, 'unit_price' => '500'],
            ],
        ])->assertCreated()->json('data');
        $this->postJson("/api/seller/erp/sales/offline/{$sale['id']}/payments", ['amount' => '5000', 'method' => 'telebirr'])->assertCreated();
        $return = $this->postJson("/api/seller/erp/sales/offline/{$sale['id']}/returns", [
            'reason' => 'Wrong colour', 'refund' => '2000', 'refund_method' => 'cash',
            'items' => [['item_id' => $sale['items'][0]['id'], 'quantity' => 1, 'restock' => true]],
        ])->assertCreated()->json('data');

        $rent = $this->post('/api/seller/erp/expenses', ['expense_category_id' => $this->categoryId('Warehouse rent'), 'amount' => '1500', 'description' => 'Rent',
            'payment_method' => 'cash'], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->post('/api/seller/erp/expenses', ['expense_category_id' => $this->categoryId('Furniture transportation and delivery'), 'amount' => '500', 'description' => 'Fuel',
            'payment_method' => 'cash'], ['Accept' => 'application/json'])->assertCreated();
        $wrong = $this->post('/api/seller/erp/expenses', ['expense_category_id' => $this->categoryId('Furniture transportation and delivery'), 'amount' => '999', 'description' => 'Typed twice',
            'payment_method' => 'cash'], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->postJson("/api/seller/erp/expenses/{$wrong}/void", ['reason' => 'Entered twice'])->assertOk();

        $supplier = $this->postJson('/api/seller/erp/suppliers', ['name' => 'Kality Wood'])->assertCreated()->json('data.id');
        $this->postJson("/api/seller/erp/suppliers/{$supplier}/invoices", ['invoice_number' => 'KW-1', 'amount' => '5000'])->assertCreated();
        $this->postJson("/api/seller/erp/suppliers/{$supplier}/payments", ['amount' => '2000', 'method' => 'bank_transfer'])->assertCreated();

        // The marketplace owes the store for the online sale (less commission): it pays that out, then
        // charges a 100 penalty, which the store pays.
        $payout = -LedgerService::balance($this->seller->id);
        $ledger->recordPayout($this->seller->id, $payout, 'bank_transfer', 'TR-1', null, $admin);
        $ledger->adjust($this->seller->id, 10_000, 'Late delivery penalty', $admin);
        $ledger->recordPayment($this->seller->id, 10_000, 'cash', null, null, $admin);

        return compact('cod', 'online', 'waiting', 'cancelled', 'sale', 'return', 'rent', 'payout');
    }

    public function test_the_statement_adds_up_from_the_records_without_counting_anything_twice(): void
    {
        ['cod' => $cod, 'online' => $online, 'waiting' => $waiting, 'sale' => $sale, 'return' => $return, 'payout' => $payout] = $this->scenario();
        $f = $this->finance();
        $t = $f['total'];

        $credit = $return['returned_santim'];
        $mpItems = $cod->items_total_santim + $online->items_total_santim;
        $mpDelivery = $cod->delivery_fee_santim + $online->delivery_fee_santim;
        $this->assertSame(3_000_000, $mpItems);
        $this->assertSame([2, 1], [$t['marketplace_orders'], $t['offline_sales']]);
        $this->assertSame($mpItems + 3_050_000, $t['gross_sales_santim']);
        $this->assertSame(100_000 + 19_000, $t['discounts_santim']);
        $this->assertSame($credit, $t['returns_goods_santim']);
        $this->assertSame($mpItems + 3_050_000 - 119_000 - $credit, $t['net_items_santim']);
        $this->assertSame($mpDelivery + 30_000, $t['delivery_santim']);
        $this->assertSame($t['net_items_santim'] + $t['delivery_santim'], $t['net_sales_santim']);

        // Commission once: what the delivered orders were charged, the same as the ledger holds.
        $this->assertSame($cod->commission_santim + $online->commission_santim, $t['commission_santim']);
        $this->assertSame((int) SellerLedgerEntry::where('type', 'commission')->sum('amount_santim'), $t['commission_santim']);

        // Cost: 2 + 1 + 3 sofas sold, 1 back in stock. The assembly line has no cost price.
        $this->assertSame(600_000 * 5, $t['cost_of_goods_santim']);
        $this->assertSame([$sale['items'][1]['net_santim'], 1], [$t['sales_without_cost_santim'], $t['lines_without_cost']]);
        $this->assertSame(49_678, $sale['items'][1]['net_santim']);
        $costedSales = $mpItems + $sale['items'][0]['net_santim'];
        $this->assertSame($costedSales - $credit - 600_000 * 5, $t['gross_profit_santim']);
        // The statement adds up line by line: net item sales, less those without a cost, less their cost.
        $this->assertSame($t['gross_profit_santim'], $t['net_items_santim'] - $t['net_items_without_cost_santim'] - $t['cost_of_goods_santim']);
        $this->assertSame($sale['items'][1]['net_santim'], $t['net_items_without_cost_santim']);
        // The same gross profit as the sales report and the overview.
        $this->assertSame($t['gross_profit_santim'], $this->getJson('/api/seller/erp/sales/report?period=30d')->json('data.totals.gross_profit_santim'));
        $this->assertSame($t['gross_profit_santim'], $this->getJson('/api/seller/erp/overview?period=30d')->json('data.sales.finance.gross_profit_santim'));

        // Net: gross profit plus delivery, less commission, the adjustment and the 2 expenses (not the voided one).
        $this->assertSame([10_000, 200_000], [$t['adjustments_santim'], $t['expenses_santim']]);
        $this->assertSame($t['gross_profit_santim'] + $t['delivery_santim'] - $t['commission_santim'] - 10_000 - 200_000, $t['net_profit_santim']);
        $this->assertSame($t['net_profit_santim'], $this->getJson('/api/seller/erp/overview?period=30d')->json('data.sales.finance.net_profit_santim'));

        // Money recorded in and out: cash collected on the cash order, the shop sale's 2 payments and
        // the payout. The online sales are not money in until they are paid out.
        $this->assertSame($online->paid_santim - $online->commission_santim - $cod->commission_santim, $payout);
        $this->assertSame([$cod->total_santim, 2_961_000, $payout], [$t['in_cash_on_delivery_santim'], $t['in_shop_payments_santim'], $t['in_payouts_santim']]);
        $this->assertSame([200_000, 200_000, 200_000, 10_000], [$t['out_refunds_santim'], $t['out_suppliers_santim'], $t['out_expenses_santim'], $t['out_marketplace_santim']]);
        $this->assertSame($cod->total_santim + 2_961_000 + $payout - 610_000, $t['money_net_santim']);

        // The marketplace account: the balance now, what moved in the period, and paid online orders
        // not delivered yet (not owed to the store until they are).
        $m = $f['marketplace'];
        $this->assertSame(BalanceController::summary($this->seller->id), $m['now']);
        $this->assertSame(LedgerService::balance($this->seller->id), $m['now']['balance_santim']);
        $this->assertSame([$t['commission_santim'], $online->paid_santim, $payout, 10_000, 10_000], array_values($m['period']));
        $this->assertSame(0, $m['now']['balance_santim']);
        $this->assertSame([$waiting->total_santim, 1], [$m['online_waiting_santim'], $m['online_waiting_orders']]);

        // Owed now: the shop customer paid in full and got 2,000 of the returned sofa back, so the
        // store holds the rest for them; the supplier billed 5,000 and was paid 2,000.
        $this->assertSame(2_961_000, $sale['total_santim']);
        $this->assertSame(['owe_santim' => 0, 'owing_sales' => 0, 'overdue_sales' => 0, 'credit_santim' => $credit - 200_000], $f['owed']['customers']);
        $this->assertSame(['owed_santim' => 300_000, 'paid_ahead_santim' => 0, 'received_not_invoiced_santim' => 0], $f['owed']['suppliers']);
        $this->assertSame([['id' => $this->categoryId('Warehouse rent'), 'name' => 'Warehouse rent', 'total_santim' => 150_000, 'count' => 1],
            ['id' => $this->categoryId('Furniture transportation and delivery'), 'name' => 'Furniture transportation and delivery', 'total_santim' => 50_000, 'count' => 1]], $f['expenses_by_category']);
    }

    public function test_buckets_add_up_to_the_total_and_dates_filter_by_the_day_each_thing_happened(): void
    {
        ['rent' => $rent] = $this->scenario();
        $this->as($this->seller->user);
        // An expense from 40 days ago and a sale from 10 days ago.
        $old = $this->post('/api/seller/erp/expenses', ['expense_category_id' => $this->categoryId('Packaging'), 'amount' => '700', 'description' => 'Old bill',
            'payment_method' => 'cash', 'spent_on' => $this->today(40)], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->postJson('/api/seller/erp/sales', ['sale_date' => $this->today(10), 'paid_now' => '400', 'payment_method' => 'cash',
            'items' => [['description' => 'Cushion', 'quantity' => 2, 'unit_price' => '200']]])->assertCreated();

        foreach (['day', 'week', 'month'] as $group) {
            $f = $this->finance("period=30d&group={$group}");
            $this->assertSame($group, $f['group']);
            foreach (['gross_sales_santim', 'net_sales_santim', 'gross_profit_santim', 'net_profit_santim', 'expenses_santim', 'money_net_santim', 'commission_santim'] as $field) {
                $this->assertSame($f['total'][$field], array_sum(array_column($f['buckets'], $field)), "{$group} {$field}");
            }
            // Buckets cover the range exactly, in order, without gaps.
            $this->assertSame($this->today(29), $f['buckets'][0]['from']);
            $this->assertSame($this->today(), end($f['buckets'])['to']);
        }
        $this->assertCount(30, $this->finance('period=30d&group=day')['buckets']);
        $days = collect($this->finance('period=30d&group=day')['buckets'])->keyBy('key');
        $this->assertSame(40_000, $days[$this->today(10)]['offline_gross_santim']);
        $this->assertSame(200_000, $days[$this->today()]['expenses_santim']);

        // The old expense is outside the last 30 days, inside a range that reaches it.
        $this->assertSame(200_000, $this->finance()['total']['expenses_santim']);
        $wide = $this->finance('period=custom&from='.$this->today(45).'&to='.$this->today());
        $this->assertSame(270_000, $wide['total']['expenses_santim']);
        $this->assertSame('week', $wide['group']);
        $this->assertSame(70_000, $this->finance('period=custom&from='.$this->today(40).'&to='.$this->today(40))['total']['expenses_santim']);
        $this->assertSame(0, $this->finance('period=custom&from='.$this->today(39).'&to='.$this->today(11))['total']['expenses_santim']);

        // A voided expense stops counting everywhere.
        $this->postJson("/api/seller/erp/expenses/{$old}/void", ['reason' => 'Paid by the landlord'])->assertOk();
        $this->assertSame(200_000, $this->finance('period=custom&from='.$this->today(45).'&to='.$this->today())['total']['expenses_santim']);
        $this->assertSame(200_000, Expense::where('id', $rent)->value('amount_santim') + 50_000);

        // Ranges are checked.
        $this->getJson('/api/seller/erp/finance?period=custom&from='.$this->today().'&to='.$this->today(3))->assertUnprocessable();
        $this->getJson('/api/seller/erp/finance?period=custom&from='.$this->today(400).'&to='.$this->today())->assertUnprocessable();
        $this->getJson('/api/seller/erp/finance?group=hour')->assertUnprocessable();
        $this->getJson('/api/seller/erp/finance')->assertOk()->assertJsonPath('data.range.period', 'month')
            ->assertJsonPath('data.range.from', now(config('marketplace.timezone'))->startOfMonth()->toDateString());
        $lastMonth = now(config('marketplace.timezone'))->subMonthNoOverflow();
        $this->getJson('/api/seller/erp/finance?period=last_month')->assertOk()
            ->assertJsonPath('data.range.from', $lastMonth->copy()->startOfMonth()->toDateString())
            ->assertJsonPath('data.range.to', $lastMonth->copy()->endOfMonth()->toDateString());
    }

    public function test_weeks_start_on_monday_and_are_cut_to_the_range(): void
    {
        $this->assertSame('2026-10-12', Finance::bucket('2026-10-18', 'week'));
        $this->assertSame('2026-10-19', Finance::bucket('2026-10-19', 'week'));
        $this->assertSame('2026-10', Finance::bucket('2026-10-31', 'month'));

        // Wednesday 2026-10-14 to Tuesday 2026-10-20: two weeks, the first starting on the Wednesday.
        $f = $this->finance('period=custom&from=2026-10-14&to=2026-10-20&group=week');
        $this->assertSame([['2026-10-12', '2026-10-14', '2026-10-18'], ['2026-10-19', '2026-10-19', '2026-10-20']],
            array_map(fn ($b) => [$b['key'], $b['from'], $b['to']], $f['buckets']));
        $this->assertSame([['2026-01-31 to 2026-02-01'], ['2026-02-02 to 2026-02-03']],
            array_map(fn ($r) => [$r['period']], $this->report('sales', 'period=custom&from=2026-01-31&to=2026-02-03&group=week')->json('data')));
    }

    public function test_a_store_sees_only_its_own_figures_and_reports(): void
    {
        $this->scenario();
        $other = $this->approvedSeller();
        $this->as($other->user);
        $f = $this->getJson('/api/seller/erp/finance?period=30d')->assertOk()->json('data.total');
        foreach (['gross_sales_santim', 'commission_santim', 'expenses_santim', 'money_in_santim', 'money_out_santim', 'net_profit_santim'] as $field) {
            $this->assertSame(0, $f[$field], $field);
        }
        $this->getJson('/api/seller/erp/finance?period=30d')->assertJsonPath('data.owed.suppliers.owed_santim', 0)
            ->assertJsonPath('data.owed.customers.owe_santim', 0)->assertJsonPath('data.marketplace.now.balance_santim', 0);
        foreach (array_keys(Reports::definitions()) as $key) {
            $rows = $this->getJson("/api/seller/erp/reports/{$key}?period=30d")->assertOk()->json('data');
            $money = array_filter($rows, fn ($r) => array_filter($r, fn ($v, $k) => str_ends_with($k, '_santim') && $v, ARRAY_FILTER_USE_BOTH));
            $this->assertSame([], array_values($money), $key);
        }
        $this->assertStringNotContainsString('Kality', $this->get('/api/seller/erp/reports/suppliers.csv')->streamedContent());
        $this->assertStringNotContainsString('SL-00001', $this->get('/api/seller/erp/reports/returns.csv?period=30d')->streamedContent());
        $this->assertSame("date_time,listing,option,location,type,change,available_after,at_location_after,order,by,note\n",
            $this->get('/api/seller/erp/movements.csv')->streamedContent());

        // Customers and signed-out visitors get nothing.
        $this->as($this->buyer)->getJson('/api/seller/erp/finance')->assertForbidden();
        $this->as($this->buyer)->getJson('/api/seller/erp/reports/products')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/seller/erp/finance')->assertUnauthorized();
        $this->getJson('/api/seller/erp/reports/sales.csv')->assertUnauthorized();
    }

    public function test_reports_hold_the_right_rows_and_sort_page_and_download_them(): void
    {
        ['cod' => $cod, 'online' => $online, 'cancelled' => $cancelled, 'sale' => $sale, 'return' => $return] = $this->scenario();
        $this->as($this->seller->user);

        $this->getJson('/api/seller/erp/reports')->assertOk()->assertJsonCount(count(Reports::definitions()), 'data');
        $this->getJson('/api/seller/erp/reports/nope')->assertNotFound();
        $this->getJson('/api/seller/erp/reports/products?sort=password')->assertUnprocessable();
        $this->getJson('/api/seller/erp/reports/products?per_page=1000')->assertUnprocessable();

        // Sales by day: one row per day, totals over every row.
        $sales = $this->report('sales', 'period=7d&group=day');
        $this->assertCount(7, $sales->json('data'));
        $this->assertSame($this->finance('period=7d')['total']['net_sales_santim'], $sales->json('totals.net_sales_santim'));
        $this->assertSame([$this->today(6), $this->today()], [$sales->json('data.0.period'), $sales->json('data.6.period')]);
        $page = $this->report('sales', 'period=30d&group=day&per_page=10&page=3&sort=period&dir=desc');
        $this->assertSame([3, 3, 30], [$page->json('meta.current_page'), $page->json('meta.last_page'), $page->json('meta.total')]);
        $this->assertSame($this->today(29), $page->json('data.9.period'));
        $this->assertSame($sales->json('totals.net_sales_santim'), $page->json('totals.net_sales_santim'));
        // Printing gets every row on one page.
        $all = $this->report('sales', 'period=30d&group=day&per_page=10&all=1');
        $this->assertSame([30, 1], [count($all->json('data')), $all->json('meta.last_page')]);
        // A page past the end shows the last one.
        $this->assertSame(3, $this->report('sales', 'period=30d&group=day&per_page=10&page=99')->json('meta.current_page'));

        // Products: sofas first by units, the assembly line in "other" with no profit worked out.
        $products = $this->report('products')->json('data');
        $this->assertSame([6, 2 + 1, 3, 1], [$products[0]['units'], $products[0]['marketplace_units'], $products[0]['offline_units'], $products[0]['returned_units']]);
        $this->assertSame($products[0]['net_santim'] - 600_000 * 5, $products[0]['gross_profit_santim']);
        $this->assertSame(['Custom and free-text lines', null, null], [$products[1]['name'], $products[1]['cost_santim'], $products[1]['gross_profit_santim']]);
        $bySales = $this->report('products', 'period=30d&sort=revenue_santim&dir=asc')->json('data');
        $this->assertSame('Custom and free-text lines', $bySales[0]['name']);
        // Empty values go last whichever way the column is sorted.
        $this->assertSame('Custom and free-text lines', $this->report('products', 'period=30d&sort=gross_profit_santim&dir=asc')->json('data.1.name'));

        // Valuation includes a hidden listing that still holds units; low stock does not list it.
        $hidden = Product::factory()->create(['seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id, 'status' => 'archived',
            'stock_quantity' => 2, 'cost_price_santim' => 100_000, 'price_santim' => 300_000]);
        $valuation = $this->report('valuation');
        $sofa = $this->sofa->fresh();
        $this->assertSame([$sofa->stock_quantity + 1, 600_000 * ($sofa->stock_quantity + 1)], [$valuation->json('data.0.on_hand'), $valuation->json('data.0.value_cost_santim')]);
        $this->assertSame(['archived', 2, 200_000], [$valuation->json('data.1.status'), $valuation->json('data.1.on_hand'), $valuation->json('data.1.value_cost_santim')]);
        $this->assertSame($valuation->json('totals.value_cost_santim'), $this->getJson('/api/seller/erp/overview')->json('data.stock.value_cost_santim'));
        $this->assertSame(2, $this->getJson('/api/seller/erp/overview')->json('data.stock.hidden_units'));
        $this->assertSame([], $this->report('low_stock')->json('data'));
        $hidden->forceFill(['status' => 'published', 'min_stock_level' => 3])->save();
        $this->assertSame([[$hidden->name, 'low', 2]], array_map(fn ($r) => [$r['name'], $r['state'], $r['available']], $this->report('low_stock')->json('data')));

        // Stock movements by kind, and every line as CSV with the filters of Stock history.
        $moves = collect($this->report('movements')->json('data'))->keyBy('type');
        $this->assertSame(['entries' => 1, 'units_in' => 0, 'units_out' => 3], array_intersect_key($moves['offline_sale'], array_flip(['entries', 'units_in', 'units_out'])));
        $this->assertSame(1, $moves['sale_returned']['units_in']);
        $lines = $this->csv('/api/seller/erp/movements.csv?type=offline_sale');
        $this->assertSame(2, substr_count($lines, "\n"));
        $this->assertStringContainsString(',offline_sale,-3,', $lines);
        $this->assertStringNotContainsString($this->buyer->name, $this->csv('/api/seller/erp/movements.csv'));

        // Purchase history leaves out drafts; supplier balances show what is owed.
        $this->assertSame([], $this->report('purchases')->json('data'));
        $this->assertSame([['Kality Wood', 500_000, 200_000, 300_000]], array_map(fn ($r) => [$r['name'], $r['bought_santim'], $r['paid_santim'], $r['owed_santim']], $this->report('suppliers')->json('data')));

        // Expenses by category with their share.
        $this->assertSame([['Warehouse rent', 150_000, 75], ['Furniture transportation and delivery', 50_000, 25]],
            array_map(fn ($r) => [$r['category'], $r['total_santim'], $r['share']], $this->report('expenses')->json('data')));

        // Profit rows match the statement.
        $this->assertSame($this->finance()['total']['net_profit_santim'], $this->report('profit', 'period=30d&group=month')->json('totals.net_profit_santim'));

        // Returns, cancellations and refunds: the shop return with its cash back, and the cancelled order.
        $returns = collect($this->report('returns')->json('data'))->keyBy('kind');
        $this->assertSame(['RT-00001', $sale['number'], 'Almaz', $return['returned_santim'], 200_000, 'store'],
            [$returns['shop_return']['document'], $returns['shop_return']['for'], $returns['shop_return']['customer'], $returns['shop_return']['amount_santim'], $returns['shop_return']['money_back_santim'], $returns['shop_return']['refund_by']]);
        $this->assertSame([$cancelled->number, $this->buyer->name, 'cancelled_by_seller', 'Out of stock', 0],
            [$returns['cancelled_order']['document'], $returns['cancelled_order']['customer'], $returns['cancelled_order']['status'], $returns['cancelled_order']['reason'], $returns['cancelled_order']['money_back_santim']]);
        $content = $this->report('returns')->getContent();
        $this->assertStringNotContainsString('911000111', $content);
        $this->assertStringNotContainsString('House 12', $content);
        $this->assertStringNotContainsString($this->buyer->email, $content);

        // An online order cancelled after payment shows the refund the marketplace owes.
        $paid = $this->placeOrder(1);
        $paid->forceFill(['payment_method' => 'online', 'payment_status' => 'paid', 'paid_at' => now(), 'paid_santim' => $paid->total_santim])->save();
        $this->as($this->seller->user)->postJson("/api/seller/orders/{$paid->id}/cancel", ['reason' => 'Damaged in the shop'])->assertOk();
        $row = collect($this->report('returns')->json('data'))->firstWhere('document', $paid->number);
        $this->assertSame([$paid->total_santim, 'marketplace', 'refund_due'], [$row['money_back_santim'], $row['refund_by'], $row['status']]);

        // CSV: every row in the same order, money in birr, empty where it cannot be worked out.
        $csv = $this->csv('/api/seller/erp/reports/products.csv?period=30d');
        $this->assertStringStartsWith("name,option,units,marketplace_units,offline_units,returned_units,revenue_etb,returned_etb,net_etb,cost_etb,gross_profit_etb\n", $csv);
        $this->assertMatchesRegularExpression('/\n"Custom and free-text lines",,1,0,1,0,[0-9.]+,0\.00,[0-9.]+,,\n/', $csv);
        $this->assertSame(1, AuditLog::where('action', 'erp.report_exported')->count());
        $finance = $this->csv('/api/seller/erp/finance.csv?period=7d&group=day');
        $this->assertSame(1 + 7 + 1, substr_count($finance, "\n"));
        $this->assertStringContainsString("\ntotal,{$this->today(6)},{$this->today()},2,1,", $finance);
        $this->assertSame(1, AuditLog::where('action', 'erp.finance_exported')->count());
    }

    public function test_report_csvs_do_not_let_typed_text_run_as_a_formula(): void
    {
        $this->as($this->seller->user);
        $this->postJson('/api/seller/erp/suppliers', ['name' => '=HYPERLINK("http://x.test","Pay here")'])->assertCreated();
        $this->post('/api/seller/erp/expenses', ['expense_category_id' => $this->categoryId('Other operating costs'), 'amount' => '10', 'description' => '-2+3',
            'payment_method' => 'cash'], ['Accept' => 'application/json'])->assertCreated();
        $id = $this->postJson('/api/seller/erp/sales', ['customer_name' => '@SUM(A1)', 'paid_now' => '10', 'payment_method' => 'cash',
            'items' => [['description' => '+cmd', 'quantity' => 1, 'unit_price' => '10']]])->assertCreated()->json('data.id');
        $line = $this->getJson("/api/seller/erp/sales/offline/{$id}")->json('data.items.0.id');
        $this->postJson("/api/seller/erp/sales/offline/{$id}/returns", ['reason' => '=1+1', 'items' => [['item_id' => $line, 'quantity' => 1, 'restock' => false]]])->assertCreated();

        $this->assertStringContainsString("\n\"'=HYPERLINK(\"\"http://x.test\"\",\"\"Pay here\"\")\",", $this->csv('/api/seller/erp/reports/suppliers.csv'));
        $returns = $this->csv('/api/seller/erp/reports/returns.csv?period=today');
        $this->assertStringContainsString(",'@SUM(A1),", $returns);
        $this->assertStringContainsString(",'=1+1\n", $returns);
        $this->sofa->forceFill(['name' => '+cmd|calc'])->save();
        $this->postJson('/api/seller/erp/sales', ['paid_now' => '10000', 'payment_method' => 'cash',
            'items' => [['product_id' => $this->sofa->id, 'quantity' => 1, 'unit_price' => '10000']]])->assertCreated();
        $this->assertStringContainsString("\n'+cmd|calc,", $this->csv('/api/seller/erp/reports/products.csv?period=today'));
        $this->assertStringContainsString("\n'+cmd|calc,", $this->csv('/api/seller/erp/reports/valuation.csv'));
        // A returned line without a cost comes off the sales left out of profit, not off the profit.
        $t = $this->finance('period=today')['total'];
        $this->assertSame([1_000, 0, 1_000_000 - 600_000], [$t['returns_goods_santim'], $t['net_items_without_cost_santim'], $t['gross_profit_santim']]);
        $this->assertSame($t['gross_profit_santim'], $t['net_items_santim'] - $t['net_items_without_cost_santim'] - $t['cost_of_goods_santim']);
        // Negative amounts are numbers, not text.
        $this->assertStringNotContainsString("'-", $this->csv('/api/seller/erp/finance.csv?period=today'));
    }

    public function test_marketplace_order_revenue_is_counted_once_however_it_was_paid(): void
    {
        ['cod' => $cod, 'online' => $online] = $this->scenario();
        $t = $this->finance()['total'];
        // Sales: what customers paid for the delivered orders, once each.
        $this->assertSame($cod->total_santim + $online->total_santim, $t['marketplace_items_santim'] + $t['marketplace_delivery_santim']);
        // The online sale credited to the store's account and the payout are not sales again.
        $this->assertSame($cod->items_total_santim + $online->items_total_santim + 3_050_000, $t['gross_sales_santim']);
        $this->assertSame((int) Order::where('status', 'delivered')->sum('commission_santim'), $t['commission_santim']);
        // A cancelled order adds nothing.
        $this->assertSame(2, $t['marketplace_orders']);
    }
}
