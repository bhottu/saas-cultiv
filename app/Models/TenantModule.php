<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A workspace's install record for one module.
 *
 * Tenant-owned on purpose: the same BelongsToTenant trait used by every business table
 * auto-stamps tenant_id on create and scopes every read, so one workspace can never see
 * or flip another workspace's installs. The route layer additionally re-checks the row
 * against the active tenant context (see ModulesController).
 *
 * This table only ever holds STATE — the module definition stays in `modules`.
 */
class TenantModule extends Model
{
    use BelongsToTenant;

    /** Added to the workspace but switched off. */
    public const STATUS_INSTALLED = 'installed';

    /** Installed and usable: routes guarded by `module:<key>` pass, sidebar shows it. */
    public const STATUS_ACTIVE = 'active';

    /** Previously activated, now switched off. */
    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [self::STATUS_INSTALLED, self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    protected $guarded = [];

    protected $casts = [
        'settings'        => 'array',
        'installed_at'    => 'datetime',
        'activated_at'    => 'datetime',
        'deactivated_at'  => 'datetime',
    ];

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeForTenant(Builder $query, int|Tenant $tenant): Builder
    {
        return $query->where('tenant_id', $tenant instanceof Tenant ? $tenant->id : $tenant);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Whether this workspace holds an install record for the module — i.e. any of the
     * three known states.
     *
     * INACTIVE COUNTS AS INSTALLED. Deactivate switches a module off; it does not
     * uninstall it. Excluding `inactive` here made the Module Center show a
     * deactivated module as never installed, so the only button rendered was
     * "Install" (which is a no-op on the existing record) and the module could never
     * be switched back on. Whether a module may be USED is `isActive()` alone.
     */
    public function isInstalled(): bool
    {
        return in_array($this->status, self::STATUSES, true);
    }

    /** Human label for the status badge — one wording for the UI and the tests. */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE    => __('Active'),
            self::STATUS_INSTALLED => __('Installed'),
            self::STATUS_INACTIVE  => __('Inactive'),
            default                => __('Unknown'),
        };
    }

    /** One setting of this workspace's module configuration. */
    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }
}
