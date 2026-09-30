<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $guarded = [];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
        ];
    }

    public function tenants()
    {
        return $this->belongsToMany(Tenant::class, 'tenant_user')
            ->withPivot('role', 'status', 'joined_at')->withTimestamps();
    }

    /**
     * Workspaces this account can ACTUALLY switch into right now.
     *
     * `tenants()` is a plain belongsToMany, and a pivot row says nothing about whether
     * it still grants access: a revoked membership is kept as history (status 'removed'
     * + deleted_at) so the audit trail survives. A belongsToMany bypasses the
     * TenantUser model, therefore its SoftDeletes scope never runs, and an unfiltered
     * relation happily lists workspaces the user was removed from. Anything that
     * offers "switch to workspace" must use this relation instead.
     */
    public function activeTenants()
    {
        return $this->tenants()
            ->wherePivot('status', TenantUser::STATUS_ACTIVE)
            ->wherePivotNull('deleted_at');
    }

    /** Workspaces created/owned by this account; used only for account-level workspace limits. */
    public function ownedTenants(): HasMany
    {
        return $this->hasMany(Tenant::class, 'owner_id');
    }

    public function memberships()
    {
        return $this->hasMany(TenantUser::class);
    }

    /**
     * Memberships that were revoked by removing the member from the team.
     *
     * Soft-deleted rows keep the history: the workspace hub uses them to tell a
     * returning account "you no longer have access to your previous workspace"
     * instead of showing an unexplained 403.
     */
    public function removedMemberships()
    {
        return $this->memberships()
            ->onlyTrashed()
            ->where('status', TenantUser::STATUS_REMOVED)
            ->latest('deleted_at');
    }

    public function currentTenant()
    {
        return $this->belongsTo(Tenant::class, 'current_tenant_id');
    }

    public function membershipIn(Tenant $tenant): ?TenantUser
    {
        return $this->memberships()
            ->where('tenant_id', $tenant->id)
            ->where('status', TenantUser::STATUS_ACTIVE)
            ->first();
    }

    public function roleIn(Tenant $tenant): ?string
    {
        return $this->membershipIn($tenant)?->role;
    }
}

