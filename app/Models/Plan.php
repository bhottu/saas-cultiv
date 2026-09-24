<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'entitlements' => 'array',
        'features' => 'array',
        'is_active' => 'boolean',
        'is_free_tier' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    public function priceFor(string $cycle): int
    {
        return $cycle === 'yearly' ? $this->price_yearly : $this->price_monthly;
    }

    /** Entitlement limit for a metric; null means unlimited. Accepts 'storage_mb' or 'max_storage_mb'. */
    public function limit(string $metric): ?int
    {
        $value = $this->entitlements[$metric]
            ?? $this->entitlements["max_{$metric}"]
            ?? config("saas.usage.{$metric}.fallback");

        return $value;
    }
}
