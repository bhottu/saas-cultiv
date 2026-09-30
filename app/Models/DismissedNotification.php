<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A notice the account owner chose to hide for good.
 *
 * This is a user preference, not application data, so it is deliberately tiny and
 * generic: the caller decides the `key` that identifies the notice. Because the row
 * lives on the server, a dismissal holds across refreshes, sessions and devices for
 * that account, while a different key still raises its own notice.
 */
class DismissedNotification extends Model
{
    protected $guarded = [];

    protected $casts = ['dismissed_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
