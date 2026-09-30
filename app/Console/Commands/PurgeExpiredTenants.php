<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Permanently remove tenants past their data-retention window (audited).
 *
 * IMPORTANT: deleting a workspace from the UI is a SOFT delete (see
 * TenantController::destroy()). This command is the separate, later step that may
 * remove the row for good — so it is deliberately conservative:
 *
 *   - Business records are NEVER dropped by a routine run. Every business table
 *     keys on tenant_id with ON DELETE CASCADE, so a forceDelete() would silently
 *     destroy products, customers, sales, purchases, stock and invoices. When any
 *     business data is still present the workspace is only reported and kept.
 *   - A deliberate purge requires the --force flag, and the retention window is
 *     still enforced, so it can never fire by accident.
 */
class PurgeExpiredTenants extends Command
{
    protected $signature = 'tenants:purge {--force : Purge even when business records still reference the workspace}';

    protected $description = 'Permanently delete tenant data past the retention period';

    /** Business tables that must survive a workspace purge. */
    private const BUSINESS_TABLES = [
        'sales', 'sale_items', 'purchases', 'purchase_items',
        'products', 'customers', 'suppliers', 'stock_movements',
        'stock_balances', 'warehouses', 'expenses', 'invoices', 'payments',
    ];

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $purged = 0;
        $skipped = 0;

        Tenant::onlyTrashed()
            ->where('status', 'pending_deletion')
            ->whereNotNull('data_retention_until')
            ->where('data_retention_until', '<', now())
            ->chunkById(50, function ($tenants) use ($force, &$purged, &$skipped) {
                foreach ($tenants as $tenant) {
                    $business = $this->businessRecordCount($tenant->id);

                    if ($business > 0 && ! $force) {
                        $skipped++;
                        \Illuminate\Support\Facades\Log::warning('tenant.purge.skipped', [
                            'tenant_id' => $tenant->id,
                            'business_records' => $business,
                        ]);
                        $this->warn("Tenant #{$tenant->id} ({$tenant->name}) kept: {$business} business record(s) still reference it.");
                        continue;
                    }

                    // Audit before the row disappears, with tenant_id nulled because
                    // audit_logs.tenant_id is itself a cascading FK.
                    \App\Models\AuditLog::create([
                        'tenant_id' => null,
                        'user_id' => null,
                        'action' => 'tenant.purged',
                        'resource_type' => 'tenant',
                        'resource_id' => (string) $tenant->id,
                        'metadata' => [
                            'name' => $tenant->name,
                            'retention_until' => $tenant->data_retention_until?->toIso8601String(),
                            'business_records' => $business,
                            'forced' => $force,
                        ],
                    ]);

                    \Illuminate\Support\Facades\Log::info('tenant.purged', [
                        'tenant_id' => $tenant->id,
                        'business_records' => $business,
                        'forced' => $force,
                    ]);

                    $tenant->forceDelete();
                    $purged++;
                }
            });

        $this->info("Purged: {$purged}, kept (business data present): {$skipped}");

        return self::SUCCESS;
    }

    /** How many business rows still point at this workspace. */
    private function businessRecordCount(int $tenantId): int
    {
        $total = 0;

        foreach (self::BUSINESS_TABLES as $table) {
            if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
                continue;
            }

            $total += (int) \Illuminate\Support\Facades\DB::table($table)
                ->where('tenant_id', $tenantId)
                ->count();
        }

        return $total;
    }
}
