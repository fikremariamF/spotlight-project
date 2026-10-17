<?php

namespace Tests\Feature;

use App\Enums\SellerStatus;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReceipt;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Warehouse;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

class ErpPurchasingTest extends TestCase
{
    use PlacesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->setUpOrders();
    }

    private function supplier(array $attrs = []): int
    {
        return $this->as($this->seller->user)->postJson('/api/seller/erp/suppliers', $attrs + ['name' => 'Merkato Wood'])
            ->assertCreated()->json('data.id');
    }

    /** A purchase order for 10 sofas at 5,000 birr less 1,000 on the line, plus 2,000 birr transport. */
    private function order(int $supplierId, array $attrs = []): array
    {
        return $this->as($this->seller->user)->postJson('/api/seller/erp/purchases', $attrs + [
            'supplier_id' => $supplierId,
            'charges' => '2000', 'charges_note' => 'Transport',
            'items' => [['product_id' => $this->sofa->id, 'quantity' => 10, 'unit_cost' => '5000', 'discount' => '1000']],
        ])->assertCreated()->json('data');
    }

    private function sent(int $supplierId, array $attrs = []): array
    {
        $po = $this->order($supplierId, $attrs);

        return $this->postJson("/api/seller/erp/purchases/{$po['id']}/order")->assertOk()->json('data');
    }

    private function receive(int $poId, array $lines, array $extra = []): TestResponse
    {
        return $this->as($this->seller->user)->postJson("/api/seller/erp/purchases/{$poId}/receive", $extra + [
            'items' => array_map(fn ($id, $q) => ['item_id' => $id, 'quantity' => $q], array_keys($lines), $lines),
        ]);
    }

    public function test_received_goods_add_stock_once_at_their_landed_cost(): void
    {
        $this->sofa->forceFill(['cost_price_santim' => 600_000])->save();
        $po = $this->order($this->supplier());
        $this->assertSame([4_900_000, 0, 200_000, 5_100_000, 'PO-00001', 'draft'], [$po['subtotal_santim'], $po['discount_santim'], $po['charges_santim'], $po['total_santim'], $po['number'], $po['status']]);
        $line = $po['items'][0]['id'];
        // Transport is shared into the cost: (49,000 + 2,000) / 10 = 5,100 birr a sofa.
        $this->assertSame(510_000, $po['items'][0]['landed_unit_cost_santim']);
        $this->assertSame(50, $this->sofa->fresh()->stock_quantity);

        $this->receive($po['id'], [$line => 4])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson("/api/seller/erp/purchases/{$po['id']}/order")->assertOk()->assertJsonPath('data.status', 'ordered');
        $this->assertSame(50, $this->sofa->fresh()->stock_quantity);

        $this->receive($po['id'], [$line => 4], ['request_id' => 'form-1'])->assertOk()
            ->assertJsonPath('data.status', 'partially_received')->assertJsonPath('data.items.0.outstanding', 6)
            ->assertJsonPath('data.receipts.0.number', 'GRN-00001');
        $sofa = $this->sofa->fresh();
        $this->assertSame(54, $sofa->stock_quantity);
        // Weighted average of 50 at 6,000 and 4 at 5,100.
        $this->assertSame((int) round((50 * 600_000 + 4 * 510_000) / 54), $sofa->cost_price_santim);
        $movement = StockMovement::where('type', 'purchase_received')->sole();
        $receipt = PurchaseReceipt::sole();
        $this->assertSame([4, 54, 'purchase_receipt', $receipt->id], [$movement->quantity, $movement->available_after, $movement->reference_type, $movement->reference_id]);

        // The same form sent again (a double tap or a retry) adds nothing.
        $this->receive($po['id'], [$line => 4], ['request_id' => 'form-1'])->assertOk();
        $this->assertSame(54, $this->sofa->fresh()->stock_quantity);
        $this->assertSame(1, PurchaseReceipt::count());

        $this->receive($po['id'], [$line => 7])->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
        $this->receive($po['id'], [$line => 0])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->receive($po['id'], [$line => 6])->assertOk()->assertJsonPath('data.status', 'received')->assertJsonPath('data.receipts.1.number', 'GRN-00002');
        $this->assertSame(60, $this->sofa->fresh()->stock_quantity);
        $this->receive($po['id'], [$line => 1])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame(60, $this->sofa->fresh()->stock_quantity);
        $this->assertSame(2, AuditLog::where('action', 'erp.purchase_received')->count());

        // A future date or one before the order is refused.
        $po2 = $this->sent(Supplier::sole()->id);
        $this->receive($po2['id'], [$po2['items'][0]['id'] => 1], ['received_on' => now(config('marketplace.timezone'))->addDays(3)->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('received_on');
    }

    public function test_cost_updates_can_be_turned_off_and_unknown_costs_take_the_landed_cost(): void
    {
        $po = $this->sent($this->supplier());
        $this->receive($po['id'], [$po['items'][0]['id'] => 2])->assertOk();
        $this->assertSame(510_000, $this->sofa->fresh()->cost_price_santim);

        $this->putJson('/api/seller/erp/settings', ['cost_from_purchases' => false])->assertOk()->assertJsonPath('data.cost_from_purchases', false);
        $this->sofa->forceFill(['cost_price_santim' => 100])->save();
        $this->receive($po['id'], [$po['items'][0]['id'] => 2])->assertOk();
        $this->assertSame(100, $this->sofa->fresh()->cost_price_santim);
    }

    public function test_order_lines_are_checked_and_totals_worked_out_on_the_server(): void
    {
        $supplier = $this->supplier();
        $url = '/api/seller/erp/purchases';
        $line = ['product_id' => $this->sofa->id, 'quantity' => 2, 'unit_cost' => '100'];
        $post = fn (array $body) => $this->postJson($url, $body + ['supplier_id' => $supplier, 'items' => [$line]]);

        $post(['discount' => '201'])->assertUnprocessable()->assertJsonValidationErrors('discount');
        $post(['items' => [$line + ['discount' => '200.01']]])->assertUnprocessable()->assertJsonValidationErrors('items.0.discount');
        $post(['items' => [$line, $line]])->assertUnprocessable()->assertJsonValidationErrors('items.1.product_id');
        $post(['items' => []])->assertUnprocessable();
        $post(['items' => [['quantity' => 0] + $line]])->assertUnprocessable();
        $post(['items' => [['unit_cost' => '12.345'] + $line]])->assertUnprocessable();
        $post(['items' => [['unit_cost' => '-5'] + $line]])->assertUnprocessable();
        $post(['order_date' => now(config('marketplace.timezone'))->addDays(2)->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('order_date');
        $post(['order_date' => '2026-10-10', 'expected_date' => '2026-10-09'])->assertUnprocessable()->assertJsonValidationErrors('expected_date');

        // Only this store's listings, suppliers and locations.
        $other = $this->approvedSeller();
        $theirs = Product::factory()->published()->create(['seller_profile_id' => $other->id, 'category_id' => $this->category()->id]);
        $post(['items' => [['product_id' => $theirs->id] + $line]])->assertUnprocessable()->assertJsonValidationErrors('items.0.product_id');
        $theirSupplier = Supplier::forceCreate(['seller_profile_id' => $other->id, 'name' => 'Theirs']);
        $this->postJson($url, ['supplier_id' => $theirSupplier->id, 'items' => [$line]])->assertUnprocessable()->assertJsonValidationErrors('supplier_id');
        $theirPlace = Warehouse::where('seller_profile_id', $other->id)->first() ?? Warehouse::forceCreate(['seller_profile_id' => $other->id, 'name' => 'X']);
        $post(['warehouse_id' => $theirPlace->id])->assertUnprocessable()->assertJsonValidationErrors('warehouse_id');

        // Made to order keeps no stock; a listing with options needs one chosen.
        $bed = Product::factory()->published()->create(['seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id,
            'stock_type' => 'made_to_order', 'stock_quantity' => 0, 'production_days' => 10]);
        $post(['items' => [['product_id' => $bed->id] + $line]])->assertUnprocessable()->assertJsonValidationErrors('items.0.product_id');
        $this->postJson("/api/seller/products/{$this->sofa->id}/variants", ['color' => 'Grey', 'stock_quantity' => 50])->assertOk();
        $grey = ProductVariant::sole();
        $post([])->assertUnprocessable()->assertJsonValidationErrors('items.0.variant_id');

        $po = $post(['discount' => '50', 'charges' => '20.50', 'items' => [['variant_id' => $grey->id] + $line + ['discount' => '10']]])->assertCreated()->json('data');
        $this->assertSame([19_000, 5_000, 2_050, 16_050], [$po['subtotal_santim'], $po['discount_santim'], $po['charges_santim'], $po['total_santim']]);
        $this->assertSame('Sofa (Grey)', str_replace($this->sofa->name, 'Sofa', $po['items'][0]['description']));
    }

    public function test_drafts_change_freely_and_sent_orders_only_their_dates_and_notes(): void
    {
        $po = $this->order($this->supplier());
        $chair = Product::factory()->published()->create(['seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id, 'stock_quantity' => 1]);
        $this->putJson("/api/seller/erp/purchases/{$po['id']}", ['supplier_id' => $po['supplier']['id'], 'items' => [
            ['product_id' => $chair->id, 'quantity' => 3, 'unit_cost' => '700'],
            ['product_id' => $this->sofa->id, 'quantity' => 1, 'unit_cost' => '5000'],
        ]])->assertOk()->assertJsonCount(2, 'data.items')->assertJsonPath('data.total_santim', 710_000);

        $this->postJson("/api/seller/erp/purchases/{$po['id']}/order")->assertOk();
        $this->postJson("/api/seller/erp/purchases/{$po['id']}/order")->assertUnprocessable();
        $this->putJson("/api/seller/erp/purchases/{$po['id']}", ['expected_date' => now(config('marketplace.timezone'))->addDays(5)->toDateString(), 'notes' => 'Call before',
            'items' => [['product_id' => $chair->id, 'quantity' => 99, 'unit_cost' => '1']]])->assertOk()
            ->assertJsonPath('data.notes', 'Call before')->assertJsonCount(2, 'data.items')->assertJsonPath('data.total_santim', 710_000);
    }

    public function test_cancelling_and_closing_follow_what_was_received_and_paid(): void
    {
        $supplier = $this->supplier();
        $po = $this->sent($supplier);
        $this->postJson("/api/seller/erp/suppliers/{$supplier}/payments", ['purchase_order_id' => $po['id'], 'amount' => '1000', 'method' => 'cash'])->assertCreated();
        $payment = SupplierPayment::sole();
        $this->postJson("/api/seller/erp/purchases/{$po['id']}/cancel", ['reason' => 'Changed mind'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson("/api/seller/erp/supplier-payments/{$payment->id}/void", ['reason' => 'Refunded'])->assertOk()->assertJsonPath('data.status', 'voided');
        $this->postJson("/api/seller/erp/supplier-payments/{$payment->id}/void", ['reason' => 'Again'])->assertUnprocessable();
        $this->postJson("/api/seller/erp/purchases/{$po['id']}/cancel", ['reason' => 'Changed mind'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->receive($po['id'], [$po['items'][0]['id'] => 1])->assertUnprocessable();

        $po = $this->sent($supplier);
        $this->postJson("/api/seller/erp/purchases/{$po['id']}/close", ['reason' => 'Nothing came'])->assertUnprocessable();
        $this->receive($po['id'], [$po['items'][0]['id'] => 3])->assertOk();
        $this->postJson("/api/seller/erp/purchases/{$po['id']}/cancel", ['reason' => 'x'])->assertUnprocessable();
        $this->postJson("/api/seller/erp/purchases/{$po['id']}/close", ['reason' => 'Rest is out of stock'])->assertOk()
            ->assertJsonPath('data.status', 'received')->assertJsonPath('data.closed_short', true)->assertJsonPath('data.items.0.outstanding', 0);
        $this->receive($po['id'], [$po['items'][0]['id'] => 1])->assertUnprocessable();
        $this->assertSame(53, $this->sofa->fresh()->stock_quantity);

        // Nothing more is coming, so a payment can cover what arrived but no longer the whole order.
        $closed = $this->getJson("/api/seller/erp/purchases/{$po['id']}")->json('data');
        $this->assertGreaterThan($closed['payable_santim'], $closed['total_santim']);
        $pay = fn ($santim) => $this->postJson("/api/seller/erp/suppliers/{$supplier}/payments", ['purchase_order_id' => $po['id'], 'amount' => Money::toBirr($santim), 'method' => 'cash']);
        $pay($closed['payable_santim'] + 1)->assertUnprocessable()->assertJsonValidationErrors('amount');
        $pay($closed['payable_santim'])->assertCreated();
        $pay(1)->assertUnprocessable()->assertJsonPath('errors.amount.0', "{$closed['number']} is fully paid.");
    }

    public function test_balances_count_received_goods_until_the_invoice_replaces_them(): void
    {
        $supplier = $this->supplier(['payment_days' => 30]);
        $po = $this->sent($supplier);
        $owed = fn () => $this->getJson("/api/seller/erp/suppliers/{$supplier}")->assertOk()->json('data');
        $pay = fn (array $body) => $this->postJson("/api/seller/erp/suppliers/{$supplier}/payments", $body + ['method' => 'bank_transfer']);
        $bill = fn (array $body) => $this->postJson("/api/seller/erp/suppliers/{$supplier}/invoices", $body);

        $this->assertSame(0, $owed()['owed_santim']);
        $pay(['amount' => '1'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->receive($po['id'], [$po['items'][0]['id'] => 10])->assertOk();
        $figures = fn () => array_map(fn ($k) => $owed()[$k], ['bought_santim', 'owed_santim', 'received_not_invoiced_santim']);
        $this->assertSame([5_100_000, 5_100_000, 5_100_000], $figures());

        $pay(['amount' => '60000'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $pay(['amount' => '20000', 'request_id' => 'pay-1'])->assertCreated();
        $pay(['amount' => '20000', 'request_id' => 'pay-1'])->assertCreated();
        $this->assertSame(1, SupplierPayment::count());
        $this->assertSame(3_100_000, $owed()['owed_santim']);
        $pay(['amount' => '1', 'paid_on' => now(config('marketplace.timezone'))->addDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('paid_on');
        $pay(['amount' => '1', 'method' => 'gold'])->assertUnprocessable()->assertJsonValidationErrors('method');

        // The supplier's invoice (with a discount) replaces the received value.
        $bill(['invoice_number' => 'INV-1', 'amount' => '50000', 'purchase_order_id' => $po['id'], 'invoice_date' => '2026-10-15'])->assertCreated()
            ->assertJsonPath('data.due_date', '2026-11-14');
        $this->assertSame([5_000_000, 3_000_000, 0], $figures());
        $bill(['invoice_number' => 'inv-1 ', 'amount' => '10'])->assertUnprocessable()->assertJsonValidationErrors('invoice_number');
        $bill(['invoice_number' => 'INV-2', 'amount' => '10', 'invoice_date' => now(config('marketplace.timezone'))->addDays(2)->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('invoice_date');
        $bill(['invoice_number' => 'INV-2', 'amount' => '10', 'invoice_date' => '2026-10-15', 'due_date' => '2026-10-14'])->assertUnprocessable()->assertJsonValidationErrors('due_date');
        $draft = $this->order($supplier);
        $bill(['invoice_number' => 'INV-2', 'amount' => '10', 'purchase_order_id' => $draft['id']])->assertUnprocessable()->assertJsonValidationErrors('purchase_order_id');
        $pay(['amount' => '10', 'purchase_order_id' => $draft['id']])->assertUnprocessable()->assertJsonValidationErrors('purchase_order_id');

        // Voiding the invoice brings back the received value; the same number can then be entered again.
        $invoice = $this->getJson("/api/seller/erp/suppliers/{$supplier}")->json('data.invoices.0.id');
        $this->postJson("/api/seller/erp/supplier-invoices/{$invoice}/void", ['reason' => 'Wrong amount'])->assertOk();
        $this->assertSame(3_100_000, $owed()['owed_santim']);
        $bill(['invoice_number' => 'INV-1', 'amount' => '51000', 'purchase_order_id' => $po['id']])->assertCreated();

        // Against an order, a payment can be a deposit up to the order total; then the order is fully paid.
        $pay(['amount' => '51000.01', 'purchase_order_id' => $po['id']])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $pay(['amount' => '51000', 'purchase_order_id' => $po['id']])->assertCreated();
        $this->assertSame(-2_000_000, $owed()['owed_santim']);
        $pay(['amount' => '1', 'purchase_order_id' => $po['id']])->assertUnprocessable();
        $this->assertSame(2, AuditLog::where('action', 'erp.invoice_recorded')->count());
        $this->assertSame(1, AuditLog::where('action', 'erp.invoice_voided')->count());

        // Another supplier's order cannot carry this supplier's invoice.
        $other = $this->supplier(['name' => 'Piassa Metal']);
        $this->postJson("/api/seller/erp/suppliers/{$other}/invoices", ['invoice_number' => 'A', 'amount' => '5', 'purchase_order_id' => $po['id']])
            ->assertUnprocessable()->assertJsonValidationErrors('purchase_order_id');
    }

    public function test_stock_on_its_way_keeps_its_listing_and_option(): void
    {
        $po = $this->sent($this->supplier());
        $this->deleteJson("/api/seller/products/{$this->sofa->id}")->assertUnprocessable()->assertJsonValidationErrors('product');
        $this->putJson("/api/seller/products/{$this->sofa->id}", ['stock_type' => 'made_to_order', 'production_days' => 5])
            ->assertUnprocessable()->assertJsonValidationErrors('stock_type');

        // The listing gets options after the order was sent: the goods go to the first option, as cancelled orders do.
        $this->postJson("/api/seller/products/{$this->sofa->id}/variants", ['color' => 'Grey'])->assertOk();
        $grey = ProductVariant::sole();
        $this->assertSame(50, $grey->fresh()->stock_quantity);
        $this->deleteJson("/api/seller/products/{$this->sofa->id}/variants/{$grey->id}")->assertUnprocessable();
        $this->receive($po['id'], [$po['items'][0]['id'] => 10])->assertOk();
        $this->assertSame([60, 60], [$grey->fresh()->stock_quantity, $this->sofa->fresh()->stock_quantity]);

        // Once nothing is on the way, the listing can be deleted.
        $this->deleteJson("/api/seller/products/{$this->sofa->id}")->assertOk();
    }

    public function test_suppliers_are_private_unique_and_kept_once_used(): void
    {
        $id = $this->supplier(['contact_person' => 'Kebede', 'phone' => '0911 234 567', 'tin' => '0012345678']);
        $this->postJson('/api/seller/erp/suppliers', ['name' => ' merkato wood '])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/seller/erp/suppliers', ['name' => 'X', 'phone' => 'call me'])->assertUnprocessable()->assertJsonValidationErrors(['name', 'phone']);
        $spare = $this->supplier(['name' => 'Spare']);
        $this->deleteJson("/api/seller/erp/suppliers/{$spare}")->assertOk();
        $this->assertDatabaseMissing('suppliers', ['id' => $spare]);

        $this->order($id);
        // The order carries the supplier's full details, for printing.
        $this->getJson('/api/seller/erp/purchases/'.PurchaseOrder::sole()->id)->assertOk()
            ->assertJsonPath('data.supplier.contact_person', 'Kebede')->assertJsonPath('data.supplier.tin', '0012345678');
        $this->deleteJson("/api/seller/erp/suppliers/{$id}")->assertUnprocessable();
        $this->putJson("/api/seller/erp/suppliers/{$id}", ['name' => 'Merkato Wood', 'is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->postJson('/api/seller/erp/purchases', ['supplier_id' => $id, 'items' => [['product_id' => $this->sofa->id, 'quantity' => 1, 'unit_cost' => '1']]])
            ->assertUnprocessable()->assertJsonValidationErrors('supplier_id');
        $this->postJson('/api/seller/erp/purchases/'.PurchaseOrder::sole()->id.'/order')->assertUnprocessable()->assertJsonValidationErrors('supplier_id');
        $this->getJson('/api/seller/erp/suppliers?status=inactive')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/seller/erp/suppliers?q=kebede')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/seller/erp/suppliers?q=%25')->assertOk()->assertJsonCount(0, 'data');
        // Names only, inactive ones included, for pickers.
        $this->getJson('/api/seller/erp/suppliers?brief=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_active', false)->assertJsonMissingPath('data.0.owed_santim');

        // The usual supplier of a listing, set from inventory.
        $this->putJson("/api/seller/erp/inventory/{$this->sofa->id}", ['supplier_id' => $id])->assertOk()->assertJsonPath('data.supplier.name', 'Merkato Wood');
        $this->getJson("/api/seller/erp/inventory?supplier_id={$id}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/seller/erp/suppliers/{$id}")->assertOk()->assertJsonPath('data.products.0.preferred', true);
    }

    public function test_another_store_customers_and_suspended_stores_are_kept_out(): void
    {
        $supplier = $this->supplier();
        $po = $this->sent($supplier);
        $this->postJson("/api/seller/erp/suppliers/{$supplier}/payments", ['purchase_order_id' => $po['id'], 'amount' => '10', 'method' => 'cash'])->assertCreated();
        $payment = SupplierPayment::sole()->id;

        $other = $this->approvedSeller();
        $this->as($other->user);
        $this->getJson('/api/seller/erp/suppliers')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/seller/erp/purchases')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/seller/erp/suppliers/{$supplier}")->assertNotFound();
        $this->putJson("/api/seller/erp/suppliers/{$supplier}", ['name' => 'Mine now'])->assertNotFound();
        $this->deleteJson("/api/seller/erp/suppliers/{$supplier}")->assertNotFound();
        $this->postJson("/api/seller/erp/suppliers/{$supplier}/payments", ['amount' => '1', 'method' => 'cash'])->assertNotFound();
        $this->postJson("/api/seller/erp/suppliers/{$supplier}/invoices", ['invoice_number' => 'X', 'amount' => '1'])->assertNotFound();
        $this->postJson("/api/seller/erp/supplier-payments/{$payment}/void", ['reason' => 'mine'])->assertNotFound();
        $this->getJson("/api/seller/erp/purchases/{$po['id']}")->assertNotFound();
        $this->putJson("/api/seller/erp/purchases/{$po['id']}", ['notes' => 'x'])->assertNotFound();
        $this->postJson("/api/seller/erp/purchases/{$po['id']}/receive", ['items' => [['item_id' => $po['items'][0]['id'], 'quantity' => 1]]])->assertNotFound();
        $this->postJson("/api/seller/erp/purchases/{$po['id']}/cancel", ['reason' => 'mine'])->assertNotFound();
        $this->postJson("/api/seller/erp/purchases/{$po['id']}/order")->assertNotFound();
        $this->assertSame(50, $this->sofa->fresh()->stock_quantity);

        $this->as($this->buyer)->getJson('/api/seller/erp/suppliers')->assertForbidden();
        $this->as($this->admin())->getJson('/api/seller/erp/purchases')->assertForbidden();
        $this->postJson('/api/seller/erp/suppliers', ['name' => 'Admin'])->assertForbidden();

        $this->seller->forceFill(['status' => SellerStatus::Suspended])->save();
        $this->as($this->seller->user)->getJson('/api/seller/erp/suppliers')->assertOk();
        $this->getJson("/api/seller/erp/purchases/{$po['id']}")->assertOk();
        $this->postJson('/api/seller/erp/suppliers', ['name' => 'New'])->assertForbidden();
        $this->postJson("/api/seller/erp/purchases/{$po['id']}/receive", ['items' => [['item_id' => $po['items'][0]['id'], 'quantity' => 1]]])->assertForbidden();
    }

    public function test_overview_and_lists_show_what_is_open_late_and_owed(): void
    {
        $supplier = $this->supplier();
        $late = $this->sent($supplier, ['order_date' => now(config('marketplace.timezone'))->subDays(10)->toDateString(), 'expected_date' => now(config('marketplace.timezone'))->subDays(2)->toDateString()]);
        $this->sent($supplier);
        $this->order($supplier);
        $this->receive($late['id'], [$late['items'][0]['id'] => 2])->assertOk();

        $this->getJson('/api/seller/erp/purchases?status=late')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.late', true)
            ->assertJsonPath('meta.counts.open', 2)->assertJsonPath('meta.counts.draft', 1)->assertJsonPath('meta.counts.late', 1);
        $this->getJson('/api/seller/erp/purchases?status=draft')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/seller/erp/purchases?q=00002')->assertOk()->assertJsonCount(1, 'data');

        $p = $this->getJson('/api/seller/erp/overview?period=7d')->assertOk()->json('data.purchasing');
        $this->assertSame([2, 1, 1_020_000, 1_020_000, 2], [$p['open_orders'], $p['late_orders'], $p['owed_santim'], $p['received_value_santim'], $p['received_units']]);
        $this->getJson('/api/seller/summary')->assertOk()->assertJsonPath('data.erp.late_purchases', 1);
        $this->getJson('/api/seller/erp/suppliers?owing=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.owed_santim', 1_020_000);
    }
}
