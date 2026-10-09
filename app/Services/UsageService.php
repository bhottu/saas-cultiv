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
    /**
     * Human-readable feature labels + the plans that include them. This is the
     * SINGLE mapping used by server-side enforcement AND the navigation lock
     * hints, so the UI can never advertise a different plan than the gate.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const FEATURE_LABELS = [
        'advanced_reports' => ['Advanced Reports', 'Pro and Business'],
        'advanced_permissions' => ['Advanced Permissions', 'Pro and Business'],
        'api_access' => ['API Access', 'Starter, Pro and Business'],
        'audit_log' => ['Audit Log', 'Pro and Business'],
        'advanced_analytics' => ['Advanced Analytics', 'Pro and Business'],
        'cultiv_ai' => ['AI Assistant Telegram', ''],
    ];

    public function record(Tenant $tenant, string $metric, int $amount = 1): void
    {
        [$start, $end] = $this->period($metric);

        $row = \App\Models\UsageRecord::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'metric' => $metric, 'period_start' => $start],
            ['period_end' => $end]
        );

        $row->increment('value', $amount);
    }

    /** Atomically apply a metered AI request against its workspace plan quota. */
    public function consume(Tenant $tenant, string $metric, int $amount = 1): void
    {
        DB::transaction(function () use ($tenant, $metric, $amount): void {
            $lockedTenant = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $plan = $this->planFor($lockedTenant);
            $limit = $plan?->limit($metric);
            $current = $this->usage($lockedTenant, $metric);

            if ($limit !== null && $current + $amount > $limit) {
                throw new SubscriptionLimitException(
                    resource: $this->resourceLabel($metric),
                    current: $current,
                    limit: $limit,
                    planName: $plan?->name ?? 'Free',
                    field: $metric,
                );
            }

            [$start, $end] = $this->period($metric);
            $row = \App\Models\UsageRecord::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $lockedTenant->id, 'metric' => $metric, 'period_start' => $start],
                ['period_end' => $end]
            );
            $row->increment('value', $amount);
        });
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
        $plan = $tenant->effectivePlan()
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

        $labels = self::FEATURE_LABELS;
        [$label, $plans] = $labels[$feature] ?? [str($feature)->headline()->toString(), 'Pro and Business'];

        throw new SubscriptionLimitException($label, null, null, $this->planFor($tenant)?->name ?? 'Free', $feature, $plans);
    }

    /** The plans that include $feature — for "Available on …" lock hints in the UI. */
    public function featurePlans(string $feature): string
    {
        $plans = Plan::active()->get()
            ->filter(fn (Plan $plan) => $plan->allows($feature))
            ->pluck('name');

        if ($plans->isNotEmpty()) {
            $names = $plans->values();
            $last = $names->pop();

            if ($names->isEmpty()) {
                return $last;
            }

            if ($names->count() === 1) {
                return __(':first and :last', ['first' => $names->first(), 'last' => $last]);
            }

            return __(':list, and :last', ['list' => $names->implode(', '), 'last' => $last]);
        }

        if (isset(self::FEATURE_LABELS[$feature]) && self::FEATURE_LABELS[$feature][1] === '') {
            return __('No active plans currently include this feature.');
        }

        return self::FEATURE_LABELS[$feature][1] ?? 'Pro and Business';
    }

    public function planFor(Tenant $tenant): ?Plan
    {
        return $tenant->effectivePlan() ?? Plan::where('is_free_tier', true)->first();
    }

    /** Resolve a tenant's own plan explicitly, independent of the request's current workspace. */
    private function ownedTenantPlan(Tenant $tenant): ?Plan
    {
        // effectivePlan() covers the platform-admin case; this raw query is only needed
        // when the workspace has no subscription of its own to read, which is why it
        // runs without the tenant global scope.
        return $tenant->effectivePlan()
            ?? Subscription::withoutGlobalScopes()
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
