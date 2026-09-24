<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * QRIS.PW API client. Credentials stay server-side (env vars), never in the browser.
 * Docs: create-payment.php (POST), check-payment.php (GET). 100 req/min limit.
 */
class QrisPwClient
{
    public function createPayment(array $payload): array
    {
        $response = Http::withHeaders([
                'X-API-Key' => config('services.qrispw.api_key'),
                'X-API-Secret' => config('services.qrispw.api_secret'),
            ])
            ->timeout(15)
            ->retry(2, 300, throw: false)
            ->post(config('services.qrispw.endpoints.create'), $payload);

        if (! $response->successful()) {
            Log::error('qrispw.create.failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \RuntimeException("QRIS.PW create-payment failed (HTTP {$response->status()}).");
        }

        $data = $response->json();

        if (empty($data['success'])) {
            throw new \RuntimeException('QRIS.PW create-payment rejected: '.($data['error'] ?? 'unknown'));
        }

        return $data;
    }

    /** Server-side status verification (fallback when webhook is delayed/lost). */
    public function checkStatus(string $transactionId): array
    {
        $response = Http::withHeaders([
                'X-API-Key' => config('services.qrispw.api_key'),
                'X-API-Secret' => config('services.qrispw.api_secret'),
            ])
            ->timeout(15)
            ->get(config('services.qrispw.endpoints.status'), ['transaction_id' => $transactionId]);

        if (! $response->successful()) {
            throw new \RuntimeException("QRIS.PW check-payment failed (HTTP {$response->status()}).");
        }

        return $response->json() ?? [];
    }
}
