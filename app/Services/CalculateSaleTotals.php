<?php

namespace App\Services;

/**
 * Authoritative sale money math — one implementation for the whole sales module.
 *
 * The browser previews totals for usability, but the server always recomputes them here from
 * the validated input: totals are never taken from the request.
 *
 * Conventions (identical to App\Services\Money):
 *  - every amount is cents (unsigned integers), never a float column;
 *  - percentage discounts are entered as whole percent and resolved to cents here;
 *  - a discount can never push a line, or the document, below zero.
 */
class CalculateSaleTotals
{
    public const TYPE_FIXED = 'fixed';
    public const TYPE_PERCENT = 'percent';

    public const DISCOUNT_TYPES = [self::TYPE_FIXED, self::TYPE_PERCENT];

    /**
     * @param  array<int, array{quantity:int|string, unit_price:int|string, discount_type?:string, discount_value?:int|string}>  $lines
     * @param  array{discount_type?:string, discount_value?:int|string, tax_percent?:int|string, shipping?:int|string}  $header
     * @return array{
     *     lines: array<int, array{gross:int, discount:int, subtotal:int}>,
     *     subtotal:int, item_discount:int, discount:int, discount_type:string, discount_value:int,
     *     tax:int, tax_percent:int, shipping:int, total:int
     * }
     */
    public function calculate(array $lines, array $header = []): array
    {
        $resolvedLines = [];
        $subtotal = 0;
        $itemDiscount = 0;

        foreach ($lines as $index => $line) {
            $resolved = $this->line(
                (int) $line['quantity'],
                (int) $line['unit_price'],
                (string) ($line['discount_type'] ?? self::TYPE_FIXED),
                (int) ($line['discount_value'] ?? 0),
            );

            $resolvedLines[$index] = $resolved;
            $subtotal += $resolved['subtotal'];
            $itemDiscount += $resolved['discount'];
        }

        $discountType = $this->normalizeType((string) ($header['discount_type'] ?? self::TYPE_FIXED));
        $discountValue = max(0, (int) ($header['discount_value'] ?? 0));
        $taxPercent = max(0, min(100, (int) ($header['tax_percent'] ?? 0)));
        $shipping = max(0, (int) ($header['shipping'] ?? 0));

        $discount = $this->discountFor($subtotal, $discountType, $discountValue);

        // Tax is applied on the discounted amount only.
        $taxable = max(0, $subtotal - $discount);
        $tax = $taxPercent > 0 ? Money::percentDiscount($taxable, (float) $taxPercent) : 0;

        $total = Money::total($subtotal, $discount, $tax) + $shipping;

        return [
            'lines'          => $resolvedLines,
            'subtotal'       => $subtotal,
            'item_discount'  => $itemDiscount,
            'discount'       => $discount,
            'discount_type'  => $discountType,
            'discount_value' => $discountValue,
            'tax'            => $tax,
            'tax_percent'    => $taxPercent,
            'shipping'       => $shipping,
            'total'          => $total,
        ];
    }

    /**
     * Resolve a single line: gross, resolved discount (cents) and net subtotal (cents).
     *
     * @return array{gross:int, discount:int, subtotal:int}
     */
    public function line(int $quantity, int $unitPrice, string $discountType = self::TYPE_FIXED, int $discountValue = 0): array
    {
        $quantity = max(0, $quantity);
        $unitPrice = max(0, $unitPrice);

        $gross = $quantity * $unitPrice;
        $discount = $this->discountFor($gross, $discountType, $discountValue);

        return [
            'gross'    => $gross,
            'discount' => $discount,
            'subtotal' => max(0, $gross - $discount),
        ];
    }

    /** Fixed (cents) or percentage (whole %) discount, capped at the discounted amount. */
    public function discountFor(int $amount, string $type, int $value): int
    {
        if ($amount <= 0 || $value <= 0) {
            return 0;
        }

        $discount = $this->normalizeType($type) === self::TYPE_PERCENT
            ? Money::percentDiscount($amount, (float) min(100, $value))
            : $value;

        return (int) min($amount, $discount);
    }

    public function normalizeType(string $type): string
    {
        return $type === self::TYPE_PERCENT ? self::TYPE_PERCENT : self::TYPE_FIXED;
    }
}