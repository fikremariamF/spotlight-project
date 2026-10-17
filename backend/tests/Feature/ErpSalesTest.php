<?php

namespace Tests\Feature;

use App\Enums\SellerStatus;
use App\Models\AuditLog;
use App\Models\OfflineSale;
use App\Models\OfflineSaleItem;
use App\Models\OfflineSalePayment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\LedgerService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

class ErpSalesTest extends TestCase
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

    private function sell(array $attrs = [], ?array $items = null): TestResponse
    {
        return $this->as($this->seller->user)->postJson('/api/seller/erp/sales', $attrs + [
            'items' => $items ?? [['product_id' => $this->sofa->id, 'quantity' => 3, 'unit_price' => '10000']],
        ]);
    }

    private function giveBack(int $saleId, array $lines, array $extra = []): TestResponse
    {
        return $this->postJson("/api/seller/erp/sales/offline/{$saleId}/returns", $extra + [
            'reason' => 'Wrong colour',
            'items' => array_map(fn ($id, $line) => ['item_id' => $id] + (is_array($line) ? $line : ['quantity' => $line, 'restock' => true]), array_keys($lines), $lines),
        ]);
    }

    public function test_a_sale_takes_stock_once_keeps_its_prices_and_records_what_was_paid(): void
    {
        $sale = $this->sell([
            'customer_name' => 'Almaz', 'customer_phone' => '0911 222 333', 'discount' => '190', 'delivery' => '300',
            'paid_now' => '5000', 'payment_method' => 'cash', 'request_id' => 'sale-form-1',
        ], [
            ['product_id' => $this->sofa->id, 'quantity' => 2, 'unit_price' => '10000', 'discount' => '1000'],
            ['description' => 'Assembly', 'quantity' => 1, 'unit_price' => '500'],
        ])->assertCreated()->json('data');

        // 19,000 + 500 = 19,500 birr of items, less 190 on the whole sale, plus 300 delivery.
        $this->assertSame(['SL-00001', 'completed', 1_950_000, 19_000, 30_000, 1_961_000], [$sale['number'], $sale['status'], $sale['subtotal_santim'], $sale['discount_santim'], $sale['delivery_santim'], $sale['total_santim']]);
        $this->assertSame([500_000, 1_461_000], [$sale['paid_santim'], $sale['balance_santim']]);
        // The whole-sale discount is shared by line value and adds up exactly.
        $this->assertSame([1_881_487, 49_513], array_column($sale['items'], 'net_santim'));
        $this->assertSame(1_961_000 - 30_000, array_sum(array_column($sale['items'], 'net_santim')));
        $this->assertSame([600_000, null], array_column($sale['items'], 'unit_cost_santim'));
        $this->assertSame([true, false], array_column($sale['items'], 'stock_item'));
        $this->assertSame(48, $this->sofa->fresh()->stock_quantity);
        $movement = StockMovement::where('type', 'offline_sale')->sole();
        $this->assertSame([-2, 48, 'offline_sale', $sale['id'], 'SL-00001'], [$movement->quantity, $movement->available_after, $movement->reference_type, $movement->reference_id, $movement->note]);

        // The same form again (a double tap or a retry) takes nothing more.
        $this->sell(['request_id' => 'sale-form-1'])->assertCreated()->assertJsonPath('data.id', $sale['id']);
        $this->assertSame(48, $this->sofa->fresh()->stock_quantity);
        $this->assertSame(1, OfflineSale::count());
        $this->assertSame(1, OfflineSalePayment::count());

        // Prices and cost on the sale stay as they were when the listing changes.
        $this->sofa->forceFill(['cost_price_santim' => 700_000, 'price_santim' => 1_200_000])->save();
        $this->getJson("/api/seller/erp/sales/offline/{$sale['id']}")->assertOk()
            ->assertJsonPath('data.items.0.unit_cost_santim', 600_000)->assertJsonPath('data.items.0.unit_price_santim', 1_000_000)
            ->assertJsonPath('data.customer_phone', '0911 222 333')->assertJsonPath('data.store.name', $this->seller->store_name);
        $this->assertSame(1, AuditLog::where('action', 'erp.sale_recorded')->count());

        // Inventory counts the units as sold.
        $this->getJson("/api/seller/erp/inventory/{$this->sofa->id}")->assertOk()->assertJsonPath('data.items.0.sold', 2);
    }

    public function test_sale_lines_and_totals_are_checked_on_the_server(): void
    {
        $other = $this->approvedSeller();
        $theirs = Product::factory()->published()->create(['seller_profile_id' => $other->id, 'category_id' => $this->category()->id, 'stock_quantity' => 5]);
        $line = fn (array $extra = []) => $extra + ['product_id' => $this->sofa->id, 'quantity' => 1, 'unit_price' => '10000'];

        $this->sell([], [$line(['product_id' => $theirs->id])])->assertUnprocessable()->assertJsonValidationErrors('items.0.product_id');
        $this->sell([], [$line(), $line()])->assertUnprocessable()->assertJsonValidationErrors('items.1.product_id');
        $this->sell([], [$line(['quantity' => 51])])->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
        $this->sell([], [$line(['quantity' => 0])])->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
        $this->sell([], [$line(['discount' => '10000.01'])])->assertUnprocessable()->assertJsonValidationErrors('items.0.discount');
        $this->sell([], [$line(['unit_price' => '-5'])])->assertUnprocessable()->assertJsonValidationErrors('items.0.unit_price');
        $this->sell([], [['quantity' => 1, 'unit_price' => '50']])->assertUnprocessable()->assertJsonValidationErrors('items.0.description');
        $this->sell(['discount' => '10000.01'], [$line()])->assertUnprocessable()->assertJsonValidationErrors('discount');
        $this->sell(['paid_now' => '10000.01', 'payment_method' => 'cash'], [$line()])->assertUnprocessable()->assertJsonValidationErrors('paid_now');
        $this->sell(['paid_now' => '100'], [$line()])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->sell(['sale_date' => $this->today(-1)], [$line()])->assertUnprocessable()->assertJsonValidationErrors('sale_date');
        $this->sell(['sale_date' => $this->today(3), 'due_date' => $this->today(4)], [$line()])->assertUnprocessable()->assertJsonValidationErrors('due_date');
        $this->sell(['customer_phone' => 'call me'], [$line()])->assertUnprocessable()->assertJsonValidationErrors('customer_phone');
        $this->sell(['items' => []])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->sell([], array_fill(0, 101, ['description' => 'x', 'quantity' => 1, 'unit_price' => '1']))->assertUnprocessable()->assertJsonValidationErrors('items');
        $theirPlace = Warehouse::forceCreate(['seller_profile_id' => $other->id, 'name' => 'Theirs']);
        $this->sell(['warehouse_id' => $theirPlace->id], [$line()])->assertUnprocessable()->assertJsonValidationErrors('warehouse_id');
        $this->assertSame(50, $this->sofa->fresh()->stock_quantity);
        $this->assertSame(0, OfflineSale::count());

        // Units must be at the location the sale is from.
        $bole = $this->postJson('/api/seller/erp/warehouses', ['name' => 'Bole shop'])->assertCreated()->json('data.id');
        $this->sell(['warehouse_id' => $bole], [$line()])->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');

        // A listing with options needs one; a made-to-order listing sells without touching stock.
        $this->postJson("/api/seller/products/{$this->sofa->id}/variants", ['color' => 'Grey'])->assertOk();
        $this->sell([], [$line()])->assertUnprocessable()->assertJsonValidationErrors('items.0.variant_id');
        $grey = ProductVariant::sole();
        $this->sell([], [$line(['variant_id' => $grey->id])])->assertCreated()->assertJsonPath('data.items.0.description', $this->sofa->name.' (Grey)');
        $this->assertSame(49, $grey->fresh()->stock_quantity);
        $made = Product::factory()->published()->create(['seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id, 'stock_type' => 'made_to_order', 'stock_quantity' => 0]);
        $this->sell([], [['product_id' => $made->id, 'quantity' => 2, 'unit_price' => '30000']])->assertCreated()->assertJsonPath('data.items.0.stock_item', false);
        $this->assertSame(0, StockMovement::where('product_id', $made->id)->count());
        $this->sell([], [['product_id' => $made->id, 'variant_id' => $grey->id, 'quantity' => 1, 'unit_price' => '1']])->assertUnprocessable()->assertJsonValidationErrors('items.0.variant_id');
    }

    public function test_payments_cannot_go_past_what_is_owed_and_voids_keep_the_history(): void
    {
        $sale = $this->sell(['sale_date' => $this->today(5), 'due_date' => $this->today(1)])->assertCreated()->json('data');
        $this->assertSame([3_000_000, 0, 3_000_000, true], [$sale['total_santim'], $sale['paid_santim'], $sale['balance_santim'], $sale['overdue']]);
        $pay = fn (string $amount, array $extra = []) => $this->postJson("/api/seller/erp/sales/offline/{$sale['id']}/payments", $extra + ['amount' => $amount, 'method' => 'telebirr']);

        $pay('30000.01')->assertUnprocessable()->assertJsonValidationErrors('amount');
        $pay('100', ['paid_on' => $this->today(6)])->assertUnprocessable()->assertJsonValidationErrors('paid_on');
        $pay('100', ['method' => 'gold'])->assertUnprocessable()->assertJsonValidationErrors('method');
        $pay('10000', ['request_id' => 'pay-1'])->assertCreated()->assertJsonPath('data.balance_santim', 2_000_000);
        $pay('10000', ['request_id' => 'pay-1'])->assertCreated()->assertJsonPath('data.balance_santim', 2_000_000);
        $pay('20000')->assertCreated()->assertJsonPath('data.balance_santim', 0)->assertJsonPath('data.overdue', false);
        $pay('1')->assertUnprocessable()->assertJsonPath('errors.amount.0', 'SL-00001 is fully paid.');

        $first = OfflineSalePayment::orderBy('id')->first();
        $this->postJson("/api/seller/erp/sale-payments/{$first->id}/void", ['reason' => 'Bounced'])->assertOk()->assertJsonPath('data.balance_santim', 1_000_000)
            ->assertJsonPath('data.payments.0.status', 'voided');
        $this->postJson("/api/seller/erp/sale-payments/{$first->id}/void", ['reason' => 'Again'])->assertUnprocessable();
        $this->assertSame(2, OfflineSalePayment::count());

        // Only the customer and note details change after the sale.
        $this->putJson("/api/seller/erp/sales/offline/{$sale['id']}", ['customer_name' => 'Kebede', 'note' => 'Pays Fridays', 'total' => '1'])->assertOk()
            ->assertJsonPath('data.customer.name', 'Kebede')->assertJsonPath('data.total_santim', 3_000_000);
        $this->putJson("/api/seller/erp/sales/offline/{$sale['id']}", ['due_date' => $this->today(6)])->assertUnprocessable()->assertJsonValidationErrors('due_date');
    }

    public function test_returns_credit_the_line_share_restock_and_give_back_only_what_was_overpaid(): void
    {
        $sale = $this->sell(['discount' => '100', 'delivery' => '600', 'paid_now' => '30500', 'payment_method' => 'cash'],
            [['product_id' => $this->sofa->id, 'quantity' => 3, 'unit_price' => '10000']])->assertCreated()->json('data');
        $line = $sale['items'][0]['id'];
        // 30,000 less 100 = 29,900 for three sofas: thirds round to 9,966.67, 9,966.66, 9,966.67.
        $this->assertSame([2_990_000, 3_050_000, 0], [$sale['items'][0]['net_santim'], $sale['total_santim'], $sale['balance_santim']]);
        $this->assertSame(47, $this->sofa->fresh()->stock_quantity);

        $this->giveBack($sale['id'], [$line => 4])->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
        $this->giveBack($sale['id'], [$line => 1], ['refund' => '9966.68', 'refund_method' => 'cash'])->assertUnprocessable()->assertJsonValidationErrors('refund');
        $this->giveBack($sale['id'], [$line => 1], ['refund' => '100'])->assertUnprocessable()->assertJsonValidationErrors('refund_method');
        $this->giveBack($sale['id'], [$line => 1], ['delivery_credit' => '600.01'])->assertUnprocessable()->assertJsonValidationErrors('delivery_credit');
        $this->giveBack($sale['id'], [$line => 1], ['returned_on' => $this->today(1)])->assertUnprocessable()->assertJsonValidationErrors('returned_on');

        $after = $this->giveBack($sale['id'], [$line => 1], ['refund' => '9966.67', 'refund_method' => 'cash', 'request_id' => 'ret-1'])->assertCreated()->json('data');
        $this->assertSame(['RT-00001', 996_667, 996_667, 0], [$after['returns'][0]['number'], $after['returns'][0]['credit_santim'], $after['refunded_santim'], $after['balance_santim']]);
        $this->giveBack($sale['id'], [$line => 1], ['request_id' => 'ret-1'])->assertCreated();
        $this->assertSame(1, SaleReturn::count());
        $this->assertSame(48, $this->sofa->fresh()->stock_quantity);
        $back = StockMovement::where('type', 'sale_returned')->sole();
        $this->assertSame([1, 'offline_sale', $sale['id']], [$back->quantity, $back->reference_type, $back->reference_id]);

        // Damaged units come back without going into stock; every unit returned credits exactly the line.
        $after = $this->giveBack($sale['id'], [$line => ['quantity' => 2, 'restock' => false]], ['delivery_credit' => '600'])->assertCreated()->json('data');
        $this->assertSame(1_993_333 + 60_000, $after['returns'][1]['credit_santim']);
        $this->assertSame(2_990_000 + 60_000, $after['credited_santim']);
        $this->assertSame(48, $this->sofa->fresh()->stock_quantity);
        // Nothing given back on that one yet: the store holds the money for the customer.
        $this->assertSame(-2_053_333, $after['balance_santim']);
        $this->giveBack($sale['id'], [$line => 1])->assertUnprocessable()->assertJsonValidationErrors(['items.0.quantity' => 'All of '.$this->sofa->name.' was already returned.']);

        // The sale cannot be voided while returns stand; voiding a payment cannot leave refunds unpaid for.
        $this->postJson("/api/seller/erp/sales/offline/{$sale['id']}/void", ['reason' => 'Mistake'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $payment = OfflineSalePayment::sole();
        $this->postJson("/api/seller/erp/sale-payments/{$payment->id}/void", ['reason' => 'Mistake'])->assertUnprocessable();

        // Undoing a return takes restocked units out again.
        $returns = SaleReturn::orderBy('id')->get();
        $this->postJson("/api/seller/erp/sale-returns/{$returns[0]->id}/void", ['reason' => 'Entered twice'])->assertOk()->assertJsonPath('data.returns.0.status', 'voided');
        $this->assertSame(47, $this->sofa->fresh()->stock_quantity);
        $this->postJson("/api/seller/erp/sale-returns/{$returns[0]->id}/void", ['reason' => 'Again'])->assertUnprocessable();
        $this->postJson("/api/seller/erp/sale-returns/{$returns[1]->id}/void", ['reason' => 'Entered twice'])->assertOk()->assertJsonPath('data.balance_santim', 0);

        // Then the sale can be voided: its units go back and its payment is voided with it.
        $this->postJson("/api/seller/erp/sales/offline/{$sale['id']}/void", ['reason' => 'Customer cancelled'])->assertOk()
            ->assertJsonPath('data.status', 'voided')->assertJsonPath('data.balance_santim', 0)->assertJsonPath('data.payments.0.status', 'voided')
            ->assertJsonPath('data.can_return', false);
        $this->assertSame(50, $this->sofa->fresh()->stock_quantity);
        $this->postJson("/api/seller/erp/sales/offline/{$sale['id']}/void", ['reason' => 'Again'])->assertUnprocessable();
        $this->postJson("/api/seller/erp/sales/offline/{$sale['id']}/payments", ['amount' => '1', 'method' => 'cash'])->assertUnprocessable();
        $this->assertSame([-3, 1, -1, 3], StockMovement::orderBy('id')->where('product_id', $this->sofa->id)->whereIn('type', ['offline_sale', 'sale_returned', 'sale_return_voided', 'offline_sale_voided'])->pluck('quantity')->all());
    }

    public function test_an_unpaid_sale_return_lowers_what_is_owed_and_a_sold_out_return_cannot_be_undone(): void
    {
        $sale = $this->sell([], [['product_id' => $this->sofa->id, 'quantity' => 2, 'unit_price' => '10000']])->assertCreated()->json('data');
        $line = $sale['items'][0]['id'];
        $this->giveBack($sale['id'], [$line => 1], ['refund' => '1', 'refund_method' => 'cash'])->assertUnprocessable()->assertJsonValidationErrors('refund');
        $this->giveBack($sale['id'], [$line => 1])->assertCreated()->assertJsonPath('data.balance_santim', 1_000_000);
        $this->assertSame(49, $this->sofa->fresh()->stock_quantity);

        // The returned unit was sold again, so undoing the return would leave the shop short.
        $this->postJson("/api/seller/erp/inventory/{$this->sofa->id}/adjust", ['type' => 'count', 'quantity' => 0])->assertOk();
        $this->postJson('/api/seller/erp/sale-returns/'.SaleReturn::sole()->id.'/void', ['reason' => 'Oops'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame('recorded', SaleReturn::sole()->status);
    }

    public function test_voided_sale_units_go_back_to_the_default_location_when_theirs_was_closed(): void
    {
        $bole = $this->as($this->seller->user)->postJson('/api/seller/erp/warehouses', ['name' => 'Bole shop'])->assertCreated()->json('data.id');
        $main = Warehouse::where('default_for', $this->seller->id)->value('id');
        $this->postJson('/api/seller/erp/transfers', ['from_warehouse_id' => $main, 'to_warehouse_id' => $bole, 'received_now' => true,
            'items' => [['product_id' => $this->sofa->id, 'quantity' => 2]]])->assertCreated();
        $sale = $this->sell(['warehouse_id' => $bole], [['product_id' => $this->sofa->id, 'quantity' => 2, 'unit_price' => '10000']])->assertCreated()->json('data');
        $this->postJson("/api/seller/erp/warehouses/{$bole}/close")->assertOk();
        $this->postJson("/api/seller/erp/sales/offline/{$sale['id']}/void", ['reason' => 'Mistake'])->assertOk();
        $this->assertSame(50, $this->sofa->fresh()->stock_quantity);
        $this->assertSame($main, StockMovement::where('type', 'offline_sale_voided')->sole()->warehouse_id);
    }

    public function test_a_marketplace_return_is_recorded_without_touching_the_order_or_the_balance(): void
    {
        $order = $this->placeOrder(2);
        $this->as($this->seller->user)->postJson("/api/seller/erp/sales/orders/{$order->id}/returns", ['reason' => 'Broken', 'items' => [['item_id' => 1, 'quantity' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items');
        $order = $this->deliver($order);
        $before = [$order->total_santim, $order->commission_santim, $order->status->value, $order->payment_status, LedgerService::balance($this->seller->id)];
        $item = $order->items()->sole();
        $this->assertSame(48, $this->sofa->fresh()->stock_quantity);

        $ret = fn (array $extra) => $this->as($this->seller->user)->postJson("/api/seller/erp/sales/orders/{$order->id}/returns", $extra + [
            'reason' => 'Scratched leg', 'items' => [['item_id' => $item->id, 'quantity' => 1, 'restock' => true]],
        ]);
        $fee = $order->delivery_fee_santim;
        $this->assertGreaterThan(0, $fee);
        $ret(['refund' => '10000.01', 'refund_method' => 'cash'])->assertUnprocessable()->assertJsonValidationErrors('refund');
        $ret(['delivery_credit' => Money::toBirr($fee + 1)])->assertUnprocessable()->assertJsonValidationErrors('delivery_credit');
        $view = $ret(['refund' => Money::toBirr(1_000_000 + $fee), 'refund_method' => 'cash', 'delivery_credit' => Money::toBirr($fee)])->assertCreated()->json('data');
        $this->assertSame([1_000_000 + $fee, 1_000_000 + $fee, 1, 1], [$view['returned_santim'], $view['refunded_santim'], $view['items'][0]['returned'], $view['items'][0]['returnable']]);
        $this->assertSame(49, $this->sofa->fresh()->stock_quantity);
        $movement = StockMovement::where('type', 'sale_returned')->sole();
        $this->assertSame([$order->id, 'sale_return'], [$movement->order_id, $movement->reference_type]);

        $order->refresh();
        $this->assertSame($before, [$order->total_santim, $order->commission_santim, $order->status->value, $order->payment_status, LedgerService::balance($this->seller->id)]);
        $this->assertSame(0, Refund::count());

        // Online orders are refunded by Betoch support, never by the store's record.
        $order->forceFill(['payment_method' => 'online', 'paid_at' => now()])->save();
        $ret(['refund' => '1', 'refund_method' => 'cash'])->assertUnprocessable()->assertJsonValidationErrors('refund');
        $ret(['delivery_credit' => '1'])->assertUnprocessable()->assertJsonValidationErrors('delivery_credit');
        $ret([])->assertCreated()->assertJsonPath('data.items.0.returnable', 0);
        $ret([])->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
        $this->getJson('/api/seller/erp/inventory/'.$this->sofa->id)->assertJsonPath('data.items.0.sold', 0);
    }

    public function test_a_marketplace_receipt_shows_the_name_and_area_but_never_the_phone_or_street(): void
    {
        $order = $this->placeOrder(1);
        $this->as($this->seller->user)->getJson("/api/seller/erp/sales/orders/{$order->id}")->assertNotFound();
        $this->postJson("/api/seller/orders/{$order->id}/confirm")->assertOk();
        $res = $this->getJson("/api/seller/erp/sales/orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.customer.name', $this->buyer->name)->assertJsonPath('data.customer.area', 'Bole')
            ->assertJsonPath('data.can_return', false);
        $this->assertStringNotContainsString('911000111', $res->getContent());
        $this->assertStringNotContainsString('House 12', $res->getContent());
        $this->assertStringNotContainsString($this->buyer->email, $res->getContent());

        $this->postJson("/api/seller/orders/{$order->id}/cancel", ['reason' => 'Out of stock'])->assertOk();
        $this->getJson("/api/seller/erp/sales/orders/{$order->id}")->assertNotFound();
    }

    public function test_the_sales_list_and_report_join_both_kinds_of_sale(): void
    {
        $delivered = $this->deliver($this->placeOrder(2));
        $this->as($this->seller->user);
        $paid = $this->sell(['customer_name' => 'Almaz', 'paid_now' => '30000', 'payment_method' => 'cash'])->assertCreated()->json('data');
        $owing = $this->sell(['customer_name' => 'Bekele', 'sale_date' => $this->today(2), 'paid_now' => '100', 'payment_method' => 'cbe_birr'],
            [['product_id' => $this->sofa->id, 'quantity' => 1, 'unit_price' => '9000'], ['description' => 'Delivery to Gerji', 'quantity' => 1, 'unit_price' => '200']])->assertCreated()->json('data');
        $voided = $this->sell([], [['description' => 'Old chair', 'quantity' => 1, 'unit_price' => '700']])->assertCreated()->json('data');
        $this->postJson("/api/seller/erp/sales/offline/{$voided['id']}/void", ['reason' => 'Mistake'])->assertOk();
        $this->giveBack($paid['id'], [$paid['items'][0]['id'] => 1]);
        $this->placeOrder(1);
        $this->as($this->seller->user);

        $list = $this->getJson('/api/seller/erp/sales')->assertOk();
        $this->assertSame(4, $list->json('meta.total'));
        $this->assertEqualsCanonicalizing(['marketplace', 'offline', 'offline', 'offline'], array_column($list->json('data'), 'kind'));
        $this->assertSame($owing['number'], $list->json('data.3.number'));
        $this->getJson('/api/seller/erp/sales?source=marketplace')->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', $delivered->number)
            ->assertJsonPath('data.0.customer.name', $this->buyer->name);
        $this->getJson('/api/seller/erp/sales?status=owing')->assertJsonCount(1, 'data')->assertJsonPath('data.0.balance_santim', 920_000 - 10_000);
        // Money still owed is as of now, whatever the period.
        $this->getJson('/api/seller/erp/sales?status=owing&period=today')->assertJsonCount(1, 'data');
        $this->getJson('/api/seller/erp/sales?status=voided')->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'voided');
        $this->getJson('/api/seller/erp/sales?q=bekele')->assertJsonCount(1, 'data');
        $this->getJson('/api/seller/erp/sales?period=today')->assertOk()->assertJsonPath('meta.total', 3);

        $report = $this->getJson('/api/seller/erp/sales/report')->assertOk()->json('data');
        $this->assertSame([1, 2], [$report['marketplace']['orders'], $report['offline']['sales']]);
        $this->assertSame(2_000_000 + 3_000_000 + 920_000, $report['totals']['items_santim']);
        $this->assertSame(1_000_000, $report['totals']['returns_santim']);
        $this->assertSame($report['totals']['items_santim'] + $report['totals']['delivery_santim'] - 1_000_000, $report['totals']['net_santim']);
        // Sofas: 2 delivered, 3 + 1 sold offline, 1 back: cost 600,000 each, the returned one back in stock.
        $sofa = collect($report['products'])->firstWhere('product_id', $this->sofa->id);
        $this->assertSame([6, 2, 4, 1], [$sofa['units'], $sofa['marketplace_units'], $sofa['offline_units'], $sofa['returned_units']]);
        $this->assertSame(2_000_000 + 3_000_000 + 900_000 - 1_000_000, $sofa['net_santim']);
        $this->assertSame((2_000_000 - 1_200_000) + (3_900_000 - 2_400_000) + (-1_000_000 + 600_000), $report['totals']['gross_profit_santim']);
        $this->assertSame(['owe_santim' => 910_000, 'owing_sales' => 1, 'overdue_sales' => 0, 'credit_santim' => 1_000_000], $report['customers']);
        $this->assertSame(1, $report['in_progress']['pending_confirmation']);
        $this->assertSame(1, collect($report['products'])->whereNull('product_id')->count());

        $csv = $this->get('/api/seller/erp/sales.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString("marketplace_sale,{$delivered->number}", $csv);
        $this->assertStringContainsString('"Delivery to Gerji"', $csv);
        $this->assertStringContainsString('return,RT-00001,'.$paid['number'].',Almaz', $csv);
        $this->assertStringNotContainsString('Old chair', $csv);
        // Returns go out as negative quantities and amounts.
        $this->assertMatchesRegularExpression('/return,RT-00001,[^\n]*,-1,-10000\.00,6000\.00,yes/', $csv);

        $overview = $this->getJson('/api/seller/erp/overview')->assertOk()->json('data.sales');
        $this->assertSame([1, 2, 1], [$overview['marketplace']['orders'], $overview['offline']['sales'], $overview['returns']['returns']]);
        $this->assertSame($this->sofa->id, $overview['top_products'][0]['product_id']);
    }

    public function test_another_store_customers_and_suspended_stores_are_kept_out(): void
    {
        $sale = $this->sell(['paid_now' => '100', 'payment_method' => 'cash'])->assertCreated()->json('data');
        $this->giveBack($sale['id'], [$sale['items'][0]['id'] => 1]);
        $order = $this->deliver($this->placeOrder(1));
        $payment = OfflineSalePayment::sole()->id;
        $return = SaleReturn::sole()->id;

        $other = $this->approvedSeller();
        $this->as($other->user);
        $this->getJson('/api/seller/erp/sales')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/seller/erp/sales/report')->assertOk()->assertJsonPath('data.totals.sales', 0);
        $this->getJson("/api/seller/erp/sales/offline/{$sale['id']}")->assertNotFound();
        $this->getJson("/api/seller/erp/sales/orders/{$order->id}")->assertNotFound();
        $this->putJson("/api/seller/erp/sales/offline/{$sale['id']}", ['note' => 'mine'])->assertNotFound();
        $this->postJson("/api/seller/erp/sales/offline/{$sale['id']}/void", ['reason' => 'mine'])->assertNotFound();
        $this->postJson("/api/seller/erp/sales/offline/{$sale['id']}/payments", ['amount' => '1', 'method' => 'cash'])->assertNotFound();
        $this->postJson("/api/seller/erp/sale-payments/{$payment}/void", ['reason' => 'mine'])->assertNotFound();
        $this->postJson("/api/seller/erp/sale-returns/{$return}/void", ['reason' => 'mine'])->assertNotFound();
        $this->postJson("/api/seller/erp/sales/offline/{$sale['id']}/returns", ['reason' => 'mine', 'items' => [['item_id' => $sale['items'][0]['id'], 'quantity' => 1]]])->assertNotFound();
        $this->postJson("/api/seller/erp/sales/orders/{$order->id}/returns", ['reason' => 'mine', 'items' => [['item_id' => $order->items()->value('id'), 'quantity' => 1]]])->assertNotFound();
        // Another store's line ids on one's own sale are not lines of it.
        $mine = $this->postJson('/api/seller/erp/sales', ['items' => [['description' => 'Chair', 'quantity' => 1, 'unit_price' => '10']]])->assertCreated()->json('data');
        $this->postJson("/api/seller/erp/sales/offline/{$mine['id']}/returns", ['reason' => 'Broken', 'items' => [['item_id' => $sale['items'][0]['id'], 'quantity' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.item_id');
        $this->assertSame([47, 'recorded', 'completed'], [$this->sofa->fresh()->stock_quantity, SaleReturn::find($return)->status, OfflineSale::find($sale['id'])->status]);

        $this->as($this->buyer)->getJson('/api/seller/erp/sales')->assertForbidden();
        $this->as($this->admin())->getJson('/api/seller/erp/sales')->assertForbidden();

        $this->seller->forceFill(['status' => SellerStatus::Suspended])->save();
        $this->as($this->seller->user)->getJson('/api/seller/erp/sales')->assertOk();
        $this->getJson("/api/seller/erp/sales/offline/{$sale['id']}")->assertOk();
        $this->postJson('/api/seller/erp/sales', ['items' => [['description' => 'Chair', 'quantity' => 1, 'unit_price' => '10']]])->assertForbidden();
        $this->postJson("/api/seller/erp/sales/offline/{$sale['id']}/void", ['reason' => 'x'])->assertForbidden();
        $this->postJson("/api/seller/erp/sale-returns/{$return}/void", ['reason' => 'x'])->assertForbidden();
    }

    public function test_rolling_back_the_tables_is_refused_once_sales_exist(): void
    {
        $this->sell()->assertCreated();
        $migration = require database_path('migrations/2026_10_19_200000_create_erp_sales_and_expenses_tables.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_sale_items_keep_their_line_when_the_listing_is_deleted(): void
    {
        $sale = $this->sell([], [['product_id' => $this->sofa->id, 'quantity' => 1, 'unit_price' => '10000']])->assertCreated()->json('data');
        $this->deleteJson("/api/seller/products/{$this->sofa->id}")->assertSuccessful();
        $this->getJson("/api/seller/erp/sales/offline/{$sale['id']}")->assertOk()->assertJsonPath('data.items.0.description', $this->sofa->name);
        $this->giveBack($sale['id'], [$sale['items'][0]['id'] => 1])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->giveBack($sale['id'], [$sale['items'][0]['id'] => ['quantity' => 1, 'restock' => false]])->assertCreated();
        $this->assertSame(1, OfflineSaleItem::count());
    }
}
