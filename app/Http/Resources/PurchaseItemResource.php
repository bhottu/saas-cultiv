<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class PurchaseItemResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_name' => $this->product_name,
            'sku' => $this->sku,
            'quantity' => (int) $this->quantity,
            'unit_cost' => $this->cents($this->unit_cost),
            'subtotal' => $this->cents($this->subtotal),
        ];
    }
}