<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Shared purchase-order input rules and server-side totals.
 *
 * Owned by ONE place so the back-office form and the public API can never disagree
 * about what a valid purchase looks like, or about how its money is computed: a client
 * may send discount/tax/shipping, but the subtotal and the grand total are always
 * recalculated here from the line quantities and unit costs.
 *
 * Stock is intentionally NOT touched by any method on this class. A purchase only
 * increases inventory when it is received (PurchasesController::receive / the matching
 * API action) — creating or editing an order must never add stock on its own.
 */
class PurchaseOrderService
{
    /** Validation rules for creating or editing a purchase. */
    public function rules(Tenant $tenant): array
    {
        $owned = fn (string $table) => Rule::exists($table, 'id')
            ->where(fn ($query) => $query->where('tenant_id', $tenant->id));

        return [
            'supplier_id' => ['required', 'integer', $owned('suppliers')],
            'warehouse_id' => ['required', 'integer', $owned('warehouses')],
            'expected_at' => ['nullable', 'date'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'shipping' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:65535'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', $owned('products'), 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * Recompute every money field from the lines, in MINOR units (cents).
     *
     * `unit_cost` arrives in DISPLAY units (rupiah), exactly like the web form posts it.
     *
     * @return array{subtotal: int, discount: int, tax: int, shipping: int, total: int}
     */
    public function totals(array $data): array
    {
        $subtotal = 0;

        foreach ($data['items'] as $item) {
            $subtotal += (int) $item['quantity'] * Money::centsFromDisplay($item['unit_cost']);
        }

        $discount = min($subtotal, Money::centsFromDisplay($data['discount'] ?? 0));
        $tax = Money::centsFromDisplay($data['tax'] ?? 0);
        $shipping = Money::centsFromDisplay($data['shipping'] ?? 0);

        return compact('subtotal', 'discount', 'tax', 'shipping')
            + ['total' => max(0, $subtotal - $discount) + $tax + $shipping];
    }
}