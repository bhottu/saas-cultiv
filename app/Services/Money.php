<?php

namespace App\Services;

use App\Models\Payment;

/**
 * Lightweight money helpers — the ONE money formatter in the application.
 *
 * Cultiv One stores money in two integer units, and both are pinned by tests:
 *
 *  - Business tables (sales.*, sale_items.*, products.*, purchases.*, expenses.*)
 *    store MINOR UNITS (cents): SalesFlowTest asserts `selling_price => 1_500_000`
 *    is "Rp 15.000". Use Money::format() for these.
 *
 *  - SaaS billing tables (plans.price_*, subscriptions.amount, invoices.amount,
 *    payments.amount) store WHOLE RUPIAH: QrisWebhookTest asserts 149000 is what
 *    goes to QRIS.PW and comes back on the webhook. Use Money::formatRupiah().
 *
 * Both entry points share one renderer, so the output shape is identical
 * everywhere: "Rp 6.650.000", never "665,000,000" and never "Rp 6.650.000,00"
 * for a whole-rupee amount.
 *
 * ORM-level math never touches storage; display formatting is locale-aware (IDR default).
 */
class Money
{
    /**
     * Format a CENTS value to a locale-aware currency string.
     */
    public static function format(int|float|string|null $cents, string $currency = 'IDR', ?bool $fraction = null): string
    {
        $cents = (int) round((float) ($cents ?? 0));

        if ($cents === 0) {
            return self::symbol($currency).' 0';
        }

        return self::render($cents / 100, $currency, $fraction ?? ($cents % 100 !== 0));
    }

    /**
     * Format a WHOLE-RUPIAH value (SaaS billing: plans, subscriptions, invoices,
     * payments, MRR). No minor-unit conversion is applied — the stored integer is
     * already the rupiah amount the user is charged.
     */
    public static function formatRupiah(int|float|string|null $amount, string $currency = 'IDR', ?bool $fraction = null): string
    {
        $amount = (float) ($amount ?? 0);

        if (abs($amount) < 0.005) {
            return self::symbol($currency).' 0';
        }

        // Whole rupiah (the normal case for plan/subscription/payment amounts) is
        // never rendered as "Rp 39.000,00".
        return self::render($amount, $currency, $fraction ?? abs($amount - round($amount)) > 0.004);
    }

    /** Single renderer: identical separators and prefix for every money surface. */
    private static function render(float $value, string $currency, bool $fraction): string
    {
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
     * Normalize a user-facing amount (Rp 15.000) into minor units for storage.
     *
     * Business tables keep cents, matching the convention pinned by
     * SalesFlowTest and ProductCrudTest.
     */
    public static function centsFromDisplay(int|float|string $amount, string $currency = 'IDR'): int
    {
        return (int) round((float) $amount * 100);
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
