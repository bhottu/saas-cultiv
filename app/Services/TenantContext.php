<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Holds the resolved tenant context for the current request.
 * Tenant isolation is enforced through this context + BelongsToTenant scope.
 */
class TenantContext
{
    public ?Tenant $tenant = null;
    public ?User $user = null;
    public ?string $role = null;

    public function set(?Tenant $tenant, ?User $user = null): void
    {
        $this->tenant = $tenant;
        $this->user = $user;
        $this->role = ($tenant && $user) ? $tenant->users()->where('users.id', $user->id)->value('role') : null;

        if ($tenant && $user) {
            Cache::remember("tenant.settings.{$tenant->id}", 300, fn () => $tenant->settings ?? []);
        }
    }

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function role(): ?string
    {
        return $this->role;
    }

    public function check(): void
    {
        abort_unless($this->tenant && $this->user && $this->role !== null, 403, 'No tenant context.');
    }

    /** Server-side permission check against the role registry. */
    public function userCan(string $permission): bool
    {
        if (! $this->role) {
            return false;
        }

        $allowed = config("permissions.roles.{$this->role}", []);

        return in_array('*', $allowed) || in_array($permission, $allowed);
    }

    public function authorize(string $permission): void
    {
        abort_unless($this->userCan($permission), 403, "Missing permission: {$permission}.");
    }
}
