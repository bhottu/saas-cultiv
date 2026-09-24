<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

/** Permanently remove tenants past their data-retention window (audited). */
class PurgeExpiredTenants extends Command
{
    protected $signature = 'tenants:purge';

    protected $description = 'Permanently delete tenant data past the retention period';

    public function handle(): int
    {
        Tenant::onlyTrashed()
            ->where('status', 'pending_deletion')
            ->where('data_retention_until', '<', now())
            ->chunkById(50, function ($tenants) {
                foreach ($tenants as $tenant) {
                    // Hard-delete cascades via FKs; financial records are archived first.
                    \Illuminate\Support\Facades\Log::info('tenant.purged', ['tenant_id' => $tenant->id]);
                    \App\Models\AuditLog::create([
                        'tenant_id' => null,
                        'user_id' => null,
                        'action' => 'tenant.purged',
                        'resource_type' => 'tenant',
                        'resource_id' => (string) $tenant->id,
                        'metadata' => ['name' => $tenant->name, 'retention_until' => $tenant->data_retention_until?->toIso8601String()],
                    ]);
                    $tenant->forceDelete();
                }
            });

        return self::SUCCESS;
    }
}
