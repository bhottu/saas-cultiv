<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Sanctum personal access token with a workspace binding.
 *
 * `tenant_id` is what makes the credential a WORKSPACE credential: the API resolves
 * its tenant from the token, never from a client-supplied header or query parameter
 * and never from whichever workspace the owner happens to have selected in the
 * browser (§2). Null only for legacy tokens created before the binding existed.
 *
 * Registered as Sanctum's token model in AppServiceProvider::boot().
 */
class PersonalAccessToken extends SanctumToken
{
    protected $guarded = [];

    protected $casts = [
        'tenant_id' => 'integer',
        'abilities' => 'array',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
