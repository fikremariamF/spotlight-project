<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\StockType;
use App\Models\OfflineSale;
use App\Models\OfflineSaleItem;
use App\Models\OfflineSalePayment;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Erp\SaleFigures;
use App\Support\Erp\StoreLock;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sales outside the marketplace, their customer payments, and returns of those sales and of
 * delivered marketplace orders.
 *
 * Stock changes only through InventoryService. A form can send a request id: a repeated submit
 * returns the first sale, payment or return instead of taking or adding stock twice. Totals are
 * always worked out here from the lines.
 *
 * Marketplace orders are recorded by the main system. A return of one is the store's own record of
 * goods coming back (and of cash it gave back for a cash order): the order, its payment, the
 * commission and the store's Betoch balance are never changed here.
 *
 * Lock order: the store's ERP lock, then the sale or order, then listings by id, as checkout does.
 */
class SalesService
{
    public const MAX_LINES = 100;

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Record a sale: take in-stock units from the chosen location, fix prices and cost prices as
     * they are now, and record what the customer paid at the time.
     *
     * @param  array<string, mixed>  $data  validated by the controller
     */
    public function record(SellerProfile $store, array $data, User $actor): OfflineSale
    {
        return DB::transaction(function () use ($store, $data, $actor) {
            StoreLock::take($store->id);
            if (! empty($data['request_id'])) {
                $earlier = OfflineSale::where('seller_profile_id', $store->id)->where('request_id', $data['request_id'])->first();
                if ($earlier) {
                    return $earlier;
                }
            }
            $warehouse = $this->warehouse($store->id, $data['warehouse_id'] ?? null);
            $today = self::today();
            $saleDate = $data['sale_date'] ?? $today;
            if ($saleDate > $today) {
                throw ValidationException::withMessages(['sale_date' => 'The sale date cannot be in the future.']);
            }
            if (! empty($data['due_date']) && $data['due_date'] < $saleDate) {
                throw ValidationException::withMessages(['due_date' => 'The pay-by date cannot be before the sale date.']);
            }

            $items = array_values($data['items'] ?? []);
            if (count($items) < 1) {
                throw ValidationException::withMessages(['items' => 'Add at least one item.']);
            }
            if (count($items) > self::MAX_LINES) {
                throw ValidationException::withMessages(['items' => 'A sale can have up to '.self::MAX_LINES.' lines.']);
            }
            // Listings are locked in id order before anything is read from them, as checkout does.
            $ids = collect($items)->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->unique()->sort()->values();
            $products = Product::where('seller_profile_id', $store->id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $variants = ProductVariant::whereIn('product_id', $products->keys())->get()->groupBy('product_id');
            $lines = $this->lines($items, $products, $variants);

            $subtotal = array_sum(array_column($lines, 'line_total_santim'));
            $discount = Money::toSantim($data['discount'] ?? '0');
            $delivery = Money::toSantim($data['delivery'] ?? '0');
            if ($discount > $subtotal) {
                throw ValidationException::withMessages(['discount' => 'The discount cannot be more than the items total.']);
            }
            $total = $subtotal - $discount + $delivery;
            $paid = Money::toSantim($data['paid_now'] ?? '0');
            if ($paid > 0 && empty($data['payment_method'])) {
                throw ValidationException::withMessages(['payment_method' => 'Choose how the customer paid.']);
            }
            if ($paid > $total) {
                throw ValidationException::withMessages(['paid_now' => 'The amount paid cannot be more than the total ('.Money::toBirr($total).' birr).']);
            }
            $shares = self::share($discount, array_column($lines, 'line_total_santim'));

            $sale = new OfflineSale;
            $sale->seller_profile_id = $store->id;
            $sale->sequence = StoreLock::next(OfflineSale::class, $store->id);
            $sale->number = StoreLock::number('SL', $sale->sequence);
            $sale->status = 'completed';
            $sale->sale_date = $saleDate;
            $sale->due_date = $data['due_date'] ?? null;
            $sale->warehouse_id = $warehouse->id;
            $sale->customer_name = $data['customer_name'] ?? null;
            $sale->customer_phone = $data['customer_phone'] ?? null;
            $sale->customer_tin = $data['customer_tin'] ?? null;
            $sale->subtotal_santim = $subtotal;
            $sale->discount_santim = $discount;
            $sale->delivery_santim = $delivery;
            $sale->total_santim = $total;
            $sale->note = $data['note'] ?? null;
            $sale->request_id = $data['request_id'] ?? null;
            $sale->created_by = $actor->id;
            $sale->save();

            foreach ($lines as $i => $line) {
                $product = $line['product'];
                $variant = $line['variant'];
                unset($line['product'], $line['variant']);
                if ($line['stock_taken']) {
                    $here = $this->inventory->quantityAt($product, $variant, $warehouse);
                    if ($here < $line['quantity']) {
                        throw ValidationException::withMessages(["items.{$i}.quantity" => $here === 0
                            ? "{$line['description']}: none available at {$warehouse->name}."
                            : "Only {$here} of {$line['description']} available at {$warehouse->name}."]);
                    }
                    $before = $this->inventory->available($product, $variant);
                    $this->inventory->change($product, $variant, $warehouse, -$line['quantity'], 'offline_sale', $actor,
                        reference: ['type' => 'offline_sale', 'id' => $sale->id], note: $sale->number);
                    $this->inventory->afterDecrease($product, $variant, $before);
                }
                $item = new OfflineSaleItem;
                $item->forceFill($line + ['offline_sale_id' => $sale->id, 'net_santim' => $line['line_total_santim'] - $shares[$i], 'sort_order' => $i]);
                $item->save();
            }

            if ($paid > 0) {
                $payment = new OfflineSalePayment;
                $payment->seller_profile_id = $store->id;
                $payment->offline_sale_id = $sale->id;
                $payment->amount_santim = $paid;
                $payment->paid_on = $saleDate;
                $payment->method = $data['payment_method'];
                $payment->reference = $data['payment_reference'] ?? null;
                $payment->status = 'recorded';
                $payment->created_by = $actor->id;
                $payment->save();
            }

            $this->audit->log('erp.sale_recorded', $sale, [
                'number' => $sale->number, 'lines' => count($lines), 'total_santim' => $total, 'paid_santim' => $paid, 'warehouse_id' => $warehouse->id,
            ]);

            return $sale;
        });
    }

    /** Customer details, pay-by date and note. Lines, prices and payments are not changed this way. */
    public function updateDetails(SellerProfile $store, int $saleId, array $data, User $actor): OfflineSale
    {
        return DB::transaction(function () use ($store, $saleId, $data) {
            $sale = $this->sale($store->id, $saleId);
            if ($sale->isVoided()) {
                throw ValidationException::withMessages(['status' => 'This sale was voided.']);
            }
            if (! empty($data['due_date']) && $data['due_date'] < $sale->sale_date->toDateString()) {
                throw ValidationException::withMessages(['due_date' => 'The pay-by date cannot be before the sale date.']);
            }
            foreach (['customer_name', 'customer_phone', 'customer_tin', 'due_date', 'note'] as $field) {
                if (array_key_exists($field, $data)) {
                    $sale->{$field} = $data[$field];
                }
            }
            if ($sale->isDirty()) {
                $fields = array_keys($sale->getDirty());
                $sale->save();
                $this->audit->log('erp.sale_updated', $sale, ['number' => $sale->number, 'fields' => $fields]);
            }

            return $sale;
        });
    }

    /**
     * A sale entered by mistake: its units go back to where they left from (the default location if
     * that one was closed since) and its payments are voided with it. Not once a return was recorded.
     */
    public function voidSale(SellerProfile $store, int $saleId, string $reason, User $actor): OfflineSale
    {
        return DB::transaction(function () use ($store, $saleId, $reason, $actor) {
            StoreLock::take($store->id);
            $sale = $this->sale($store->id, $saleId);
            if ($sale->isVoided()) {
                throw ValidationException::withMessages(['reason' => 'This sale was already voided.']);
            }
            if (SaleReturn::where('offline_sale_id', $sale->id)->where('status', 'recorded')->exists()) {
                throw ValidationException::withMessages(['reason' => 'A return is recorded for this sale. Void the return first.']);
            }
            $items = $sale->items()->where('stock_taken', true)->get();
            $products = Product::withTrashed()->whereIn('id', $items->pluck('product_id')->filter()->unique()->sort()->values())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $warehouse = Warehouse::whereKey($sale->warehouse_id)->where('is_active', true)->first() ?? $this->inventory->defaultWarehouse($store->id);

            foreach ($items as $item) {
                $product = $products[$item->product_id] ?? null;
                // The listing row is gone for good (deleted outright): there is no stock to put back.
                if (! $product) {
                    continue;
                }
                if ($product->stock_type !== StockType::InStock) {
                    throw ValidationException::withMessages(['reason' => "{$product->name} is made to order now, so its units cannot go back into stock. Switch it to in stock first."]);
                }
                $variant = $this->backVariant($product, $item->variant_id);
                $this->inventory->change($product, $variant, $warehouse, $item->quantity, 'offline_sale_voided', $actor,
                    reference: ['type' => 'offline_sale', 'id' => $sale->id], note: "{$sale->number} voided");
            }

            OfflineSalePayment::where('offline_sale_id', $sale->id)->where('status', 'recorded')->update([
                'status' => 'voided', 'voided_at' => now(), 'void_reason' => mb_substr("Sale {$sale->number} voided: {$reason}", 0, 255),
                'voided_by' => $actor->id, 'updated_at' => now(),
            ]);
            $sale->status = 'voided';
            $sale->voided_at = now();
            $sale->void_reason = $reason;
            $sale->voided_by = $actor->id;
            $sale->save();
            $this->audit->log('erp.sale_voided', $sale, ['number' => $sale->number, 'total_santim' => $sale->total_santim]);

            return $sale;
        });
    }

    /** Money the customer paid later (the rest of a deposit sale). Never more than they still owe. */
    public function recordPayment(SellerProfile $store, int $saleId, array $data, User $actor): OfflineSalePayment
    {
        return DB::transaction(function () use ($store, $saleId, $data, $actor) {
            $sale = $this->sale($store->id, $saleId);
            if (! empty($data['request_id'])) {
                $earlier = OfflineSalePayment::where('seller_profile_id', $store->id)->where('request_id', $data['request_id'])->first();
                if ($earlier) {
                    if ($earlier->offline_sale_id !== $sale->id) {
                        throw ValidationException::withMessages(['amount' => 'This form was already used for another sale. Reload the page.']);
                    }

                    return $earlier;
                }
            }
            if ($sale->isVoided()) {
                throw ValidationException::withMessages(['amount' => 'This sale was voided.']);
            }
            $amount = Money::toSantim($data['amount']);
            if ($amount < 1) {
                throw ValidationException::withMessages(['amount' => 'Enter the amount paid.']);
            }
            $paidOn = $data['paid_on'] ?? self::today();
            if ($paidOn > self::today() || $paidOn < $sale->sale_date->toDateString()) {
                throw ValidationException::withMessages(['paid_on' => 'Use a date between the sale date and today.']);
            }
            $owed = SaleFigures::of($sale)['balance_santim'];
            if ($amount > $owed) {
                throw ValidationException::withMessages(['amount' => $owed > 0
                    ? 'This is more than the customer still owes ('.Money::toBirr($owed).' birr).'
                    : "{$sale->number} is fully paid."]);
            }

            $payment = new OfflineSalePayment;
            $payment->seller_profile_id = $store->id;
            $payment->offline_sale_id = $sale->id;
            $payment->amount_santim = $amount;
            $payment->paid_on = $paidOn;
            $payment->method = $data['method'];
            $payment->reference = $data['reference'] ?? null;
            $payment->note = $data['note'] ?? null;
            $payment->status = 'recorded';
            $payment->request_id = $data['request_id'] ?? null;
            $payment->created_by = $actor->id;
            $payment->save();
            $this->audit->log('erp.sale_payment_recorded', $payment, ['offline_sale_id' => $sale->id, 'amount_santim' => $amount, 'method' => $payment->method]);

            return $payment;
        });
    }

    public function voidPayment(SellerProfile $store, int $paymentId, string $reason, User $actor): OfflineSalePayment
    {
        return DB::transaction(function () use ($store, $paymentId, $reason, $actor) {
            $saleId = OfflineSalePayment::where('seller_profile_id', $store->id)->whereKey($paymentId)->value('offline_sale_id') ?? abort(404);
            $sale = $this->sale($store->id, $saleId);
            $payment = OfflineSalePayment::where('seller_profile_id', $store->id)->lockForUpdate()->findOrFail($paymentId);
            if ($payment->isVoided()) {
                throw ValidationException::withMessages(['reason' => 'This payment was already voided.']);
            }
            $m = SaleFigures::of($sale);
            if ($m['refunded_santim'] > $m['paid_santim'] - $payment->amount_santim) {
                throw ValidationException::withMessages(['reason' => 'You gave money back on a return of this sale, more than would be left paid without this payment. Void that return first.']);
            }
            $payment->status = 'voided';
            $payment->voided_at = now();
            $payment->void_reason = $reason;
            $payment->voided_by = $actor->id;
            $payment->save();
            $this->audit->log('erp.sale_payment_voided', $payment, ['offline_sale_id' => $sale->id, 'amount_santim' => $payment->amount_santim]);

            return $payment;
        });
    }

    /**
     * Goods a customer brought back. Each line's credit is its share of what was charged for it
     * (after discounts), so returning every unit credits exactly what the line cost the customer.
     * Restocked units go to the chosen location; the others (damaged) are only recorded.
     *
     * For an offline sale the credit lowers what the customer owes and the store can give back what
     * they paid beyond that. For a marketplace order the store can only record cash it gave back for
     * a cash-on-delivery order; Betoch support refunds online payments.
     *
     * @param  'offline'|'order'  $kind
     * @param  array<string, mixed>  $data  validated by the controller
     */
    public function recordReturn(SellerProfile $store, string $kind, int $id, array $data, User $actor): SaleReturn
    {
        return DB::transaction(function () use ($store, $kind, $id, $data, $actor) {
            StoreLock::take($store->id);
            if (! empty($data['request_id'])) {
                $earlier = SaleReturn::where('seller_profile_id', $store->id)->where('request_id', $data['request_id'])->first();
                if ($earlier) {
                    if (($kind === 'offline' ? $earlier->offline_sale_id : $earlier->order_id) !== $id) {
                        throw ValidationException::withMessages(['items' => 'This form was already used for another sale. Reload the page.']);
                    }

                    return $earlier;
                }
            }

            $sale = $order = null;
            if ($kind === 'offline') {
                $sale = $this->sale($store->id, $id);
                if ($sale->isVoided()) {
                    throw ValidationException::withMessages(['items' => 'This sale was voided.']);
                }
                $lines = $sale->items()->get()->keyBy('id');
                $returned = SaleFigures::returned('offline_sale_item_id', $lines->keys()->all());
                $from = $sale->sale_date->toDateString();
                $money = SaleFigures::of($sale);
                $deliveryLeft = $sale->delivery_santim - $money['delivery_credited_santim'];
            } else {
                $order = Order::where('seller_profile_id', $store->id)->visibleToSeller()->lockForUpdate()->findOrFail($id);
                if ($order->status !== OrderStatus::Delivered) {
                    throw ValidationException::withMessages(['items' => 'Only a delivered order can have a return. To stop an order before delivery, cancel it.']);
                }
                $lines = $order->items()->get()->keyBy('id');
                $returned = SaleFigures::returned('order_item_id', $lines->keys()->all());
                $from = CarbonImmutable::instance($order->delivered_at)->setTimezone(config('marketplace.timezone'))->toDateString();
                $credited = (int) SaleReturn::where('order_id', $order->id)->where('status', 'recorded')->sum('delivery_credit_santim');
                // Online, the customer paid Betoch, so there is no delivery charge for the store to give back.
                $deliveryLeft = $order->payment_method === 'online' ? 0 : (int) $order->delivery_fee_santim - $credited;
            }

            $today = self::today();
            $returnedOn = $data['returned_on'] ?? $today;
            if ($returnedOn > $today || $returnedOn < $from) {
                throw ValidationException::withMessages(['returned_on' => 'Use a date between the '.($sale ? 'sale' : 'delivery').' date and today.']);
            }

            $wanted = [];
            foreach (array_values($data['items'] ?? []) as $i => $row) {
                $line = $lines[(int) $row['item_id']] ?? throw ValidationException::withMessages(["items.{$i}.item_id" => 'This line is not on the '.($sale ? 'sale' : 'order').'.']);
                $name = $sale ? $line->description : $line->name.($line->variant_label ? " ({$line->variant_label})" : '');
                if (isset($wanted[$line->id])) {
                    throw ValidationException::withMessages(["items.{$i}.item_id" => "{$name} is listed twice."]);
                }
                $quantity = (int) $row['quantity'];
                $left = $line->quantity - ($returned[$line->id] ?? 0);
                if ($quantity > $left) {
                    throw ValidationException::withMessages(["items.{$i}.quantity" => $left === 0 ? "All of {$name} was already returned." : "Only {$left} of {$name} can still be returned."]);
                }
                if ($quantity > 0) {
                    $wanted[$line->id] = ['line' => $line, 'name' => $name, 'quantity' => $quantity, 'restock' => (bool) ($row['restock'] ?? false)];
                }
            }
            if (! $wanted) {
                throw ValidationException::withMessages(['items' => 'Enter how many came back for at least one item.']);
            }

            $restocking = array_filter($wanted, fn ($w) => $w['restock']);
            $warehouse = $restocking ? $this->warehouse($store->id, $data['warehouse_id'] ?? null) : $this->inventory->defaultWarehouse($store->id);
            $products = Product::withTrashed()->where('seller_profile_id', $store->id)
                ->whereIn('id', collect($restocking)->map(fn ($w) => $w['line']->product_id)->filter()->unique()->sort()->values())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $goods = 0;
            foreach ($wanted as $lineId => $w) {
                $line = $w['line'];
                $net = $sale ? $line->net_santim : $line->line_total_santim;
                $before = $returned[$lineId] ?? 0;
                $wanted[$lineId]['credit'] = self::portion($net, $before + $w['quantity'], $line->quantity) - self::portion($net, $before, $line->quantity);
                $goods += $wanted[$lineId]['credit'];
                if (! $w['restock']) {
                    continue;
                }
                $product = $products[$line->product_id] ?? null;
                if (! $product || $product->trashed()) {
                    throw ValidationException::withMessages(['items' => $line->product_id
                        ? "{$w['name']} is not one of your listings any more, so it cannot go back into stock. Untick \"back in stock\" for it."
                        : "{$w['name']} is not a stock item, so it cannot go back into stock. Untick \"back in stock\" for it."]);
                }
                if ($product->stock_type !== StockType::InStock) {
                    throw ValidationException::withMessages(['items' => "{$product->name} is made to order, so it does not keep stock. Untick \"back in stock\" for it, or switch the listing to in stock first."]);
                }
                $wanted[$lineId]['product'] = $product;
                $wanted[$lineId]['variant'] = $line->variant_id
                    ? (ProductVariant::where('product_id', $product->id)->find($line->variant_id)
                        ?? throw ValidationException::withMessages(['items' => "The option of {$w['name']} was removed, so it cannot go back into stock. Untick \"back in stock\" for it."]))
                    : $this->inventory->inboundVariant($product, null, $w['name']);
            }

            $deliveryCredit = Money::toSantim($data['delivery_credit'] ?? '0');
            if ($deliveryCredit > max(0, $deliveryLeft)) {
                throw ValidationException::withMessages(['delivery_credit' => $deliveryLeft > 0
                    ? 'Up to '.Money::toBirr($deliveryLeft).' birr of the delivery charge can be credited.'
                    : 'There is no delivery charge left to credit.']);
            }
            $credit = $goods + $deliveryCredit;
            $refund = Money::toSantim($data['refund'] ?? '0');
            if ($refund > 0 && empty($data['refund_method'])) {
                throw ValidationException::withMessages(['refund_method' => 'Choose how the money was given back.']);
            }
            if ($order && $order->payment_method === 'online' && $refund > 0) {
                $name = config('marketplace.name');
                throw ValidationException::withMessages(['refund' => "This order was paid online through {$name}, so {$name} support refunds the customer. Record the return without money given back."]);
            }
            if ($sale) {
                // What they paid beyond what they owe once this return is credited.
                $over = ($money['paid_santim'] - $money['refunded_santim']) - ($sale->total_santim - $money['credited_santim'] - $credit);
                $room = max(0, min($credit, $over));
                if ($refund > $room) {
                    throw ValidationException::withMessages(['refund' => $room > 0
                        ? 'You can give back up to '.Money::toBirr($room).' birr: what the customer paid beyond what they owe after this return.'
                        : 'The customer has not paid more than they owe after this return, so there is nothing to give back. The return lowers what they owe.']);
                }
            } elseif ($refund > $credit) {
                throw ValidationException::withMessages(['refund' => 'You can give back up to '.Money::toBirr($credit).' birr for these items.']);
            }

            $return = new SaleReturn;
            $return->seller_profile_id = $store->id;
            $return->sequence = StoreLock::next(SaleReturn::class, $store->id);
            $return->number = StoreLock::number('RT', $return->sequence);
            $return->offline_sale_id = $sale?->id;
            $return->order_id = $order?->id;
            $return->status = 'recorded';
            $return->returned_on = $returnedOn;
            $return->warehouse_id = $warehouse->id;
            $return->goods_credit_santim = $goods;
            $return->delivery_credit_santim = $deliveryCredit;
            $return->refund_santim = $refund;
            $return->refund_method = $refund > 0 ? $data['refund_method'] : null;
            $return->reason = $data['reason'];
            $return->request_id = $data['request_id'] ?? null;
            $return->created_by = $actor->id;
            $return->save();

            $of = $sale ? $sale->number : "order {$order->number}";
            $units = 0;
            foreach ($wanted as $w) {
                $line = $w['line'];
                $variant = $w['variant'] ?? null;
                if ($w['restock']) {
                    $this->inventory->change($w['product'], $variant, $warehouse, $w['quantity'], 'sale_returned', $actor, order: $order,
                        reference: $sale ? ['type' => 'offline_sale', 'id' => $sale->id] : ['type' => 'sale_return', 'id' => $return->id],
                        note: "{$return->number}, {$of}");
                }
                $item = new SaleReturnItem;
                $item->sale_return_id = $return->id;
                $item->offline_sale_item_id = $sale ? $line->id : null;
                $item->order_item_id = $order ? $line->id : null;
                $item->product_id = $line->product_id;
                // The option the units went back to; for a line that is not restocked, the one it was sold as.
                $item->variant_id = $w['restock'] ? $variant?->id : $line->variant_id;
                $item->description = mb_substr($w['name'], 0, 255);
                $item->quantity = $w['quantity'];
                $item->restocked = $w['restock'];
                $item->credit_santim = $w['credit'];
                $item->unit_cost_santim = $line->unit_cost_santim;
                $item->save();
                $units += $w['quantity'];
            }

            $this->audit->log('erp.sale_returned', $return, [
                'number' => $return->number, 'offline_sale_id' => $sale?->id, 'order_id' => $order?->id, 'units' => $units,
                'credit_santim' => $credit, 'refund_santim' => $refund,
            ]);

            return $return;
        });
    }

    /**
     * A return recorded by mistake: restocked units come out again (they must still be at that
     * location) and the credit and any money given back no longer count.
     */
    public function voidReturn(SellerProfile $store, int $returnId, string $reason, User $actor): SaleReturn
    {
        return DB::transaction(function () use ($store, $returnId, $reason, $actor) {
            StoreLock::take($store->id);
            $return = SaleReturn::where('seller_profile_id', $store->id)->lockForUpdate()->findOrFail($returnId);
            if ($return->isVoided()) {
                throw ValidationException::withMessages(['reason' => 'This return was already voided.']);
            }
            $order = null;
            if ($return->offline_sale_id) {
                $this->sale($store->id, $return->offline_sale_id);
            } else {
                $order = Order::whereKey($return->order_id)->lockForUpdate()->first();
            }
            $items = $return->items()->where('restocked', true)->get();
            $products = Product::withTrashed()->whereIn('id', $items->pluck('product_id')->filter()->unique()->sort()->values())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $warehouse = Warehouse::findOrFail($return->warehouse_id);

            foreach ($items as $item) {
                $product = $products[$item->product_id] ?? null;
                if (! $product) {
                    continue;
                }
                $variant = $item->variant_id ? ProductVariant::withTrashed()->where('product_id', $product->id)->find($item->variant_id) : null;
                $here = $this->inventory->quantityAt($product, $variant, $warehouse);
                if ($here < $item->quantity) {
                    throw ValidationException::withMessages(['reason' => "Only {$here} of {$item->description} are at {$warehouse->name} now, so this return cannot be undone. Move the units back there, or count the stock first."]);
                }
                $before = $this->inventory->available($product, $variant);
                $this->inventory->change($product, $variant, $warehouse, -$item->quantity, 'sale_return_voided', $actor,
                    order: $order,
                    reference: $return->offline_sale_id ? ['type' => 'offline_sale', 'id' => $return->offline_sale_id] : ['type' => 'sale_return', 'id' => $return->id],
                    note: "{$return->number} voided");
                $this->inventory->afterDecrease($product, $variant, $before);
            }

            $return->status = 'voided';
            $return->voided_at = now();
            $return->void_reason = $reason;
            $return->voided_by = $actor->id;
            $return->save();
            $this->audit->log('erp.sale_return_voided', $return, ['number' => $return->number, 'refund_santim' => $return->refund_santim]);

            return $return;
        });
    }

    /** One of the store's offline sales, locked. Another store's id answers 404. */
    public function sale(int $storeId, int $saleId): OfflineSale
    {
        return OfflineSale::where('seller_profile_id', $storeId)->lockForUpdate()->findOrFail($saleId);
    }

    /**
     * Share $amount over lines in proportion to $weights, in whole santim that add up exactly
     * (largest remainder). Used for a discount on the whole sale.
     *
     * @param  list<int>  $weights
     * @return list<int>
     */
    public static function share(int $amount, array $weights): array
    {
        $sum = array_sum($weights);
        if ($amount === 0 || $sum === 0) {
            return array_fill(0, count($weights), 0);
        }
        $shares = [];
        $rest = [];
        foreach ($weights as $i => $w) {
            // Floats only to rank the remainders; the shares themselves are whole santim.
            $exact = $amount * ($w / $sum);
            $shares[$i] = min($w, (int) floor($exact));
            $rest[$i] = $exact - $shares[$i];
        }
        arsort($rest);
        $left = $amount - array_sum($shares);
        foreach (array_keys($rest) as $i) {
            if ($left <= 0) {
                break;
            }
            if ($shares[$i] < $weights[$i]) {
                $shares[$i]++;
                $left--;
            }
        }

        return $shares;
    }

    /** round($total * $part / $of), half up, in integers: $part and $of are unit counts. */
    public static function portion(int $total, int $part, int $of): int
    {
        return intdiv($total, $of) * $part + intdiv(2 * ($total % $of) * $part + $of, 2 * $of);
    }

    /**
     * Check the lines against the store's (locked) listings and work out each line's total.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function lines(array $items, $products, $variants): array
    {
        $seen = [];
        $lines = [];
        foreach ($items as $i => $item) {
            $quantity = (int) $item['quantity'];
            $unit = Money::toSantim($item['unit_price']);
            $discount = Money::toSantim($item['discount'] ?? '0');
            if ($discount > $quantity * $unit) {
                throw ValidationException::withMessages(["items.{$i}.discount" => 'The discount cannot be more than the line total.']);
            }
            $product = $variant = null;
            if (! empty($item['product_id'])) {
                $product = $products[(int) $item['product_id']] ?? throw ValidationException::withMessages(["items.{$i}.product_id" => 'This is not one of your listings.']);
                $options = $variants[$product->id] ?? collect();
                if ($options->isNotEmpty()) {
                    $variant = $options->firstWhere('id', (int) ($item['variant_id'] ?? 0))
                        ?? throw ValidationException::withMessages(["items.{$i}.variant_id" => "Choose which option of {$product->name}."]);
                } elseif (! empty($item['variant_id'])) {
                    throw ValidationException::withMessages(["items.{$i}.variant_id" => "{$product->name} has no options."]);
                }
                $name = $product->name.($variant && $variant->label() !== '' ? ' ('.$variant->label().')' : '');
                $key = $product->id.':'.($variant?->id ?? 0);
                if (isset($seen[$key])) {
                    throw ValidationException::withMessages(["items.{$i}.product_id" => "{$name} is on the sale twice. Change the quantity instead."]);
                }
                $seen[$key] = true;
            } else {
                $name = trim((string) ($item['description'] ?? ''));
                if ($name === '') {
                    throw ValidationException::withMessages(["items.{$i}.description" => 'Describe what was sold.']);
                }
            }
            $lines[] = [
                'product' => $product,
                'variant' => $variant,
                'product_id' => $product?->id,
                'variant_id' => $variant?->id,
                'description' => mb_substr($name, 0, 255),
                'quantity' => $quantity,
                'unit_price_santim' => $unit,
                'discount_santim' => $discount,
                'line_total_santim' => $quantity * $unit - $discount,
                'unit_cost_santim' => $product ? InventoryService::unitCost($product, $variant) : null,
                'stock_taken' => $product !== null && $product->stock_type === StockType::InStock,
            ];
        }

        return $lines;
    }

    /**
     * The option units of a voided sale go back to: the one sold (even if removed since, as a
     * cancelled order does), or for a line sold before the listing had options, its first option.
     */
    private function backVariant(Product $product, ?int $variantId): ?ProductVariant
    {
        if ($variantId) {
            return ProductVariant::withTrashed()->where('product_id', $product->id)->findOrFail($variantId);
        }

        return ProductVariant::where('product_id', $product->id)->orderBy('sort_order')->orderBy('id')->first();
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

    private static function today(): string
    {
        return CarbonImmutable::now(config('marketplace.timezone'))->toDateString();
    }
}
