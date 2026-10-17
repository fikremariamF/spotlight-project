<?php

namespace App\Services;

use App\Enums\PurchaseOrderStatus;
use App\Enums\StockType;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptItem;
use App\Models\SellerProfile;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Erp\StoreLock;
use App\Support\Erp\SupplierBalances;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Buying stock: purchase orders, receiving goods, supplier invoices and payments.
 *
 * Stock changes only when goods are received, through InventoryService, under the purchase order's
 * row lock, and never past the ordered quantity, so receiving twice cannot add stock twice. A form
 * can also send a request id: a repeated submit returns the first receipt or payment.
 *
 * Lock order: the store's ERP lock, then the purchase order, then listings (by id). Invoices and
 * payments lock the supplier, then the purchase order.
 */
class PurchasingService
{
    public const MAX_LINES = 100;

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Create a draft, or replace a draft's details and lines. Totals are always worked out here.
     *
     * @param  array<string, mixed>  $data  validated by the controller
     */
    public function saveDraft(SellerProfile $store, ?int $orderId, array $data, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($store, $orderId, $data, $actor) {
            StoreLock::take($store->id);
            $po = $orderId ? $this->find($store->id, $orderId) : new PurchaseOrder;
            if ($po->exists && $po->status !== PurchaseOrderStatus::Draft) {
                throw ValidationException::withMessages(['status' => 'Only a draft can be changed line by line. For a sent order you can change the expected date, reference and notes.']);
            }
            $supplier = $this->supplier($store->id, (int) $data['supplier_id']);
            if (! $supplier->is_active && $po->supplier_id !== $supplier->id) {
                throw ValidationException::withMessages(['supplier_id' => 'This supplier is marked inactive. Reactivate it first.']);
            }
            $warehouse = $this->warehouse($store->id, $data['warehouse_id'] ?? null);
            $lines = $this->lines($store->id, $data['items'] ?? []);

            $subtotal = array_sum(array_column($lines, 'line_total_santim'));
            $discount = Money::toSantim($data['discount'] ?? '0');
            $charges = Money::toSantim($data['charges'] ?? '0');
            if ($discount > $subtotal) {
                throw ValidationException::withMessages(['discount' => 'The discount cannot be more than the items total.']);
            }

            if (! $po->exists) {
                $po->seller_profile_id = $store->id;
                $po->sequence = StoreLock::next(PurchaseOrder::class, $store->id);
                $po->number = StoreLock::number('PO', $po->sequence);
                $po->status = PurchaseOrderStatus::Draft;
                $po->created_by = $actor->id;
            }
            $today = CarbonImmutable::now(config('marketplace.timezone'))->toDateString();
            $orderDate = $data['order_date'] ?? $today;
            if ($orderDate > $today) {
                throw ValidationException::withMessages(['order_date' => 'The order date cannot be in the future.']);
            }
            if (isset($data['expected_date']) && $data['expected_date'] < $orderDate) {
                throw ValidationException::withMessages(['expected_date' => 'The expected date cannot be before the order date.']);
            }
            $po->supplier_id = $supplier->id;
            $po->warehouse_id = $warehouse->id;
            $po->order_date = $orderDate;
            $po->expected_date = $data['expected_date'] ?? null;
            $po->supplier_reference = $data['supplier_reference'] ?? null;
            $po->charges_note = $data['charges_note'] ?? null;
            $po->notes = $data['notes'] ?? null;
            $po->subtotal_santim = $subtotal;
            $po->discount_santim = $discount;
            $po->charges_santim = $charges;
            $po->total_santim = $subtotal - $discount + $charges;
            $created = ! $po->exists;
            $po->save();

            // A draft has had no effect yet, so its lines are simply rewritten.
            PurchaseOrderItem::where('purchase_order_id', $po->id)->delete();
            foreach ($lines as $i => $line) {
                $item = new PurchaseOrderItem;
                $item->forceFill($line + ['purchase_order_id' => $po->id, 'sort_order' => $i]);
                $item->save();
            }

            $this->audit->log($created ? 'erp.purchase_created' : 'erp.purchase_updated', $po, [
                'number' => $po->number, 'supplier_id' => $supplier->id, 'lines' => count($lines), 'total_santim' => $po->total_santim,
            ]);

            return $po;
        });
    }

    /** Expected date, supplier reference and notes of a sent or received order. */
    public function updateDetails(SellerProfile $store, int $orderId, array $data, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($store, $orderId, $data) {
            $po = $this->find($store->id, $orderId);
            if ($po->status === PurchaseOrderStatus::Cancelled) {
                throw ValidationException::withMessages(['status' => 'This order was cancelled.']);
            }
            if (! empty($data['expected_date']) && $data['expected_date'] < $po->order_date->toDateString()) {
                throw ValidationException::withMessages(['expected_date' => 'The expected date cannot be before the order date.']);
            }
            foreach (['expected_date', 'supplier_reference', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $po->{$field} = $data[$field];
                }
            }
            if ($po->isDirty()) {
                $fields = array_keys($po->getDirty());
                $po->save();
                $this->audit->log('erp.purchase_updated', $po, ['number' => $po->number, 'fields' => $fields]);
            }

            return $po;
        });
    }

    /** The draft was sent to the supplier: its lines and prices are now fixed. */
    public function markOrdered(SellerProfile $store, int $orderId, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($store, $orderId) {
            $po = $this->find($store->id, $orderId);
            if ($po->status !== PurchaseOrderStatus::Draft) {
                throw ValidationException::withMessages(['status' => 'This order was already sent.']);
            }
            if (! $this->supplier($store->id, $po->supplier_id)->is_active) {
                throw ValidationException::withMessages(['supplier_id' => 'This supplier is marked inactive. Reactivate it or choose another.']);
            }
            if (! Warehouse::whereKey($po->warehouse_id)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['warehouse_id' => 'The delivery location is closed. Choose another one.']);
            }
            $items = $po->items()->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'Add at least one item.']);
            }
            // The listings may have changed since the draft was written.
            $products = Product::whereIn('id', $items->pluck('product_id'))->with('variants')->get()->keyBy('id');
            foreach ($items as $item) {
                $p = $products[$item->product_id] ?? null;
                $problem = match (true) {
                    $p === null => 'was deleted. Remove it from the order.',
                    $p->stock_type !== StockType::InStock => 'is now made to order, so it does not keep stock.',
                    $item->variant_id === null && $p->variants->isNotEmpty() => 'now has options. Edit the order and choose one.',
                    $item->variant_id !== null && ! $p->variants->contains('id', $item->variant_id) => 'no longer has the option on this order. Edit the order.',
                    default => null,
                };
                if ($problem) {
                    throw ValidationException::withMessages(['items' => "{$item->description} {$problem}"]);
                }
            }

            $po->status = PurchaseOrderStatus::Ordered;
            $po->ordered_at = now();
            $po->save();
            $this->audit->log('erp.purchase_ordered', $po, ['number' => $po->number, 'total_santim' => $po->total_santim]);

            return $po;
        });
    }

    /** Cancel a draft or a sent order that has received nothing and has no invoices or payments. */
    public function cancel(SellerProfile $store, int $orderId, string $reason, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($store, $orderId, $reason) {
            $po = $this->find($store->id, $orderId);
            if (! in_array($po->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Ordered], true)) {
                throw ValidationException::withMessages(['status' => match ($po->status) {
                    PurchaseOrderStatus::Cancelled => 'This order is already cancelled.',
                    PurchaseOrderStatus::PartiallyReceived => 'Some goods were received. Use "Stop waiting for the rest" instead.',
                    default => 'Received orders cannot be cancelled.',
                }]);
            }
            if (SupplierPayment::where('purchase_order_id', $po->id)->where('status', 'recorded')->exists()) {
                throw ValidationException::withMessages(['status' => 'A payment was recorded for this order. Void it first if the supplier gave the money back.']);
            }
            if (SupplierInvoice::where('purchase_order_id', $po->id)->where('status', 'recorded')->exists()) {
                throw ValidationException::withMessages(['status' => 'An invoice was recorded for this order. Void it first.']);
            }
            $po->status = PurchaseOrderStatus::Cancelled;
            $po->cancelled_at = now();
            $po->cancel_reason = $reason;
            $po->save();
            $this->audit->log('erp.purchase_cancelled', $po, ['number' => $po->number]);

            return $po;
        });
    }

    /** Part of the order arrived and the rest is not coming: the order counts as received. */
    public function closeShort(SellerProfile $store, int $orderId, string $reason, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($store, $orderId, $reason) {
            $po = $this->find($store->id, $orderId);
            if ($po->status !== PurchaseOrderStatus::PartiallyReceived) {
                throw ValidationException::withMessages(['status' => $po->status === PurchaseOrderStatus::Ordered
                    ? 'Nothing was received yet. Cancel the order instead.'
                    : 'Only a partly received order can be closed.']);
            }
            $po->status = PurchaseOrderStatus::Received;
            $po->closed_short = true;
            $po->received_at = now();
            $po->cancel_reason = $reason;
            $po->save();
            $this->audit->log('erp.purchase_closed', $po, ['number' => $po->number]);

            return $po;
        });
    }

    /**
     * Goods arrived. Adds the received units at the chosen location, never more than is still
     * expected per line, and updates each item's cost price to the weighted average when the store
     * keeps that setting on.
     *
     * @param  array{items: list<array{item_id: int, quantity: int}>, warehouse_id?: int|null, received_on?: string|null, note?: string|null, request_id?: string|null}  $data
     */
    public function receive(SellerProfile $store, int $orderId, array $data, User $actor): PurchaseReceipt
    {
        return DB::transaction(function () use ($store, $orderId, $data, $actor) {
            $settings = StoreLock::take($store->id);
            if (! empty($data['request_id'])) {
                $earlier = PurchaseReceipt::where('seller_profile_id', $store->id)->where('request_id', $data['request_id'])->first();
                if ($earlier) {
                    if ($earlier->purchase_order_id !== $orderId) {
                        throw ValidationException::withMessages(['items' => 'This form was already used for another order. Reload the page.']);
                    }

                    return $earlier;
                }
            }

            $po = $this->find($store->id, $orderId);
            if (! $po->status->isOpen()) {
                throw ValidationException::withMessages(['status' => match ($po->status) {
                    PurchaseOrderStatus::Draft => 'Mark the order as sent to the supplier before receiving goods.',
                    PurchaseOrderStatus::Cancelled => 'This order was cancelled.',
                    default => 'Everything on this order was already received.',
                }]);
            }
            $warehouse = $this->warehouse($store->id, $data['warehouse_id'] ?? $po->warehouse_id);
            $today = CarbonImmutable::now(config('marketplace.timezone'))->toDateString();
            $receivedOn = $data['received_on'] ?? $today;
            if ($receivedOn > $today || $receivedOn < $po->order_date->toDateString()) {
                throw ValidationException::withMessages(['received_on' => 'Use a date between the order date and today.']);
            }

            $items = $po->items()->get()->keyBy('id');
            $wanted = [];
            foreach ($data['items'] as $i => $line) {
                $item = $items[(int) $line['item_id']] ?? throw ValidationException::withMessages(["items.{$i}.item_id" => 'This line is not on the order.']);
                if (isset($wanted[$item->id])) {
                    throw ValidationException::withMessages(["items.{$i}.item_id" => "{$item->description} is listed twice."]);
                }
                $quantity = (int) $line['quantity'];
                if ($quantity > $item->outstanding()) {
                    throw ValidationException::withMessages(["items.{$i}.quantity" => $item->outstanding() === 0
                        ? "{$item->description} was already received in full."
                        : "Only {$item->outstanding()} of {$item->description} are still expected."]);
                }
                if ($quantity > 0) {
                    $wanted[$item->id] = $quantity;
                }
            }
            if (! $wanted) {
                throw ValidationException::withMessages(['items' => 'Enter how many arrived for at least one item.']);
            }

            // Listings are locked in id order, as checkout does, before any stock changes.
            $productIds = collect(array_keys($wanted))->map(fn ($id) => $items[$id]->product_id)->unique()->sort()->values();
            $products = Product::withTrashed()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $landed = self::landedUnitCosts($po, $items);

            $receipt = new PurchaseReceipt;
            $receipt->seller_profile_id = $store->id;
            $receipt->purchase_order_id = $po->id;
            $receipt->sequence = StoreLock::next(PurchaseReceipt::class, $store->id);
            $receipt->number = StoreLock::number('GRN', $receipt->sequence);
            $receipt->warehouse_id = $warehouse->id;
            $receipt->received_on = $receivedOn;
            $receipt->note = $data['note'] ?? null;
            $receipt->request_id = $data['request_id'] ?? null;
            $receipt->created_by = $actor->id;
            $receipt->save();

            $units = 0;
            foreach ($wanted as $itemId => $quantity) {
                $item = $items[$itemId];
                $product = $products[$item->product_id] ?? null;
                if (! $product || $product->trashed() || $product->seller_profile_id !== $store->id) {
                    throw ValidationException::withMessages(['items' => "{$item->description} was deleted, so it cannot take stock. Leave it out and stop waiting for it."]);
                }
                if ($product->stock_type !== StockType::InStock) {
                    throw ValidationException::withMessages(['items' => "{$product->name} is made to order now, so it does not keep stock. Switch it to in stock to receive it."]);
                }
                $variant = $this->inventory->inboundVariant($product, $item->variant_id, $item->description);
                $before = $this->inventory->available($product, $variant);
                $unit = $landed[$itemId];

                $this->inventory->change($product, $variant, $warehouse, $quantity, 'purchase_received', $actor,
                    reference: ['type' => 'purchase_receipt', 'id' => $receipt->id], note: "{$po->number}, {$receipt->number}");
                if ($settings->cost_from_purchases) {
                    $this->averageCost($product, $variant, $before, $quantity, $unit);
                }

                $line = new PurchaseReceiptItem;
                $line->purchase_receipt_id = $receipt->id;
                $line->purchase_order_item_id = $item->id;
                $line->product_id = $product->id;
                $line->variant_id = $variant?->id;
                $line->quantity = $quantity;
                $line->unit_cost_santim = $unit;
                $line->save();

                $item->received_quantity += $quantity;
                $item->save();
                $units += $quantity;
            }

            $complete = $items->every(fn (PurchaseOrderItem $i) => $i->outstanding() === 0);
            $po->status = $complete ? PurchaseOrderStatus::Received : PurchaseOrderStatus::PartiallyReceived;
            $po->received_at = $complete ? now() : null;
            $po->save();

            $this->audit->log('erp.purchase_received', $po, [
                'number' => $po->number, 'receipt' => $receipt->number, 'receipt_id' => $receipt->id,
                'warehouse_id' => $warehouse->id, 'units' => $units,
            ]);

            return $receipt;
        });
    }

    /**
     * Cost per unit of each line including its share of the order's charges and discount, shared by
     * line value (by quantity when every line is free). Used for receipts and the new cost price.
     *
     * @param  Collection<int, PurchaseOrderItem>  $items
     * @return array<int, int> item id => santim per unit
     */
    public static function landedUnitCosts(PurchaseOrder $po, Collection $items): array
    {
        $adjust = $po->charges_santim - $po->discount_santim;
        $subtotal = $items->sum('line_total_santim');
        $units = max(1, $items->sum('quantity'));
        $costs = [];
        foreach ($items as $item) {
            $weight = $subtotal > 0 ? $item->line_total_santim / $subtotal : $item->quantity / $units;
            $line = max(0, $item->line_total_santim + (int) round($adjust * $weight));
            $costs[$item->id] = (int) round($line / max(1, $item->quantity));
        }

        return $costs;
    }

    /**
     * Record a supplier's invoice (bill). Its number must not already be recorded for that supplier.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordInvoice(SellerProfile $store, int $supplierId, array $data, User $actor): SupplierInvoice
    {
        return DB::transaction(function () use ($store, $supplierId, $data, $actor) {
            $supplier = $this->supplier($store->id, $supplierId, lock: true);
            $po = $this->orderOf($supplier, $data['purchase_order_id'] ?? null, 'purchase_order_id');

            $number = trim($data['invoice_number']);
            $earlier = SupplierInvoice::where('supplier_id', $supplier->id)->where('status', 'recorded')
                ->whereRaw('LOWER(invoice_number) = ?', [mb_strtolower($number)])->first();
            if ($earlier) {
                throw ValidationException::withMessages(['invoice_number' => "Invoice {$earlier->invoice_number} from this supplier is already recorded (dated {$earlier->invoice_date->toDateString()})."]);
            }
            $today = CarbonImmutable::now(config('marketplace.timezone'))->toDateString();
            $date = $data['invoice_date'] ?? $today;
            if ($date > $today) {
                throw ValidationException::withMessages(['invoice_date' => 'The invoice date cannot be in the future.']);
            }
            $due = $data['due_date'] ?? ($supplier->payment_days !== null ? CarbonImmutable::parse($date)->addDays($supplier->payment_days)->toDateString() : null);
            if ($due !== null && $due < $date) {
                throw ValidationException::withMessages(['due_date' => 'The due date cannot be before the invoice date.']);
            }
            $amount = Money::toSantim($data['amount']);
            if ($amount < 1) {
                throw ValidationException::withMessages(['amount' => 'Enter the invoice amount.']);
            }

            $invoice = new SupplierInvoice;
            $invoice->seller_profile_id = $store->id;
            $invoice->supplier_id = $supplier->id;
            $invoice->purchase_order_id = $po?->id;
            $invoice->invoice_number = $number;
            $invoice->invoice_date = $date;
            $invoice->due_date = $due;
            $invoice->amount_santim = $amount;
            $invoice->note = $data['note'] ?? null;
            $invoice->status = 'recorded';
            $invoice->created_by = $actor->id;
            $invoice->save();
            $this->audit->log('erp.invoice_recorded', $invoice, ['supplier_id' => $supplier->id, 'purchase_order_id' => $po?->id, 'amount_santim' => $amount]);

            return $invoice;
        });
    }

    /**
     * Record money paid to a supplier. Against a purchase order it can be a deposit, up to the
     * order's total (or its bill, if larger); otherwise it cannot be more than is owed.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordPayment(SellerProfile $store, int $supplierId, array $data, User $actor): SupplierPayment
    {
        return DB::transaction(function () use ($store, $supplierId, $data, $actor) {
            $supplier = $this->supplier($store->id, $supplierId, lock: true);
            if (! empty($data['request_id'])) {
                $earlier = SupplierPayment::where('seller_profile_id', $store->id)->where('request_id', $data['request_id'])->first();
                if ($earlier) {
                    return $earlier;
                }
            }
            $po = $this->orderOf($supplier, $data['purchase_order_id'] ?? null, 'purchase_order_id');
            $amount = Money::toSantim($data['amount']);
            if ($amount < 1) {
                throw ValidationException::withMessages(['amount' => 'Enter the amount paid.']);
            }
            $today = CarbonImmutable::now(config('marketplace.timezone'))->toDateString();
            $paidOn = $data['paid_on'] ?? $today;
            if ($paidOn > $today) {
                throw ValidationException::withMessages(['paid_on' => 'The payment date cannot be in the future.']);
            }

            $balances = SupplierBalances::forStore($store->id, [$supplier->id]);
            if ($po) {
                $figures = $balances->order($po->id);
                // While goods are still coming a deposit can go up to the order total; after that, only what is owed.
                $room = ($po->status->isOpen() ? max($po->total_santim, $figures['payable_santim']) : $figures['payable_santim']) - $figures['paid_santim'];
                if ($amount > $room) {
                    throw ValidationException::withMessages(['amount' => $room > 0
                        ? 'This is more than is left to pay on '.$po->number.' ('.Money::toBirr($room).' birr).'
                        : $po->number.' is fully paid.']);
                }
            } else {
                $owed = $balances->supplier($supplier->id)['owed_santim'];
                if ($amount > $owed) {
                    throw ValidationException::withMessages(['amount' => $owed > 0
                        ? 'This is more than you owe this supplier ('.Money::toBirr($owed).' birr). To pay a deposit, choose the purchase order.'
                        : 'You owe this supplier nothing right now. To pay a deposit, choose the purchase order.']);
                }
            }

            $payment = new SupplierPayment;
            $payment->seller_profile_id = $store->id;
            $payment->supplier_id = $supplier->id;
            $payment->purchase_order_id = $po?->id;
            $payment->amount_santim = $amount;
            $payment->paid_on = $paidOn;
            $payment->method = $data['method'];
            $payment->reference = $data['reference'] ?? null;
            $payment->note = $data['note'] ?? null;
            $payment->status = 'recorded';
            $payment->request_id = $data['request_id'] ?? null;
            $payment->created_by = $actor->id;
            $payment->save();
            $this->audit->log('erp.payment_recorded', $payment, ['supplier_id' => $supplier->id, 'purchase_order_id' => $po?->id, 'amount_santim' => $amount, 'method' => $payment->method]);

            return $payment;
        });
    }

    public function voidInvoice(SellerProfile $store, int $invoiceId, string $reason, User $actor): SupplierInvoice
    {
        return $this->void(SupplierInvoice::class, 'erp.invoice_voided', $store, $invoiceId, $reason, $actor);
    }

    public function voidPayment(SellerProfile $store, int $paymentId, string $reason, User $actor): SupplierPayment
    {
        return $this->void(SupplierPayment::class, 'erp.payment_voided', $store, $paymentId, $reason, $actor);
    }

    /**
     * @template T of SupplierInvoice|SupplierPayment
     *
     * @param  class-string<T>  $model
     * @return T
     */
    private function void(string $model, string $action, SellerProfile $store, int $id, string $reason, User $actor)
    {
        return DB::transaction(function () use ($model, $action, $store, $id, $reason, $actor) {
            $supplierId = $model::where('seller_profile_id', $store->id)->whereKey($id)->value('supplier_id')
                ?? abort(404);
            $this->supplier($store->id, $supplierId, lock: true);
            $row = $model::where('seller_profile_id', $store->id)->lockForUpdate()->findOrFail($id);
            if ($row->isVoided()) {
                throw ValidationException::withMessages(['reason' => 'This was already voided.']);
            }
            $row->status = 'voided';
            $row->voided_at = now();
            $row->void_reason = $reason;
            $row->voided_by = $actor->id;
            $row->save();
            $this->audit->log($action, $row, ['supplier_id' => $supplierId, 'amount_santim' => $row->amount_santim]);

            return $row;
        });
    }

    /** One of the store's purchase orders, locked. Another store's id answers 404. */
    public function find(int $storeId, int $orderId): PurchaseOrder
    {
        return PurchaseOrder::where('seller_profile_id', $storeId)->lockForUpdate()->findOrFail($orderId);
    }

    private function supplier(int $storeId, int $supplierId, bool $lock = false): Supplier
    {
        $q = Supplier::where('seller_profile_id', $storeId);

        return ($lock ? $q->lockForUpdate() : $q)->find($supplierId)
            ?? throw ValidationException::withMessages(['supplier_id' => 'Choose one of your suppliers.']);
    }

    private function warehouse(int $storeId, ?int $warehouseId): Warehouse
    {
        if (! $warehouseId) {
            return $this->inventory->defaultWarehouse($storeId);
        }
        $warehouse = Warehouse::where('seller_profile_id', $storeId)->find($warehouseId)
            ?? throw ValidationException::withMessages(['warehouse_id' => 'Choose one of your locations.']);
        if (! $warehouse->is_active) {
            throw ValidationException::withMessages(['warehouse_id' => "{$warehouse->name} is closed. Choose another location."]);
        }

        return $warehouse;
    }

    /** A sent order of this supplier, for an invoice or a payment. */
    private function orderOf(Supplier $supplier, ?int $orderId, string $field): ?PurchaseOrder
    {
        if (! $orderId) {
            return null;
        }
        $po = PurchaseOrder::where('seller_profile_id', $supplier->seller_profile_id)->where('supplier_id', $supplier->id)
            ->lockForUpdate()->find($orderId)
            ?? throw ValidationException::withMessages([$field => 'Choose one of this supplier\'s purchase orders.']);
        if (in_array($po->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Cancelled], true)) {
            throw ValidationException::withMessages([$field => $po->status === PurchaseOrderStatus::Draft
                ? "{$po->number} is still a draft. Mark it as sent first."
                : "{$po->number} was cancelled."]);
        }

        return $po;
    }

    /**
     * Validate order lines against the store's listings and work out each line's total.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function lines(int $storeId, array $items): array
    {
        if (count($items) < 1) {
            throw ValidationException::withMessages(['items' => 'Add at least one item.']);
        }
        if (count($items) > self::MAX_LINES) {
            throw ValidationException::withMessages(['items' => 'An order can have up to '.self::MAX_LINES.' lines.']);
        }
        $products = Product::where('seller_profile_id', $storeId)->whereIn('id', array_map(fn ($i) => (int) $i['product_id'], $items))
            ->with('variants')->get()->keyBy('id');

        $seen = [];
        $lines = [];
        foreach (array_values($items) as $i => $item) {
            $p = $products[(int) $item['product_id']] ?? throw ValidationException::withMessages(["items.{$i}.product_id" => 'This is not one of your listings.']);
            if ($p->stock_type !== StockType::InStock) {
                throw ValidationException::withMessages(["items.{$i}.product_id" => "{$p->name} is made to order, so it does not keep stock. Switch it to in stock first."]);
            }
            $variant = null;
            if ($p->variants->isNotEmpty()) {
                $variant = $p->variants->firstWhere('id', (int) ($item['variant_id'] ?? 0))
                    ?? throw ValidationException::withMessages(["items.{$i}.variant_id" => "Choose which option of {$p->name}."]);
            } elseif (! empty($item['variant_id'])) {
                throw ValidationException::withMessages(["items.{$i}.variant_id" => "{$p->name} has no options."]);
            }
            $name = $p->name.($variant && $variant->label() !== '' ? ' ('.$variant->label().')' : '');
            $key = $p->id.':'.($variant?->id ?? 0);
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["items.{$i}.product_id" => "{$name} is on the order twice. Change the quantity instead."]);
            }
            $seen[$key] = true;

            $quantity = (int) $item['quantity'];
            $unit = Money::toSantim($item['unit_cost']);
            $discount = Money::toSantim($item['discount'] ?? '0');
            if ($discount > $quantity * $unit) {
                throw ValidationException::withMessages(["items.{$i}.discount" => 'The discount cannot be more than the line total.']);
            }
            $lines[] = [
                'product_id' => $p->id,
                'variant_id' => $variant?->id,
                'description' => mb_substr($name, 0, 255),
                'quantity' => $quantity,
                'unit_cost_santim' => $unit,
                'discount_santim' => $discount,
                'line_total_santim' => $quantity * $unit - $discount,
            ];
        }

        return $lines;
    }

    /**
     * New cost price = units already available at the old cost plus the units received at their
     * landed cost, averaged. With no known cost or no units left, the new cost is the landed cost.
     */
    private function averageCost(Product $product, ?ProductVariant $variant, int $before, int $quantity, int $unit): void
    {
        $old = InventoryService::unitCost($product, $variant);
        $new = $old === null || $before <= 0 ? $unit : (int) round(($before * $old + $quantity * $unit) / ($before + $quantity));
        if ($variant) {
            if ($variant->cost_price_santim !== $new) {
                ProductVariant::whereKey($variant->id)->update(['cost_price_santim' => $new]);
                $variant->forceFill(['cost_price_santim' => $new])->syncOriginalAttribute('cost_price_santim');
            }

            return;
        }
        if ($product->cost_price_santim !== $new) {
            Product::withTrashed()->whereKey($product->id)->update(['cost_price_santim' => $new]);
            $product->forceFill(['cost_price_santim' => $new])->syncOriginalAttribute('cost_price_santim');
        }
    }
}
