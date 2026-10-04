<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    use HasFactory, \Illuminate\Database\Eloquent\SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'data_retention_until' => 'datetime',
        'settings' => 'array',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'tenant_user')->withPivot('role', 'status', 'joined_at')->withTimestamps();
    }

    public function memberships()
    {
        return $this->hasMany(TenantUser::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function files()
    {
        return $this->hasMany(\App\Models\FileEntry::class);
    }

    /** Warehouses / stock locations of this tenant (used by the stock and sales modules). */
    public function warehouses()
    {
        return $this->hasMany(Warehouse::class);
    }

    /** Customers of this tenant (tenant-owned master data for sales). */
    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    public function suppliers() { return $this->hasMany(Supplier::class); }
    public function purchases() { return $this->hasMany(Purchase::class); }
    public function expenses() { return $this->hasMany(Expense::class); }
    public function businessInvoices() { return $this->hasMany(BusinessInvoice::class); }

    /** Sales documents of this tenant. */
    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    /** Modules installed/activated for this workspace. */
    public function tenantModules()
    {
        return $this->hasMany(TenantModule::class);
    }

    public function activeSubscription()
    {
        return $this->hasOne(Subscription::class)
            ->whereIn('status', ['trialing', 'active', 'past_due', 'paused'])
            ->whereNull('ended_at')
            ->latestOfMany();
    }

    /**
     * Whether this workspace is governed by a platform administrator.
     *
     * A membership with is_platform_admin = true is the whole test — it is the same
     * flag EnsurePlatformAdmin gates the /admin panel on, so "admin" means exactly one
     * thing across the application rather than a second definition invented here.
     *
     * Only ACTIVE memberships count: a removed member must not keep granting a plan.
     */
    public function hasPlatformAdmin(): bool
    {
        return $this->memberships()
            ->where('status', TenantUser::STATUS_ACTIVE)
            ->whereHas('user', fn ($query) => $query->where('is_platform_admin', true))
            ->exists();
    }

    /**
     * The plan that governs this workspace's entitlements.
     *
     * This is the ONE place the rule lives. Feature gating (UsageService, ModuleManager,
     * the plan.feature middleware) and the limit checks all resolve through it, so a
     * platform admin holding Business permanently is enforced everywhere at once
     * instead of being re-implemented at each call site.
     *
     * `activeSubscription` is left untouched on purpose: it stays the raw, truthful
     * record of what was actually purchased, which is what the billing screen shows.
     */
    public function effectivePlan(): ?Plan
    {
        if ($this->hasPlatformAdmin()) {
            // Business is the top of the catalogue, so it is the permanent plan. Looked
            // up by slug because that is the identifier the rest of the billing flow
            // uses, and a catalogue with no Business row simply falls through to the
            // subscription below rather than erroring.
            return Plan::where('slug', 'business')->first() ?? $this->activeSubscription?->plan;
        }

        return $this->activeSubscription?->plan;
    }

    public function seatCount(): int
    {
        return $this->memberships()->where('status', TenantUser::STATUS_ACTIVE)->count();
    }

    /** Active members plus pending invitations; every reserved seat consumes plan capacity. */
    public function occupiedSeatCount(): int
    {
        return $this->memberships()->whereIn('status', TenantUser::SEAT_STATUSES)->count();
    }

    // ------------------------------------------------------------------ Custom branding

    /**
     * Workspace display name, falling back to the product default.
     *
     * An empty string is treated as "not set" so clearing the field in the form
     * restores the default rather than leaving a blank lockup in the sidebar.
     */
    public function brandName(): string
    {
        $name = trim((string) $this->brand_name);

        return $name !== '' ? $name : (string) config('app.name', 'Cultiv One');
    }

    /** Workspace tagline, falling back to the product default. */
    public function brandTagline(): string
    {
        $tagline = trim((string) $this->brand_tagline);

        return $tagline !== '' ? $tagline : (string) config('app.tagline', 'The smarter way to manage your business');
    }

    /**
     * URL of the uploaded logo, or null when the workspace has none.
     *
     * Points at the authenticated branding route rather than the raw storage path, so
     * the file is only reachable by members of this workspace.
     */
    public function brandLogoUrl(): ?string
    {
        return $this->brand_logo_path ? route('branding.logo', $this) : null;
    }

    /** True once this workspace overrides any part of the product identity. */
    public function hasCustomBranding(): bool
    {
        return filled($this->brand_name) || filled($this->brand_tagline) || filled($this->brand_logo_path);
    }
}
