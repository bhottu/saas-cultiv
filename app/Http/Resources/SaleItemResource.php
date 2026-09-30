<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class SaleItemResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_name' => $this->product_name,
            'sku' => $this->sku,
            'quantity' => (int) $this->quantity,
            'unit' => $this->unit,
            // Historical snapshots: these values never change when the product
            // is repriced later, so historical profit stays reproducible.
            'selling_price' => $this->cents($this->selling_price),
            'cost_price' => $this->cents($this->cost_price),
            'discount_type' => $this->discount_type,
            'discount_value' => $this->cents($this->discount_value),
            'discount_percent' => (int) $this->discount,
            'subtotal' => $this->cents($this->subtotal),
            'cogs' => $this->cents($this->cogs()),
            'returned_quantity' => (int) $this->returned_quantity,
            'returnable_quantity' => $this->remainingQuantity(),
        ];
    }
}