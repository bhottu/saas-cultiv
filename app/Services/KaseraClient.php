<?php

namespace App\Services;

use App\Exceptions\PaymentProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Kasera Pay API client (pay.kasera.id). Credentials stay server-side (env vars),
 * never in the browser.
 *
 * Contract per docs:
 *  - Bearer auth with a kp_test / kp_live key (KASERA_API_KEY).
 *  - POST /v1/transactions creates a transaction; the Idempotency-Key header makes a
 *    retry of the same order return the original transaction instead of minting a
 *    second one, which is exactly what a transport-layer resend needs.
 *  - GET  /v1/transactions/{id} retrieves it — the reconcile fallback when the
 *    webhook is delayed or lost.
 *  - A 2xx answer must still carry an `id`; a 200 with an error body is a rejection.
 *
 * Structure mirrors QrisPwClient deliberately: same guard order (configured → amount
 * floor → transport → status → body), same correlation-only logging, so both gateways
 * fail the same way for the same reasons.
 */
class KaseraClient
{
    /** Display name used in exception messages (customer- and operator-facing). */
    public const PROVIDER = 'Kasera Pay';

    /** Placeholders that mean "somebody copied the example and never filled this in". */
    private const PLACEHOLDERS = ['your', 'changeme', 'xxx', 'placeholder', 'example', 'todo', 'null'];

    /**
     * Refuse to call the provider when the integration is not wired up.
     *
     * Same reasoning as QrisPwClient: an empty key would otherwise POST with an empty
     * Bearer header, come back 401, and send the operator hunting the network instead
     * of the .env line. Only the NAME of the missing variable is ever reported.
     */
    private function assertConfigured(string $operation, array $context): void
    {
        $value = trim((string) config('services.kasera.api_key'));

        if ($value === '' || $this->looksLikePlaceholder($value)) {
            Log::error('kasera.not_configured', $context + [
                'endpoint' => $operation,
                'missing_variable' => 'KASERA_API_KEY',
            ]);

            throw PaymentProviderException::notConfigured($operation, 'KASERA_API_KEY', $context, self::PROVIDER);
        }
    }

    private function looksLikePlaceholder(string $value): bool
    {
        $needle = strtolower($value);

        foreach (self::PLACEHOLDERS as $marker) {
            if (str_contains($needle, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Refuse an amount the QRIS rail can never accept.
     *
     * Rp 1,000 is the floor of the rail both gateways settle on, and a plan priced
     * under it is a configuration fault that retrying can never fix — so it is caught
     * here, before the rows are written, with a log line naming the price.
     */
    private function assertAmountAcceptable(string $operation, array $payload, array $context): void
    {
        $amount = (int) ($payload['amount'] ?? 0);
        $minimum = (int) config('services.kasera.min_amount');

        if ($amount >= $minimum) {
            return;
        }

        Log::error('kasera.amount_below_minimum', $context + [
            'endpoint' => $operation,
            'amount' => $amount,
            'provider_minimum' => $minimum,
            'likely_cause' => 'check plans.price_monthly / price_yearly',
        ]);

        throw PaymentProviderException::amountBelowMinimum($operation, $amount, $minimum, $context, self::PROVIDER);
    }

    /**
     * POST /v1/transactions.
     *
     * @param  array<string, mixed>  $payload  amount / description / external_id / customer …
     * @param  string  $idempotencyKey  sent as Idempotency-Key; a resend of the same key
     *                                  returns the original transaction instead of a second one
     * @param  array<string, mixed>  $context  correlation ids for the log; never a credential
     */
    public function createPayment(array $payload, string $idempotencyKey, array $context = []): array
    {
        $this->assertConfigured('create-transaction', $context);
        $this->assertAmountAcceptable('create-transaction', $payload, $context);

        try {
            $response = Http::withHeaders([
                    'Authorization' => 'Bearer '.config('services.kasera.api_key'),
                    'Idempotency-Key' => $idempotencyKey,
                ])
                ->timeout(15)
                // throw:false keeps the final failure as a response we can classify;
                // without it a 4xx/5xx would surface as an uncaught RequestException.
                ->retry(2, 300, throw: false)
                ->post(config('services.kasera.endpoints.create'), $payload);
        } catch (ConnectionException $e) {
            $reason = $this->transportReason($e);

            Log::error('kasera.create.transport_failed', $context + [
                'endpoint' => 'create-transaction',
                'reason' => $reason,
            ]);

            throw PaymentProviderException::transport('create-transaction', $reason, $context, self::PROVIDER);
        }

        if (! $response->successful()) {
            $this->logFailure('create-transaction', $response, $context);

            throw PaymentProviderException::fromProviderResponse(
                $response->status(),
                'create-transaction',
                $context,
                self::PROVIDER
            );
        }

        $data = $response->json();

        // A 2xx without a transaction id is unusable: reconcile keys on it and the
        // webhook matches on it. Treated as a rejection rather than silently stored.
        if (! is_array($data) || empty($data['id'])) {
            $providerError = is_array($data) ? ($data['error']['message'] ?? $data['message'] ?? null) : null;

            Log::error('kasera.create.rejected', $context + [
                'endpoint' => 'create-transaction',
                'status' => $response->status(),
                'provider_error' => $providerError ? mb_substr((string) $providerError, 0, 200) : 'missing transaction id',
            ]);

            throw new PaymentProviderException(
                self::PROVIDER.' create-transaction rejected: '.($providerError ?? 'missing transaction id')
            );
        }

        return $data;
    }

    /**
     * GET /v1/transactions/{id} — server-side status verification.
     *
     * The id is the one this platform stored at create time (payreq_…), URL-encoded
     * so no id shape can ever break the path.
     */
    public function checkStatus(string $transactionId, array $context = []): array
    {
        $endpoint = config('services.kasera.endpoints.create').'/'.rawurlencode($transactionId);

        try {
            $response = Http::withHeaders([
                    'Authorization' => 'Bearer '.config('services.kasera.api_key'),
                ])
                ->timeout(15)
                ->retry(2, 300, throw: false)
                ->get($endpoint);
        } catch (ConnectionException $e) {
            $reason = $this->transportReason($e);

            Log::error('kasera.check.transport_failed', $context + [
                'endpoint' => 'retrieve-transaction',
                'reason' => $reason,
            ]);

            throw PaymentProviderException::transport('retrieve-transaction', $reason, $context, self::PROVIDER);
        }

        if (! $response->successful()) {
            $this->logFailure('retrieve-transaction', $response, $context);

            throw PaymentProviderException::fromProviderResponse(
                $response->status(),
                'retrieve-transaction',
                $context,
                self::PROVIDER
            );
        }

        return $response->json() ?? [];
    }

    /**
     * One structured line per provider failure: endpoint, status and the provider's
     * own message, plus the correlation ids. Credentials and headers are never
     * included.
     */
    private function logFailure(string $operation, $response, array $context): void
    {
        Log::error('kasera.request_failed', $context + [
            'endpoint' => $operation,
            'status' => $response->status(),
            'provider_error' => $this->summariseBody((string) $response->body()),
        ]);
    }

    private function summariseBody(string $body): string
    {
        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            // Kasera error envelopes carry the reason at error.message.
            $message = $decoded['error']['message'] ?? $decoded['message'] ?? $decoded['error'] ?? null;

            if (is_string($message) && $message !== '') {
                return mb_substr($message, 0, 200);
            }
        }

        $text = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? $body);

        return mb_substr($text === '' ? 'empty response body' : $text, 0, 200);
    }

    /** A short, log-safe label for why the transport failed. */
    private function transportReason(ConnectionException $e): string
    {
        $message = $e->getMessage();

        return match (true) {
            (bool) preg_match('/timed out|timeout/i', $message) => 'timeout',
            stripos($message, 'Connection refused') !== false => 'connection refused',
            stripos($message, 'could not resolve host') !== false => 'dns resolution failed',
            stripos($message, 'SSL') !== false, stripos($message, 'certificate') !== false => 'tls failure',
            default => 'connection error',
        };
    }
}