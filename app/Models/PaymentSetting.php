<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * The single row of platform-wide gateway selection (payment_settings).
 *
 * Read-only on every render path: activeGateway() falls back to config when the row
 * (or the table) does not exist yet, exactly like SeoSetting::config(), because a
 * checkout must never be the thing that creates a settings row or fails because the
 * migration has not run. An unknown stored value also falls back rather than wiring
 * every new checkout to a gateway that does not exist.
 */
class PaymentSetting extends Model
{
    protected $guarded = [];

    /** Gateways this platform can route new checkouts to. */
    public const KNOWN_GATEWAYS = ['qrispw', 'kasera'];

    /** The one settings row, created lazily by the admin form — never by a read. */
    public static function current(): self
    {
        // Never let an un-migrated database take down /admin/billing: the edit screen
        // must render (with the config default selected) so the admin can see the
        // "run migrations" guidance instead of a 500. Writes are refused in update().
        try {
            if (! Schema::hasTable((new static)->getTable())) {
                return new static(['active_gateway' => static::defaultGateway()]);
            }

            return static::query()->firstOrCreate([], ['active_gateway' => 'qrispw']);
        } catch (\Throwable) {
            return new static(['active_gateway' => static::defaultGateway()]);
        }
    }

    /** Config default when the row/table cannot be read (pre-migration behaviour: QRIS.PW). */
    public static function defaultGateway(): string
    {
        $fallback = (string) config('saas.default_payment_gateway', 'qrispw');

        return in_array($fallback, self::KNOWN_GATEWAYS, true) ? $fallback : 'qrispw';
    }

    /**
     * The gateway NEW checkouts are dispatched to.
     *
     * Never throws: a missing row, a missing table or a stale/unknown value all land on
     * the configured default (qrispw), which is the behaviour the platform had before
     * this table existed.
     */
    public static function activeGateway(): string
    {
        $fallback = static::defaultGateway();

        try {
            $stored = static::query()->value('active_gateway');
        } catch (\Throwable) {
            return $fallback;
        }

        $stored = is_string($stored) ? trim($stored) : '';

        return in_array($stored, self::KNOWN_GATEWAYS, true) ? $stored : $fallback;
    }

    /** Whether the underlying table exists (false on deployments that never migrated). */
    public static function isMigrated(): bool
    {
        try {
            return Schema::hasTable((new static)->getTable());
        } catch (\Throwable) {
            return false;
        }
    }
}