<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Sales returns / refunds — the single implementation for returning goods.
 *
 * Rules:
 *  - a return can never exceed what was actually sold (validated per line, server-side);
 *  - refunds are derived from the ORIGINAL sale_item snapshots (selling_price, discount,
 *    cost_price), so refunding and COGS crediting stay stable over time;
 *  - every returned unit becomes an inventory movement (type "sale_return") created by
 *    InventoryService inside the same DB transaction: stock is never edited by hand and a
 *    cancelled/fully refunded sale can never restore the same unit twice.
 */
class SaleReturnService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly DocumentNumberingService $numbering,
    ) {}

    /**
     * Record a (partial) return for a sale.
     *
     * @param  array<int|string, int|string>  $quantities  sale_item_id => quantity to return
     * @param  array{reason?:string, refund_method?:string, notes?:string, returned_at?:mixed}  $attributes
     */
    public function record(Sale $sale, array $quantities, array $attributes = [], ?int $userId = null): SaleReturn
    {
        return DB::transaction(function () use ($sale, $quantities, $attributes, $userId) {
            // Lock the sale so two concurrent returns cannot over-return the same line.
            $locked = Sale::withoutGlobalScopes()->whereKey($sale->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === Sale::STATUS_CANCELLED) {
                throw new InvalidArgumentException('A cancelled sale cannot be returned.');
            }

            if ($locked->status === Sale::STATUS_REFUNDED) {
                throw new InvalidArgumentException('This sale has already been fully refunded.');
            }

            $tenant = Tenant::findOrFail($locked->tenant_id);
            $items = $locked->items()->get()->keyBy('id');

            $lines = [];
            $refundTotal = 0;

            foreach ($quantities as $itemId => $quantity) {
                $quantity = (int) $quantity;

                if ($quantity < 1) {
                    continue;
                }

                /** @var SaleItem|null $item */
                $item = $items->get((int) $itemId);

                if (! $item) {
                    throw new InvalidArgumentException('The selected line does not belong to this sale.');
                }

                if ($quantity > $item->remainingQuantity()) {
                    throw new InvalidArgumentException(sprintf(
                        'Cannot return %d of "%s": only %d sold unit(s) left to return.',
                        $quantity,
                        $item->product_name,
                        $item->remainingQuantity()
                    ));
                }

                $refund = $this->refundFor($item, $quantity);

                $lines[] = ['item' => $item, 'quantity' => $quantity, 'refund' => $refund];
                $refundTotal += $refund;
            }

            if ($lines === []) {
                throw new InvalidArgumentException('Select at least one product quantity to return.');
            }

            $return = SaleReturn::create([
                'tenant_id'     => $tenant->id,
                'sale_id'       => $locked->id,
                'return_number' => $this->numbering->next('sale_return', $tenant->id),
                'returned_at'   => $attributes['returned_at'] ?? now(),
                'refund_amount' => $refundTotal,
                'refund_method' => $attributes['refund_method'] ?? $locked->payment_method,
                'reason'        => $attributes['reason'] ?? 'Customer return',
                'status'        => 'completed',
                'created_by'    => $userId,
                'notes'         => $attributes['notes'] ?? null,
            ]);

            foreach ($lines as $line) {
                /** @var SaleItem $item */
                $item = $line['item'];

                SaleReturnItem::create([
                    'tenant_id'      => $tenant->id,
                    'sale_return_id' => $return->id,
                    'sale_item_id'   => $item->id,
                    'product_id'     => $item->product_id,
                    'quantity'       => $line['quantity'],
                    'refund_amount'  => $line['refund'],
                ]);

                $item->increment('returned_quantity', $line['quantity']);

                $product = $item->product_id
                    ? Product::withoutGlobalScopes()->find($item->product_id)
                    : null;

                if ($product && $product->track_inventory && $locked->warehouse_id) {
                    $this->inventory->ensureBalance($tenant, $product, (int) $locked->warehouse_id);

                    $this->inventory->return(
                        $tenant,
                        $product,
                        (int) $locked->warehouse_id,
                        (int) $line['quantity'],
                        $userId,
                        'sale_return',
                        $locked->id,
                        "Return {$return->return_number}"
                    );
                }
            }

            $locked->load('items');

            $fullyReturned = $locked->items->every(fn (SaleItem $item) => $item->remainingQuantity() === 0);

            $locked->forceFill([
                'refunded_amount' => (int) $locked->refunded_amount + $refundTotal,
                'status'          => $fullyReturned ? Sale::STATUS_REFUNDED : $locked->status,
                'refunded_at'     => $fullyReturned ? ($locked->refunded_at ?? now()) : $locked->refunded_at,
            ])->save();

            $locked->refreshPaymentStatus();

            AuditLogger::log('sale.returned', $locked, [
                'return_number' => $return->return_number,
                'refund_amount' => $refundTotal,
                'quantity'      => array_sum(array_column($lines, 'quantity')),
            ]);

            return $return;
        });
    }

    /**
     * Return every remaining unit of the sale ("Refund" action on the invoice).
     */
    public function refundAll(Sale $sale, ?int $userId = null, string $reason = 'Full refund'): SaleReturn
    {
        $sale->loadMissing('items');

        $quantities = [];

        foreach ($sale->items as $item) {
            if ($item->remainingQuantity() > 0) {
                $quantities[$item->id] = $item->remainingQuantity();
            }
        }

        if ($quantities === []) {
            throw new InvalidArgumentException('This sale has nothing left to refund.');
        }

        return $this->record($sale, $quantities, ['reason' => $reason], $userId);
    }

    /**
     * Refund value of $quantity units of a line, proportional to its net subtotal so the
     * original line discount is respected (cents, never more than the line is worth).
     */
    public function refundFor(SaleItem $item, int $quantity): int
    {
        $quantity = max(0, $quantity);
        $sold = (int) $item->quantity;

        if ($quantity < 1 || $sold < 1) {
            return 0;
        }

        $refund = (int) round((int) $item->subtotal * $quantity / $sold);

        return max(0, min((int) $item->subtotal, $refund));
    }
}