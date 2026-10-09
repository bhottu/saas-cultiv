<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per served API request (§23). Append-only, tenant-scoped.
 *
 * Stores the token's id, never the token value: a log row must be useless as a
 * credential even if it is read by somebody who should not have it.
 */
class ApiUsage extends Model
{
    /** A logged request never changes after it happened. */
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'status_code' => 'integer',
        'duration_ms' => 'integer',
        'created_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }
}
