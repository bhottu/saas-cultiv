<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use HasFactory, BelongsToTenant;

    protected $guarded = [];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'cancelled_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function isTrialing(): bool
    {
        return $this->status === 'trialing' && $this->trial_ends_at?->isFuture();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** Usable for paid features (trial, active, or grace period). */
    public function isUsable(): bool
    {
        return in_array($this->status, config('saas.active_states'))
            && ($this->current_period_end === null || $this->current_period_end->isFuture()
                || $this->current_period_end->gt(now()->subDays(config('saas.grace_period_days', 3))));
    }

    /** Advance the period after successful payment. */
    public function renew(): void
    {
        $base = $this->current_period_end && $this->current_period_end->isFuture()
            ? $this->current_period_end
            : now();

        $this->update([
            'status' => 'active',
            'current_period_start' => $base,
            'current_period_end' => $this->billing_cycle === 'yearly' ? $base->copy()->addYear() : $base->copy()->addMonth(),
            'cancelled_at' => null,
            'ended_at' => null,
        ]);
    }
}
