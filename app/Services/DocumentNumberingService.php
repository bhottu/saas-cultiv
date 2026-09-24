<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Atomic document numbering for business documents (INV/SALE/PUR).
 *
 * Reuses the same transactional upsert+lock pattern as PaymentService::nextInvoiceNumber()
 * (invoice_sequences table) so numbering is safe under concurrent requests on PostgreSQL
 * and degrades gracefully on SQLite during tests.
 */
class DocumentNumberingService
{
    private const TYPES = [
        'invoice' => 'INV',
        'sale'   => 'SALE',
        'purchase' => 'PUR',
    ];

    public function next(string $type, ?int $tenantId = null): string
    {
        $prefix = self::TYPES[$type] ?? throw new \InvalidArgumentException("Unknown document type: {$type}");

        $year = now()->year;

        $seq = DB::table('invoice_sequences'); // same atomic numbering table

        return DB::transaction(function () use ($seq, $prefix, $year, $type, $tenantId) {
            $seq->upsert(
                ['year' => $year, 'last_number' => 0, 'type' => $type],
                ['year', 'type'],
                []
            );

            $current = (int) $seq->where('year', $year)->where('type', $type)->lockForUpdate()->value('last_number');
            $next = $current + 1;

            $seq->where('year', $year)->where('type', $type)->update(['last_number' => $next]);

            return sprintf('%s-%d-%06d', $prefix, $year, $next);
        });
    }
}