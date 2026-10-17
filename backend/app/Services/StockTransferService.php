<?php

namespace App\Services;

use App\Enums\StockType;
use App\Enums\TransferStatus;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Erp\StoreLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moving stock between a store's locations.
 *
 * Sending takes the units out of the first location at once (they cannot be sold while on the
 * way); receiving adds what arrived at the second. Cancelling a transfer on its way puts the units
 * back where they left. "Moved now" does both steps at once. Each step changes stock exactly once,
 * under the transfer's row lock and the listings' locks.
 */
class StockTransferService
{
    public const MAX_LINES = 50;

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{from_warehouse_id: int, to_warehouse_id: int, items: list<array{product_id: int, variant_id?: int|null, quantity: int}>, note?: string|null, received_now?: bool, request_id?: string|null}  $data
     */
    public function create(int $storeId, array $data, User $actor): StockTransfer
    {
        return DB::transaction(function () use ($storeId, $data, $actor) {
            StoreLock::take($storeId);
            if (! empty($data['request_id'])) {
                $earlier = StockTransfer::where('seller_profile_id', $storeId)->where('request_id', $data['request_id'])->first();
                if ($earlier) {
                    return $earlier;
                }
            }
            $from = $this->location($storeId, (int) $data['from_warehouse_id'], 'from_warehouse_id');
            $to = $this->location($storeId, (int) $data['to_warehouse_id'], 'to_warehouse_id');
            if ($from->id === $to->id) {
                throw ValidationException::withMessages(['to_warehouse_id' => 'Choose a different location to move the stock to.']);
            }
            $items = $data['items'] ?? [];
            if (count($items) < 1 || count($items) > self::MAX_LINES) {
                throw ValidationException::withMessages(['items' => 'Move between 1 and '.self::MAX_LINES.' items at a time.']);
            }

            // Listings are locked in id order before any stock is read, as checkout does.
            $ids = collect($items)->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->sort()->values();
            $products = Product::where('seller_profile_id', $storeId)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $lines = [];
            $seen = [];
            foreach (array_values($items) as $i => $item) {
                $product = $products[(int) $item['product_id']] ?? throw ValidationException::withMessages(["items.{$i}.product_id" => 'This is not one of your listings.']);
                if ($product->stock_type !== StockType::InStock) {
                    throw ValidationException::withMessages(["items.{$i}.product_id" => "{$product->name} is made to order and keeps no stock."]);
                }
                $variant = $this->inventory->variant($product, isset($item['variant_id']) ? (int) $item['variant_id'] : null);
                if (! $variant && $this->inventory->hasVariants($product)) {
                    throw ValidationException::withMessages(["items.{$i}.variant_id" => "Choose which option of {$product->name}."]);
                }
                $name = $product->name.($variant && $variant->label() !== '' ? ' ('.$variant->label().')' : '');
                $key = $product->id.':'.($variant?->id ?? 0);
                if (isset($seen[$key])) {
                    throw ValidationException::withMessages(["items.{$i}.product_id" => "{$name} is listed twice."]);
                }
                $seen[$key] = true;
                $quantity = (int) $item['quantity'];
                $here = $this->inventory->quantityAt($product, $variant, $from);
                if ($quantity < 1 || $quantity > $here) {
                    throw ValidationException::withMessages(["items.{$i}.quantity" => "{$from->name} has {$here} of {$name} available."]);
                }
                $lines[] = [$product, $variant, $name, $quantity];
            }

            $transfer = new StockTransfer;
            $transfer->seller_profile_id = $storeId;
            $transfer->sequence = StoreLock::next(StockTransfer::class, $storeId);
            $transfer->number = StoreLock::number('TR', $transfer->sequence);
            $transfer->from_warehouse_id = $from->id;
            $transfer->to_warehouse_id = $to->id;
            $transfer->status = TransferStatus::InTransit;
            $transfer->note = $data['note'] ?? null;
            $transfer->request_id = $data['request_id'] ?? null;
            $transfer->dispatched_at = now();
            $transfer->created_by = $actor->id;
            $transfer->save();

            $now = ! empty($data['received_now']);
            foreach ($lines as [$product, $variant, $name, $quantity]) {
                $line = new StockTransferItem;
                $line->stock_transfer_id = $transfer->id;
                $line->product_id = $product->id;
                $line->variant_id = $variant?->id;
                $line->description = mb_substr($name, 0, 255);
                $line->quantity = $quantity;
                $line->received_quantity = $now ? $quantity : null;
                $line->save();

                $ref = ['type' => 'stock_transfer', 'id' => $transfer->id];
                $this->inventory->change($product, $variant, $from, -$quantity, 'transfer_out', $actor, reference: $ref, note: "{$transfer->number} to {$to->name}");
                if ($now) {
                    $this->inventory->change($product, $variant, $to, $quantity, 'transfer_in', $actor, reference: $ref, note: "{$transfer->number} from {$from->name}");
                }
            }
            if ($now) {
                $transfer->status = TransferStatus::Received;
                $transfer->received_at = now();
                $transfer->received_by = $actor->id;
                $transfer->save();
            }
            $this->audit->log('erp.transfer_created', $transfer, [
                'number' => $transfer->number, 'from' => $from->id, 'to' => $to->id,
                'units' => array_sum(array_column($lines, 3)), 'received_now' => $now,
            ]);

            return $transfer;
        });
    }

    /**
     * The units arrived. $received maps line id to the number that arrived (default: all of it);
     * fewer means some were lost or damaged on the way and stay out of stock.
     *
     * @param  array<int, int>  $received
     */
    public function receive(int $storeId, int $transferId, array $received, User $actor): StockTransfer
    {
        return DB::transaction(function () use ($storeId, $transferId, $received, $actor) {
            $transfer = $this->find($storeId, $transferId);
            if ($transfer->status !== TransferStatus::InTransit) {
                throw ValidationException::withMessages(['status' => $transfer->status === TransferStatus::Received ? 'This transfer was already received.' : 'This transfer was cancelled.']);
            }
            $to = Warehouse::findOrFail($transfer->to_warehouse_id);
            $fromName = Warehouse::whereKey($transfer->from_warehouse_id)->value('name');
            $items = $transfer->items()->get();
            foreach (array_keys($received) as $lineId) {
                if (! $items->contains('id', (int) $lineId)) {
                    throw ValidationException::withMessages(['items' => 'A line is not on this transfer.']);
                }
            }
            $products = Product::withTrashed()->whereIn('id', $items->pluck('product_id')->unique()->sort()->values())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $short = 0;
            foreach ($items as $item) {
                $arrived = array_key_exists($item->id, $received) ? (int) $received[$item->id] : $item->quantity;
                if ($arrived < 0 || $arrived > $item->quantity) {
                    throw ValidationException::withMessages(['items' => "Between 0 and {$item->quantity} of {$item->description} can arrive."]);
                }
                $product = $products[$item->product_id];
                if ($arrived > 0) {
                    $variant = $this->inventory->inboundVariant($product, $item->variant_id, $item->description);
                    $note = "{$transfer->number} from {$fromName}".($arrived < $item->quantity ? ", {$arrived} of {$item->quantity} arrived" : '');
                    $this->inventory->change($product, $variant, $to, $arrived, 'transfer_in', $actor,
                        reference: ['type' => 'stock_transfer', 'id' => $transfer->id], note: $note);
                }
                $item->received_quantity = $arrived;
                $item->save();
                $short += $item->quantity - $arrived;
            }

            $transfer->status = TransferStatus::Received;
            $transfer->received_at = now();
            $transfer->received_by = $actor->id;
            $transfer->save();
            $this->audit->log('erp.transfer_received', $transfer, ['number' => $transfer->number, 'missing_units' => $short]);

            return $transfer;
        });
    }

    /** Called off on the way: the units go back to the location they left. */
    public function cancel(int $storeId, int $transferId, User $actor): StockTransfer
    {
        return DB::transaction(function () use ($storeId, $transferId, $actor) {
            $transfer = $this->find($storeId, $transferId);
            if ($transfer->status !== TransferStatus::InTransit) {
                throw ValidationException::withMessages(['status' => 'Only a transfer on its way can be cancelled.']);
            }
            $from = Warehouse::findOrFail($transfer->from_warehouse_id);
            $items = $transfer->items()->get();
            $products = Product::withTrashed()->whereIn('id', $items->pluck('product_id')->unique()->sort()->values())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($items as $item) {
                $product = $products[$item->product_id];
                $variant = $this->inventory->inboundVariant($product, $item->variant_id, $item->description);
                $this->inventory->change($product, $variant, $from, $item->quantity, 'transfer_returned', $actor,
                    reference: ['type' => 'stock_transfer', 'id' => $transfer->id], note: "{$transfer->number} cancelled");
            }
            $transfer->status = TransferStatus::Cancelled;
            $transfer->cancelled_at = now();
            $transfer->save();
            $this->audit->log('erp.transfer_cancelled', $transfer, ['number' => $transfer->number]);

            return $transfer;
        });
    }

    public function find(int $storeId, int $transferId): StockTransfer
    {
        return StockTransfer::where('seller_profile_id', $storeId)->lockForUpdate()->findOrFail($transferId);
    }

    private function location(int $storeId, int $id, string $field): Warehouse
    {
        $warehouse = Warehouse::where('seller_profile_id', $storeId)->find($id)
            ?? throw ValidationException::withMessages([$field => 'Choose one of your locations.']);
        if (! $warehouse->is_active) {
            throw ValidationException::withMessages([$field => "{$warehouse->name} is closed."]);
        }

        return $warehouse;
    }
}
