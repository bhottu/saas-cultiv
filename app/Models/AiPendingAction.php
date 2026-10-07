<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiPendingAction extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];
}
