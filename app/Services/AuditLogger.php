<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/** Lightweight audit trail helper (defensive: never breaks the main flow). */
class AuditLogger
{
    public static function log(string $action, $resource = null, array $metadata = []): void
    {
        try {
            \App\Models\AuditLog::record($action, $resource, $metadata);
        } catch (\Throwable $e) {
            Log::warning('audit.log.failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }
}
