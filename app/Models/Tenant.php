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
