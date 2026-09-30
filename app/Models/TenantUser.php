<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Workspace membership — the ONLY link between a user account and a workspace.
 *
 * Removing somebody from a team soft-deletes this row (status 'removed') instead of
 * deleting the account, so access, seat counting and history stay separate concerns:
 *
 *   User Account  ──  Workspace Membership  ──  Access Permission
 *
 * Statuses: active (member) | invited (pending, no access) | rejected (declined) |
 * removed (soft-deleted, no access, revivable by the Owner).
 */
class TenantUser extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'tenant_user';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INVITED = 'invited';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_REMOVED = 'removed';

    /** Statuses that grant workspace access. Only `active` does. */
    public const ACCESS_STATUSES = [self::STATUS_ACTIVE];

    /** Statuses that reserve a seat against the plan limit. */
    public const SEAT_STATUSES = [self::STATUS_ACTIVE, self::STATUS_INVITED];

    protected $guarded = [];

    protected $casts = ['joined_at' => 'datetime'];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function invitedBy()
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** True when this membership no longer grants access to the workspace. */
    public function isRemoved(): bool
    {
        return $this->status === self::STATUS_REMOVED || $this->trashed();
    }
}

