<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base for every public business API resource.
 *
 * Money is always exposed as an integer in minor units (cents), which is the exact
 * value stored in PostgreSQL. Floating point never appears in a financial payload.
 * The currency travels once in the response `meta` instead of on every field.
 */
abstract class ApiResource extends JsonResource
{
    /** @return array<string, mixed> */
    public static function currencyMeta(): array
    {
        return ['currency' => 'IDR', 'money_unit' => 'cents'];
    }

    /** Normalise a possibly-null money column to an integer. */
    protected function cents(int|float|string|null $value): int
    {
        return (int) $value;
    }
}