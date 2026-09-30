<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class CustomerResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'notes' => $this->notes,
            'credit_limit' => $this->cents($this->credit_limit),
            'is_active' => (bool) $this->is_active,
            'totals' => [
                'orders' => $this->whenCounted('sales'),
                'spent' => $this->when(isset($this->total_spent), fn () => $this->cents($this->total_spent)),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}