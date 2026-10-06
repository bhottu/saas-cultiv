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

    /**
     * The provider's own QR image URL, exactly as QRIS.PW returned it.
     *
     * The raw create-payment response is kept untouched in `payload`, and this reads
     * it back without transformation: no trimming, re-encoding, URL rebuilding or
     * HTML handling. Whatever the provider put there is what the browser is asked to
     * load, so the scanned code is the provider's code and not a Cultiv construction.
     *
     * Several key names are accepted because the response contract was never pinned to
     * a single documented name. Reading more than one key cannot corrupt anything — the
     * value is passed through verbatim — but it does stop a rename from silently
     * rendering the wrong resource or nothing at all.
     */
    public function qrImageUrl(): ?string
    {
        return $this->qrField([
            'qris_url',
            'qr_url',
            'qr_image_url',
            'image_url',
            'qris_image',
            'qrCodeUrl',
        ]);
    }

    /**
     * The raw EMVCo QRIS payload string, if the provider sent one.
     *
     * This is the provider's data, never assembled here. It is kept because it is the
     * authoritative statement of what the customer is being asked to pay: a real QRIS
     * payload always begins with the EMVCo identifier "0002", which is what makes a
     * generated code equivalent to an image from the provider.
     */
    public function qrisPayloadString(): ?string
    {
        return $this->qrField([
            'qris_string',
            'qrisString',
            'qr_string',
            'qrString',
            'payload_string',
        ]);
    }

    /** First non-empty value among the given keys in the stored create response. */
    private function qrField(array $keys): ?string
    {
        $create = $this->payload['create'] ?? null;

        if (! is_array($create)) {
            return null;
        }

        foreach ($keys as $key) {
            $value = $create[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                // Returned verbatim: the caller renders this as a src, and any
                // alteration here would change what the bank reads when scanning.
                return $value;
            }
        }

        return null;
    }

    /**
     * The hosted checkout page the provider returned, if any.
     *
     * Kasera answers create-transaction with `checkout_url` — a page on their domain
     * that renders the QR / VA picker for whatever the buyer picks. Like the QR fields
     * above it is passed through verbatim: the browser is sent to the provider's own
     * URL, never to one Cultiv assembled. QRIS.PW responses do not carry this key, so
     * this returns null and the QR display is untouched.
     */
    public function checkoutUrl(): ?string
    {
        $create = $this->payload['create'] ?? null;

        if (! is_array($create)) {
            return null;
        }

        $value = $create['checkout_url'] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
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
