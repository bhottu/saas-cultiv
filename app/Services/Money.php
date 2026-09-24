<?php

namespace App\Services;

use App\Models\Payment;
use App\Notifications\PaymentFailedNotification;

/**
 * Lightweight money helpers.
 *
 * The existing SaaS stores money as cents in unsignedInteger columns (Invoice.amount,
 * Payment.amount, Plan.price_*). Business tables follow the same convention so there is
 * ONE money convention across the application.
 *
 * ORM-level math never touches storage; display formatting is locale-aware (IDR default).
 */
class Money
{
    /**
     * Format cents to a locale-aware currency string.
     *
     * @param int|float|string|null $cents
     */
    public static function format(int|float|string|null $cents, string $currency = 'IDR', bool $fraction = true): string
    {
        $cents = (int) ($cents ?? 0);

        if ($cents === 0) {
            return self::symbol($currency).' 0';
        }

        $value = $cents / 100;

        $num = number_format($value, $fraction ? 2 : 0, ',', '.');

        return self::symbol($currency).' '.$num;
    }

    public static function symbol(string $currency = 'IDR'): string
    {
        return match (strtoupper($currency)) {
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            default => 'Rp',
        };
    }

    /**
     * Normalize a user-facing amount (Rp100.000) into cents for storage.
     */
    public static function centsFromDisplay(int|float|string $amount, string $currency = 'IDR'): int
    {
        $amount = (float) $amount;

        if (strtoupper($currency) === 'IDR') {
            // IDR is stored per 1000 — but existing app stores per 100 (cents).
            // Keep ONE convention: cents. Display uses 1000 separators only.
            $divisor = 1;
        }

        return (int) round($amount * 100 / ($divisor ?? 1));
    }

    /**
     * Percentage discount on a subtotal (cents).
     */
    public static function percentDiscount(int $subtotal, float $percent): int
    {
        return (int) round($subtotal * $percent / 100);
    }

    /**
     * Apply a fixed or percentage discount to a subtotal.
     */
    public static function applyDiscount(int $subtotal, ?int $fixed = null, ?float $percent = null): int
    {
        $discount = 0;

        if ($fixed !== null) {
            $discount += $fixed;
        }

        if ($percent !== null) {
            $discount += self::percentDiscount($subtotal, $percent);
        }

        return max(0, $subtotal - $discount);
    }

    /**
     * Net total after discount and tax.
     */
    public static function total(int $subtotal, ?int $discount = null, ?int $tax = null): int
    {
        $amount = $subtotal - ($discount ?? 0);

        if ($tax !== null) {
            $amount += $tax;
        }

        return max(0, $amount);
    }
}