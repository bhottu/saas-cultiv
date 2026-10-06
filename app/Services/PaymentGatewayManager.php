<?php

namespace App\Services;

use App\Models\PaymentSetting;

/**
 * Resolves a gateway key to its implementation.
 *
 * `driver()` is keyed off payments.provider — the gateway that CREATED the payment —
 * so an in-flight checkout keeps going to the same provider even if the platform's
 * active gateway is switched while its QR is on screen. `active()` is only read when
 * a NEW checkout is created.
 *
 * An unknown key (a stale row, a value from a future release) falls back to QRIS.PW
 * rather than throwing: billing must never be the thing that crashes.
 */
class PaymentGatewayManager
{
    public function __construct(
        private readonly QrisPwGateway $qrispw,
        private readonly KaseraGateway $kasera,
    ) {}

    public function driver(?string $key): PaymentGateway
    {
        return match ($key) {
            'kasera' => $this->kasera,
            default => $this->qrispw,
        };
    }

    /** The gateway new checkouts are dispatched to (admin selection, with fallback). */
    public function active(): PaymentGateway
    {
        return $this->driver(PaymentSetting::activeGateway());
    }

    /**
     * Everything the admin gateway picker needs, in display order.
     *
     * @return list<array{key: string, label: string, configured: bool}>
     */
    public function descriptors(): array
    {
        return array_map(
            fn (PaymentGateway $gateway): array => [
                'key' => $gateway->name(),
                'label' => $gateway->label(),
                'configured' => $gateway->isConfigured(),
            ],
            [$this->qrispw, $this->kasera],
        );
    }
}