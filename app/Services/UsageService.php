<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

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
        // Seats come live from memberships, not metered records.
        if ($metric === 'max_users') {
            return $tenant->seatCount();
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

    /** Enforce a server-side plan limit; throws 429 when exceeded. */
    public function enforce(Tenant $tenant, string $metric, int $requested = 1): void
    {
        $limit = $this->limit($tenant, $metric);

        if ($limit !== null && $this->usage($tenant, $metric) + $requested > $limit) {
            abort(429, "Plan limit reached for '{$metric}' ({$limit}). Upgrade your plan to continue.");
        }
    }

    public function enforceSeat(Tenant $tenant): void
    {
        $this->enforce($tenant, 'max_users');
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
