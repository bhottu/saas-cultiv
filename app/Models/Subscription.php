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

    /**
     * Advance the period after successful payment.
     *
     * One payment may cover 1, 3, 6 or 12 months of a monthly plan (period_months), so
     * the extension is that many months rather than a hardcoded one — a customer who
     * prepaid for three months gets exactly three. A yearly row still adds a year, which
     * for period_months = 12 is the same arithmetic with clearer intent, and rows written
     * before this column existed renew exactly as they did before.
     *
     * $months / $amount let the caller pass what THIS invoice actually paid, so a renewal
     * that upgrades from a 1-month to a 3-month prepayment re-arms both the period and
     * the MRR figure instead of extending by the old terms.
     */
    public function renew(?int $months = null, ?int $amount = null): void
    {
        $base = $this->current_period_end && $this->current_period_end->isFuture()
            ? $this->current_period_end
            : now();

        $months = $months ?? ($this->billing_cycle === 'yearly'
            ? 12
            : max(1, (int) ($this->period_months ?? 1)));

        $this->update([
            'status' => 'active',
            'current_period_start' => $base,
            'current_period_end' => $base->copy()->addMonths($months),
            'amount' => $amount ?? $this->amount,
            'period_months' => $months,
            'cancelled_at' => null,
            'ended_at' => null,
        ]);
    }
}
