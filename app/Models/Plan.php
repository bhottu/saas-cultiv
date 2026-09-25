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

    /**
     * Numeric entitlement limit. A present null value is deliberately unlimited;
     * it must not fall through to a configured default.
     */
    public function limit(string $metric): ?int
    {
        $entitlements = $this->entitlements ?? [];
        $aliases = [
            'workspaces' => 'max_workspaces',
            'products_count' => 'max_products',
            'customers_count' => 'max_customers',
            'api_calls' => 'max_api_calls',
            'storage_mb' => 'max_storage_mb',
        ];
        $key = $aliases[$metric] ?? $metric;

        // Accept the historical entitlement keys as well as the canonical max_* names.
        $keys = array_values(array_unique([$metric, $key, str_starts_with($key, 'max_') ? $key : "max_{$key}"]));
        foreach ($keys as $candidate) {
            if (array_key_exists($candidate, $entitlements)) {
                return $entitlements[$candidate] === null ? null : (int) $entitlements[$candidate];
            }
        }

        $fallbackMetric = match ($key) {
            'max_storage_mb' => 'storage_mb',
            'max_api_calls' => 'api_calls',
            default => $key,
        };
        $fallback = config("saas.usage.{$fallbackMetric}.fallback");

        return $fallback === null ? null : (int) $fallback;
    }

    /** Whether a non-resource feature (reports, API, audit, etc.) is included. */
    public function allows(string $feature): bool
    {
        return (bool) (($this->entitlements ?? [])[$feature] ?? false);
    }

    public function displayLimit(string $metric): string
    {
        $limit = $this->limit($metric);

        return $limit === null ? 'Unlimited' : number_format($limit);
    }
}
