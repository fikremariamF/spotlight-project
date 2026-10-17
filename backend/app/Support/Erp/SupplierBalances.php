<?php

namespace App\Support\Erp;

use App\Models\PurchaseOrder;
use App\Models\PurchaseReceiptItem;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;

/**
 * What a store bought from its suppliers, what it paid and what it still owes, from the records.
 *
 * A purchase order's bill is the total of the supplier invoices recorded for it. Until one is
 * recorded, the value of the goods received (at their cost including charges) stands in, so goods
 * from suppliers who give no invoice still count as owed. Invoices without an order add to the
 * supplier's bill. Owed = bill - payments (voided invoices and payments are left out); it is
 * negative when deposits are ahead of the bill.
 */
final class SupplierBalances
{
    /**
     * @param  array<int, array<string, int>>  $orders
     * @param  array<int, array<string, int>>  $suppliers
     */
    private function __construct(private readonly array $orders, private readonly array $suppliers) {}

    /** @param  list<int>|null  $supplierIds  null for every supplier of the store */
    public static function forStore(int $storeId, ?array $supplierIds = null): self
    {
        $only = fn ($q) => $supplierIds === null ? $q : $q->whereIn('supplier_id', $supplierIds);

        $pos = $only(PurchaseOrder::where('seller_profile_id', $storeId))->whereNotIn('status', ['draft', 'cancelled'])
            ->get(['id', 'supplier_id', 'total_santim']);
        $received = PurchaseReceiptItem::join('purchase_receipts', 'purchase_receipts.id', '=', 'purchase_receipt_items.purchase_receipt_id')
            ->whereIn('purchase_receipts.purchase_order_id', $pos->pluck('id'))
            ->groupBy('purchase_receipts.purchase_order_id')
            ->selectRaw('purchase_receipts.purchase_order_id as po, sum(purchase_receipt_items.quantity * purchase_receipt_items.unit_cost_santim) as value')
            ->pluck('value', 'po');
        $invoices = $only(SupplierInvoice::where('seller_profile_id', $storeId))->where('status', 'recorded')
            ->groupBy('supplier_id', 'purchase_order_id')
            ->selectRaw('supplier_id, purchase_order_id, sum(amount_santim) as amount, count(*) as n')->get();
        $payments = $only(SupplierPayment::where('seller_profile_id', $storeId))->where('status', 'recorded')
            ->groupBy('supplier_id', 'purchase_order_id')
            ->selectRaw('supplier_id, purchase_order_id, sum(amount_santim) as amount')->get();

        $orders = [];
        foreach ($pos as $po) {
            $orders[$po->id] = ['supplier_id' => $po->supplier_id, 'total_santim' => $po->total_santim, 'received_value_santim' => (int) ($received[$po->id] ?? 0),
                'invoiced_santim' => 0, 'invoices' => 0, 'paid_santim' => 0];
        }
        $suppliers = [];
        $blank = ['bought_santim' => 0, 'invoiced_santim' => 0, 'received_not_invoiced_santim' => 0, 'paid_santim' => 0, 'owed_santim' => 0];
        foreach ($invoices as $row) {
            $suppliers[$row->supplier_id] ??= $blank;
            $suppliers[$row->supplier_id]['invoiced_santim'] += (int) $row->amount;
            if ($row->purchase_order_id && isset($orders[$row->purchase_order_id])) {
                $orders[$row->purchase_order_id]['invoiced_santim'] += (int) $row->amount;
                $orders[$row->purchase_order_id]['invoices'] += (int) $row->n;
            } else {
                $suppliers[$row->supplier_id]['bought_santim'] += (int) $row->amount;
            }
        }
        foreach ($payments as $row) {
            $suppliers[$row->supplier_id] ??= $blank;
            $suppliers[$row->supplier_id]['paid_santim'] += (int) $row->amount;
            if ($row->purchase_order_id && isset($orders[$row->purchase_order_id])) {
                $orders[$row->purchase_order_id]['paid_santim'] += (int) $row->amount;
            }
        }
        foreach ($orders as $id => $o) {
            $payable = $o['invoices'] > 0 ? $o['invoiced_santim'] : $o['received_value_santim'];
            $orders[$id]['payable_santim'] = $payable;
            $orders[$id]['balance_santim'] = $payable - $o['paid_santim'];
            $suppliers[$o['supplier_id']] ??= $blank;
            $suppliers[$o['supplier_id']]['bought_santim'] += $payable;
            if ($o['invoices'] === 0) {
                $suppliers[$o['supplier_id']]['received_not_invoiced_santim'] += $o['received_value_santim'];
            }
        }
        foreach ($suppliers as $id => $s) {
            $suppliers[$id]['owed_santim'] = $s['bought_santim'] - $s['paid_santim'];
        }

        return new self($orders, $suppliers);
    }

    /** @return array<string, int> */
    public function supplier(int $supplierId): array
    {
        return $this->suppliers[$supplierId] ?? ['bought_santim' => 0, 'invoiced_santim' => 0, 'received_not_invoiced_santim' => 0, 'paid_santim' => 0, 'owed_santim' => 0];
    }

    /** @return array<string, int> */
    public function order(int $orderId): array
    {
        return $this->orders[$orderId] ?? ['total_santim' => 0, 'received_value_santim' => 0, 'invoiced_santim' => 0, 'invoices' => 0, 'paid_santim' => 0, 'payable_santim' => 0, 'balance_santim' => 0];
    }

    /** Owed to all suppliers together, counting only suppliers the store owes (not those it paid ahead). */
    public function totalOwed(): int
    {
        return array_sum(array_map(fn ($s) => max(0, $s['owed_santim']), $this->suppliers));
    }

    /** Deposits paid to suppliers beyond what they have billed so far. */
    public function totalPaidAhead(): int
    {
        return array_sum(array_map(fn ($s) => max(0, -$s['owed_santim']), $this->suppliers));
    }

    public function totalReceivedNotInvoiced(): int
    {
        return array_sum(array_column($this->suppliers, 'received_not_invoiced_santim'));
    }
}
