<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class SaleResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'sales_channel' => $this->sales_channel,
            'payment_method' => $this->payment_method,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
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
                'total_cogs' => $this->cents($this->total_cogs),
                'gross_profit' => $this->cents((int) $this->total - (int) $this->total_cogs),
                'paid_amount' => $this->cents($this->paid_amount),
                'change_amount' => $this->cents($this->change_amount),
                'refunded_amount' => $this->cents($this->refunded_amount),
            ],
            'items' => SaleItemResource::collection($this->whenLoaded('items')),
            'items_count' => $this->whenCounted('items'),
            'sold_at' => $this->sold_at?->toIso8601String(),
            'completed_at' => $this->when(isset($this->completed_at), fn () => $this->completed_at?->toIso8601String()),
            'cancelled_at' => $this->when(isset($this->cancelled_at), fn () => $this->cancelled_at?->toIso8601String()),
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}