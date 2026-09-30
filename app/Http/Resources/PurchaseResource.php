<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class PurchaseResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->invoice_number,
            'status' => $this->status,
            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier ? [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
            ] : null),
            'warehouse' => $this->whenLoaded('warehouse', fn () => $this->warehouse ? [
                'id' => $this->warehouse->id,
                'name' => $this->warehouse->name,
            ] : null),
            'totals' => [
                'subtotal' => $this->cents($this->subtotal),
                'discount' => $this->cents($this->discount),
                'tax' => $this->cents($this->tax),
                'shipping' => $this->cents($this->shipping),
                'total' => $this->cents($this->total),
            ],
            'items' => PurchaseItemResource::collection($this->whenLoaded('items')),
            'items_count' => $this->whenCounted('items'),
            'ordered_at' => $this->ordered_at?->toIso8601String(),
            'expected_at' => $this->expected_at?->toDateString(),
            'received_at' => $this->when(isset($this->received_at), fn () => $this->received_at?->toIso8601String()),
            'cancelled_at' => $this->when(isset($this->cancelled_at), fn () => $this->cancelled_at?->toIso8601String()),
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}