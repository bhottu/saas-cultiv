<?php

namespace App\Services;

use App\Exceptions\PaymentProviderException;
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
            // The provider body is logged for the operator; it is echoed nowhere near the
            // response, and the exception message carries only the status code.
            Log::error('qrispw.create.failed', [
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 500),
            ]);

            throw PaymentProviderException::fromProviderResponse($response->status(), 'create-payment');
        }

        $data = $response->json();

        if (empty($data['success'])) {
            Log::error('qrispw.create.rejected', [
                'status' => $response->status(),
                'error' => $data['error'] ?? 'unknown',
            ]);

            throw new PaymentProviderException('QRIS.PW create-payment rejected: '.($data['error'] ?? 'unknown'));
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
            throw PaymentProviderException::fromProviderResponse($response->status(), 'check-payment');
        }

        return $response->json() ?? [];
    }
}
