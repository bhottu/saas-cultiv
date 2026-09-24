<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\UsageRecord;

/**
 * Business usage metrics extension of UsageService.
 *
 * Reuses the existing UsageService core machinery (usage/limit/enforce/record/period)
 * and adds business-specific metered metrics so the SaaS plan system can cap:
 *   - products_count (total active products)
 *   - customers_count (total active customers)
 *   - sales_count (sales created in the current period)
 *
 * The existing seat enforcement (max_users) is unchanged.
 */
class BusinessUsageService
{
    public function __construct(private readonly UsageService $usage) {}

    /**
     * Metered count used for plan caps (distinct from live DB count for historical accuracy).
     */
    public function recordMetric(Tenant $tenant, string $metric, int $amount = 1): void
    {
        $this->usage->record($tenant, $metric, $amount);
    }

    public function usage(Tenant $tenant, string $metric): int
    {
        return $this->usage->usage($tenant, $metric);
    }

    public function limit(Tenant $tenant, string $metric): ?int
    {
        return $this->usage->limit($tenant, $metric);
    }

    public function remaining(Tenant $tenant, string $metric): ?int
    {
        return $this->usage->remaining($tenant, $metric);
    }

    /**
     * Enforce a server-side business metric limit; throws 429 when exceeded.
     */
    public function enforce(Tenant $tenant, string $metric, int $requested = 1): void
    {
        $this->usage->enforce($tenant, $metric, $requested);
    }

    /**
     * Active products count (metered snapshot maintained by product CRUD).
     */
    public function activeProductsUsage(Tenant $tenant): int
    {
        return $this->usage($tenant, 'products_count');
    }

    /**
     * Active customers count (metered snapshot maintained by customer CRUD).
     */
    public function activeCustomersUsage(Tenant $tenant): int
    {
        return $this->usage($tenant, 'customers_count');
    }

    /**
     * Sales created this period (metered). Distinct from live sales for enforcement stability.
     */
    public function salesUsageThisPeriod(Tenant $tenant): int
    {
        return $this->usage($tenant, 'sales_count');
    }

    /**
     * Current live active product count (for dashboard/reporting).
     */
    public function liveActiveProductsCount(Tenant $tenant): int
    {
        return \App\Models\Product::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->count();
    }

    /**
     * Current live active customer count.
     */
    public function liveActiveCustomersCount(Tenant $tenant): int
    {
        return \App\Models\Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->count();
    }
}