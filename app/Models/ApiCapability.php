<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Platform-global API capability row (/admin/api).
 *
 * One row per resource, never per workspace: Read/Write here is the GLOBAL capability
 * layer that decides whether an operation exists on the platform at all. Combined at
 * request time with the plan entitlement and the token's scopes, it yields the
 * effective permission (see ApiAccessService).
 */
class ApiCapability extends Model
{
    protected $guarded = [];

    protected $casts = [
        'read_enabled' => 'boolean',
        'write_enabled' => 'boolean',
    ];
}
