<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class StockBalanceResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product' => [
                'id' => $this->product_id,
                'sku' => $this->whenLoaded('product', fn () => $this->product->sku),
                'name' => $this->whenLoaded('product', fn () => $this->product->name),
                'unit' => $this->whenLoaded('product', fn () => $this->product->unit),
            ],
            'warehouse' => [
                'id' => $this->warehouse_id,
                'name' => $this->whenLoaded('warehouse', fn () => $this->warehouse->name),
                'code' => $this->whenLoaded('warehouse', fn () => $this->warehouse->code),
            ],
            'quantity' => (int) $this->quantity,
            'incoming' => (int) $this->incoming,
            'outgoing' => (int) $this->outgoing,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}