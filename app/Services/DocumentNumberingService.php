<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Atomic, tenant-scoped document numbering for business documents (INV/PUR/RTR).
 *
 * Uses the same transactional upsert+lock pattern as PaymentService::nextInvoiceNumber(), but
 * against a tenant-scoped table (document_sequences) because SaaS-billing invoice_sequences is
 * keyed by year alone and therefore cannot hold one counter per document type or per tenant.
 *
 * Numbering is safe under concurrent requests: the sequence row is created with an
 * insert-or-ignore and then locked (lockForUpdate) for the increment, inside one transaction.
 *
 * Formats (readable, never a raw database id):
 *   sale        INV-20260924-0001  (per tenant, per day)
 *   sale_return RTR-20260924-0001  (per tenant, per day)
 *   purchase    PUR-20260924-0001  (per tenant, per day)
 *   invoice     INV-2026-000001    (per tenant, per year)
 */
class DocumentNumberingService
{
    private const TYPES = [
        'sale'        => ['prefix' => 'INV', 'period' => 'Ymd', 'length' => 4],
        'sale_return' => ['prefix' => 'RTR', 'period' => 'Ymd', 'length' => 4],
        'purchase'    => ['prefix' => 'PUR', 'period' => 'Ymd', 'length' => 4],
        'invoice'     => ['prefix' => 'INV', 'period' => 'Y',   'length' => 6],
    ];

    /** Next document number for $type. Sequences reset per period (day/year) and per tenant. */
    public function next(string $type, ?int $tenantId = null, ?Carbon $at = null): string
    {
        $format = self::TYPES[$type] ?? throw new InvalidArgumentException("Unknown document type: {$type}");

        $tenantId ??= app('tenant.context')->tenant()?->id
            ?? throw new RuntimeException('Cannot generate a document number without tenant context.');

        $at ??= now();
        $period = $at->format($format['period']);

        return DB::transaction(function () use ($type, $tenantId, $period, $format, $at) {
            $sequence = DB::table('document_sequences');

            // Create-if-absent without racing: the unique (tenant_id, type, period) index
            // makes a duplicate insert a no-op on both PostgreSQL and SQLite.
            $sequence->insertOrIgnore([
                'tenant_id'   => $tenantId,
                'type'        => $type,
                'period'      => $period,
                'last_number' => 0,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            $current = (int) $sequence
                ->where('tenant_id', $tenantId)
                ->where('type', $type)
                ->where('period', $period)
                ->lockForUpdate()
                ->value('last_number');

            $next = $current + 1;

            $sequence
                ->where('tenant_id', $tenantId)
                ->where('type', $type)
                ->where('period', $period)
                ->update(['last_number' => $next, 'updated_at' => now()]);

            return sprintf(
                '%s-%s-%s',
                $format['prefix'],
                $period,
                str_pad((string) $next, $format['length'], '0', STR_PAD_LEFT)
            );
        });
    }
}