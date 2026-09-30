<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ProductResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'name' => $this->name,
            'description' => $this->description,
            'unit' => $this->unit,
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ] : null),
            'brand' => $this->whenLoaded('brand', fn () => $this->brand ? [
                'id' => $this->brand->id,
                'name' => $this->brand->name,
            ] : null),
            'prices' => [
                'purchase_price' => $this->cents($this->purchase_price),
                'cost_price' => $this->cents($this->cost_price),
                'selling_price' => $this->cents($this->selling_price),
            ],
            'stock' => [
                'track_inventory' => (bool) $this->track_inventory,
                'minimum_stock' => $this->minimum_stock,
                'maximum_stock' => $this->max_stock,
                'on_hand' => $this->whenLoaded(
                    'stockBalances',
                    fn () => (int) $this->stockBalances->sum('quantity')
                ),
            ],
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}