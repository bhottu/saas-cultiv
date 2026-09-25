<?php

namespace App\Services;

use App\Exceptions\SubscriptionLimitException;
use App\Models\Product;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Usage metering + server-side plan limit enforcement. */
class UsageService
{
    public function record(Tenant $tenant, string $metric, int $amount = 1): void
    {
        [$start, $end] = $this->period($metric);

        $row = \App\Models\UsageRecord::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'metric' => $metric, 'period_start' => $start],
            ['period_end' => $end]
        );

        $row->increment('value', $amount);
    }

    public function usage(Tenant $tenant, string $metric): int
    {
        // Seats and catalogue counts come from their authoritative live tables.
        if ($metric === 'max_users') {
            return $tenant->occupiedSeatCount();
        }

        if ($metric === 'products_count' || $metric === 'max_products') {
            return Product::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->count();
        }

        [$start] = $this->period($metric);

        return (int) \App\Models\UsageRecord::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('metric', $metric)
            ->where('period_start', $start)
            ->value('value');
    }

    public function limit(Tenant $tenant, string $metric): ?int
    {
        $plan = $tenant->activeSubscription?->plan
            ?? Plan::where('is_free_tier', true)->first();

        return $plan?->limit($metric);
    }

    public function remaining(Tenant $tenant, string $metric): ?int
    {
        $limit = $this->limit($tenant, $metric);

        return $limit === null ? null : max(0, $limit - $this->usage($tenant, $metric));
    }

    /** Enforce a server-side plan limit with an upgrade-aware domain exception. */
    public function enforce(Tenant $tenant, string $metric, int $requested = 1): void
    {
        DB::transaction(function () use ($tenant, $metric, $requested): void {
            // Callers that mutate the protected resource perform this check and the mutation
            // in the same outer transaction, so the row lock serializes concurrent requests.
            $lockedTenant = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $plan = $this->planFor($lockedTenant);
            $limit = $plan?->limit($metric);
            $current = $this->usage($lockedTenant, $metric);

            if ($limit !== null && $current + $requested > $limit) {
                throw new SubscriptionLimitException(
                    resource: $this->resourceLabel($metric),
                    current: $current,
                    limit: $limit,
                    planName: $plan?->name ?? 'Free',
                    field: $metric === 'max_users' ? 'email' : $metric,
                );
            }
        });
    }

    public function enforceSeat(Tenant $tenant): void
    {
        $this->enforce($tenant, 'max_users');
    }

    /** Account-level workspace allowance is based on the user's best active owned plan. */
    public function workspaceLimitFor(User $user): ?int
    {
        $plans = $user->ownedTenants()
            ->where('status', 'active')
            ->get()
            ->map(fn (Tenant $tenant) => $this->ownedTenantPlan($tenant))
            ->filter()
            ->push(Plan::where('is_free_tier', true)->first())
            ->filter();

        $limits = $plans->map(fn (Plan $plan) => $plan->limit('max_workspaces'))->values();

        return $limits->contains(null) ? null : (int) ($limits->max() ?? 1);
    }

    public function enforceWorkspaceCreation(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $owned = $lockedUser->ownedTenants()->where('status', 'active')->get();
            $plans = $owned->map(fn (Tenant $tenant) => $this->ownedTenantPlan($tenant))
                ->push(Plan::where('is_free_tier', true)->first())
                ->filter();
            $limits = $plans->map(fn (Plan $plan) => $plan->limit('max_workspaces'))->values();
            $limit = $limits->contains(null) ? null : (int) ($limits->max() ?? 1);
            $current = $owned->count();

            if ($limit !== null && $current + 1 > $limit) {
                $planName = $plans->sortByDesc(fn (Plan $plan) => $plan->limit('max_workspaces') ?? PHP_INT_MAX)->first()?->name ?? 'Free';
                throw new SubscriptionLimitException('Workspace', $current, $limit, $planName, 'name');
            }
        });
    }

    public function allows(Tenant $tenant, string $feature): bool
    {
        return $this->planFor($tenant)?->allows($feature) ?? false;
    }

    public function enforceFeature(Tenant $tenant, string $feature): void
    {
        if ($this->allows($tenant, $feature)) {
            return;
        }

        $labels = [
            'advanced_reports' => ['Advanced Reports', 'Pro and Business'],
            'advanced_permissions' => ['Advanced Permissions', 'Pro and Business'],
            'api_access' => ['API Access', 'Business'],
            'audit_log' => ['Audit Log', 'Pro and Business'],
            'advanced_analytics' => ['Advanced Analytics', 'Pro and Business'],
        ];
        [$label, $plans] = $labels[$feature] ?? [str($feature)->headline()->toString(), 'Pro and Business'];

        throw new SubscriptionLimitException($label, null, null, $this->planFor($tenant)?->name ?? 'Free', $feature, $plans);
    }

    public function planFor(Tenant $tenant): ?Plan
    {
        return $tenant->activeSubscription?->plan ?? Plan::where('is_free_tier', true)->first();
    }

    /** Resolve a tenant's own plan explicitly, independent of the request's current workspace. */
    private function ownedTenantPlan(Tenant $tenant): ?Plan
    {
        return Subscription::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['trialing', 'active', 'past_due', 'paused'])
            ->whereNull('ended_at')
            ->latest('id')
            ->with('plan')
            ->first()?->plan;
    }

    private function resourceLabel(string $metric): string
    {
        return match ($metric) {
            'max_users' => 'User',
            'max_products', 'products_count' => 'Product',
            'max_workspaces', 'workspaces' => 'Workspace',
            default => str($metric)->headline()->toString(),
        };
    }

    /** @return array{0:string,1:string} */
    private function period(string $metric): array
    {
        $type = config("saas.usage.{$metric}.period", 'month');

        return $type === 'forever'
            ? ['1970-01-01', '9999-12-31']
            : [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()];
    }
}
