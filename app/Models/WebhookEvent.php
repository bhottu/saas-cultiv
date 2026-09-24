<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'signature_valid' => 'boolean',
        'processed_at' => 'datetime',
    ];

    /** Idempotency key: provider + transaction + status. */
    public static function fingerprint(string $provider, string $transactionId, string $status): string
    {
        return "{$provider}:{$transactionId}:{$status}";
    }
}
