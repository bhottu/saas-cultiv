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
    /** Placeholders that mean "somebody copied the example and never filled this in". */
    private const PLACEHOLDERS = ['your', 'changeme', 'xxx', 'placeholder', 'example', 'todo', 'null'];

    /**
     * Refuse to call the provider at all when the integration is not wired up.
     *
     * Without this the app happily POSTs with empty X-API-Key/X-API-Secret headers,
     * the provider answers 401, and the customer is told the gateway was
     * unreachable — which sends the operator looking at the network instead of at
     * the .env file that was never filled in. Failing here also happens before the
     * invoice/payment rows are written.
     *
     * Only the NAME of the missing variable is ever reported or logged, never a value.
     */
    private function assertConfigured(string $operation, array $context): void
    {
        foreach ([
            'QRISPW_API_KEY' => trim((string) config('services.qrispw.api_key')),
            'QRISPW_API_SECRET' => trim((string) config('services.qrispw.api_secret')),
        ] as $name => $value) {
            if ($value === '' || $this->looksLikePlaceholder($value)) {
                Log::error('qrispw.not_configured', $context + [
                    'endpoint' => $operation,
                    'missing_variable' => $name,
                ]);

                throw PaymentProviderException::notConfigured($operation, $name, $context);
            }
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
     * Refuse an amount the gateway can never accept.
     *
     * QRIS.PW answers anything under its minimum with a 400 "Minimum amount is
     * Rp 1,000". Production hit exactly that: the Pro plan's price had been set to 5
     * in the database, the provider refused it, and the customer was told "Unable to
     * create the payment request. Please try again." — advice that can never work,
     * because no amount of retrying changes a plan's price.
     *
     * Checking here means the operator gets a log line naming the plan, its price and
     * the floor, and the customer gets a configuration message instead of a retry
     * loop. It also avoids a pointless round-trip to the provider.
     */
    private function assertAmountAcceptable(string $operation, array $payload, array $context): void
    {
        $amount = (int) ($payload['amount'] ?? 0);
        $minimum = (int) config('services.qrispw.min_amount');

        if ($amount >= $minimum) {
            return;
        }

        Log::error('qrispw.amount_below_minimum', $context + [
            'endpoint' => $operation,
            'amount' => $amount,
            'provider_minimum' => $minimum,
            // The most likely cause by far: a plan priced below the gateway floor.
            'likely_cause' => 'check plans.price_monthly / price_yearly for this plan',
        ]);

        throw PaymentProviderException::amountBelowMinimum($operation, $amount, $minimum, $context);
    }

    /**
     * @param  array<string, mixed>  $context  ids for the log line; never a credential
     */
    public function createPayment(array $payload, array $context = []): array
    {
        $this->assertConfigured('create-payment', $context);
        $this->assertAmountAcceptable('create-payment', $payload, $context);

        try {
            $response = Http::withHeaders([
                    'X-API-Key' => config('services.qrispw.api_key'),
                    'X-API-Secret' => config('services.qrispw.api_secret'),
                ])
                ->timeout(15)
                // throw:false keeps the final failure as a response we can classify;
                // without it a 4xx/5xx would surface as an uncaught RequestException.
                ->retry(2, 300, throw: false)
                ->post(config('services.qrispw.endpoints.create'), $payload);
        } catch (ConnectionException $e) {
            // DNS failure, refused connection, TLS problem or timeout. There is no
            // HTTP status to classify, so this previously escaped as an uncaught
            // exception and the customer got a 500 instead of a retryable message.
            $reason = $this->transportReason($e);

            Log::error('qrispw.create.transport_failed', $context + [
                'endpoint' => 'create-payment',
                'reason' => $reason,
            ]);

            throw PaymentProviderException::transport('create-payment', $reason, $context);
        }

        if (! $response->successful()) {
            $this->logFailure('create-payment', $response, $context);

            throw PaymentProviderException::fromProviderResponse(
                $response->status(),
                'create-payment',
                $context
            );
        }

        $data = $response->json();

        if (! is_array($data) || empty($data['success'])) {
            $providerError = is_array($data) ? ($data['error'] ?? $data['message'] ?? null) : null;

            Log::error('qrispw.create.rejected', $context + [
                'endpoint' => 'create-payment',
                'status' => $response->status(),
                // The provider's own words are the diagnostic value here; truncated so
                // an HTML error page cannot flood the log.
                'provider_error' => $providerError ? mb_substr((string) $providerError, 0, 200) : 'unknown',
            ]);

            throw new PaymentProviderException('QRIS.PW create-payment rejected: '.($providerError ?? 'unknown'));
        }

        return $data;
    }

    /** Server-side status verification (fallback when webhook is delayed/lost). */
    public function checkStatus(string $transactionId, array $context = []): array
    {
        try {
            $response = Http::withHeaders([
                    'X-API-Key' => config('services.qrispw.api_key'),
                    'X-API-Secret' => config('services.qrispw.api_secret'),
                ])
                ->timeout(15)
                ->retry(2, 300, throw: false)
                ->get(config('services.qrispw.endpoints.status'), ['transaction_id' => $transactionId]);
        } catch (ConnectionException $e) {
            $reason = $this->transportReason($e);

            Log::error('qrispw.check.transport_failed', $context + [
                'endpoint' => 'check-payment',
                'reason' => $reason,
            ]);

            throw PaymentProviderException::transport('check-payment', $reason, $context);
        }

        if (! $response->successful()) {
            $this->logFailure('check-payment', $response, $context);

            throw PaymentProviderException::fromProviderResponse(
                $response->status(),
                'check-payment',
                $context
            );
        }

        return $response->json() ?? [];
    }

    /**
     * A single structured line per provider failure: endpoint, status and the
     * provider's own message, plus the tenant/user/payment ids for correlation.
     * Credentials, tokens and headers are never included.
     */
    private function logFailure(string $operation, $response, array $context): void
    {
        Log::error('qrispw.request_failed', $context + [
            'endpoint' => $operation,
            'status' => $response->status(),
            'provider_error' => $this->summariseBody((string) $response->body()),
        ]);
    }

    private function summariseBody(string $body): string
    {
        // JSON bodies carry the real reason ("invalid api key"); HTML error pages are
        // reduced to their text so one bad gateway cannot flood the log.
        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            $message = $decoded['error'] ?? $decoded['message'] ?? null;

            if ($message) {
                return mb_substr((string) $message, 0, 200);
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
