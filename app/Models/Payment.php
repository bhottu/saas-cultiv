<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Services\PaymentService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory, BelongsToTenant;

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast() && $this->status === 'pending';
    }

    /** A payment the provider has already settled. Never cancellable, never re-created. */
    public function isSettled(): bool
    {
        return in_array($this->status, PaymentService::SETTLED_STATUSES, true);
    }

    /** Still awaiting money, and still payable. This is what blocks a new checkout. */
    public function isActivePending(): bool
    {
        return $this->status === 'pending'
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** May the customer abandon this payment and start over? */
    public function isCancellable(): bool
    {
        return ! $this->isSettled() && in_array($this->status, PaymentService::UNSETTLED_STATUSES, true);
    }
}
