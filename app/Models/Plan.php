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
        'price_1month' => 'integer',
        'price_3months' => 'integer',
        'price_6months' => 'integer',
        'price_12months' => 'integer',
    ];

    /** Manually set prices per period. A value of 0 means "not set manually". */
    public static function periodColumns(): array
    {
        return [
            1 => 'price_1month',
            3 => 'price_3months',
            6 => 'price_6months',
            12 => 'price_12months',
        ];
    }

    /** Return a manual price if stored for this period, otherwise fall back. */
    public function periodPrice(int $months): int
    {
        $column = $this->getColumnForPeriod($months);

        return $column !== null && (int) $this->{$column} > 0
            ? (int) $this->{$column}
            : $this->fallbackPrice($months);
    }

    /** Internal fallback: monthly_price x months, or price_yearly for the year. */
    public function fallbackPrice(int $months): int
    {
        if ($months === 12 && (int) $this->price_yearly > 0) {
            return (int) $this->price_yearly;
        }

        return (int) $this->price_monthly * $months;
    }

    /** Column used to store a manual price for a given period (null = no manual price). */
    public function getColumnForPeriod(int $months): ?string
    {
        return self::periodColumns()[$months] ?? null;
    }

    /** Whether a manual price exists for the requested period. */
    public function hasManualPeriodPrice(int $months): bool
    {
        $column = $this->getColumnForPeriod($months);

        return $column !== null && (int) $this->{$column} > 0;
    }

    /**
     * Resolve a total price for a billing cycle, honoring manual overrides.
     *
     * The billable figure used by checkout, invoices and subscriptions. A manually
     * stored price beats the generic monthly x months multiplication, so an admin can
     * enforce e.g. Rp105.000 for a 3-month period even when the monthly price would
     * imply Rp117.000.
     */
    public function priceFor(string $cycle): int
    {
        return $cycle === 'yearly'
            ? $this->getMaybeManual('price_yearly')
            : $this->periodPrice(1);
    }

    /** Total paid when a customer chooses a specific months-long period of this plan. */
    public function priceForPeriod(int $months): int
    {
        return $this->hasManualPeriodPrice($months)
            ? $this->periodPrice($months)
            : $this->fallbackPrice($months);
    }

    /** Resolve a yearly price with the same manual-override rule as periods. */
    private function getMaybeManual(string $column): int
    {
        return (int) $this->{$column} > 0 ? (int) $this->{$column} : 0;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
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
            'ai_messages' => 'max_ai_messages',
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
            'max_ai_messages' => 'ai_messages',
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
