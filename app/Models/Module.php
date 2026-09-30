<?php

namespace App\Models;

use App\Services\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Platform module catalogue row.
 *
 * NOT tenant-owned: a module definition belongs to the platform (like Plan), so this
 * model deliberately does NOT use BelongsToTenant and carries no tenant_id. Per-workspace
 * state lives in TenantModule.
 */
class Module extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active'      => 'boolean',
        'is_core'        => 'boolean',
        'is_paid'        => 'boolean',
        'price_monthly'  => 'integer',
        'price_yearly'   => 'integer',
        'entitlements'   => 'array',
        'sort_order'     => 'integer',
    ];

    /** Installs of this module across workspaces (platform-admin / reporting use). */
    public function tenantModules(): HasMany
    {
        return $this->hasMany(TenantModule::class);
    }

    /** Modules the marketplace may offer (inactive rows stay for historical FKs). */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Route-model binding resolves modules by their stable slug (`/modules/{module:slug}`).
     *
     * Without this, Laravel would resolve by numeric id and every module URL would 404.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function isPaid(): bool
    {
        return $this->is_paid && (int) $this->price_monthly > 0;
    }

    /**
     * Price formatted for display.
     *
     * Whole-rupiah values are rendered with Money::formatRupiah() — the SaaS billing
     * convention (plans/subscriptions/invoices) — never Money::format() (cents), because
     * module pricing sits next to plan pricing in the same UI.
     */
    public function priceLabel(): string
    {
        if (! $this->isPaid()) {
            return __('Free');
        }

        return Money::formatRupiah($this->price_monthly, $this->currency ?: 'IDR').' / '.__('month');
    }

    /** Declared dependencies / limits shipped with the module definition. */
    public function entitlement(string $key, mixed $default = null): mixed
    {
        return $this->entitlements[$key] ?? $default;
    }
}
