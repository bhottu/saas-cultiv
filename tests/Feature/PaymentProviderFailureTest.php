<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What the customer is told when QRIS.PW will not give us a payment.
 *
 * Production symptom: every failure produced the single sentence "We could not reach
 * the payment provider", for a missing API key, a rejected key, a malformed order, a
 * rate limit and an unreachable host alike. That hides the real cause from the
 * customer AND from the operator, and it hid a genuine 500: a timeout never became a
 * PaymentProviderException at all.
 *
 * These tests pin the behaviour per cause, and pin that credentials never reach a log.
 */
class PaymentProviderFailureTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlanSeeder::class);

        $this->owner = User::create([
            'name' => 'Paying Owner', 'email' => 'provider-failure@test.dev',
            'password' => bcrypt('password'), 'email_verified_at' => now(),
        ]);

        $this->tenant = Tenant::create([
            'name' => 'Provider Tenant', 'slug' => 'provider-tenant', 'owner_id' => $this->owner->id,
        ]);

        $this->tenant->users()->attach($this->owner->id, [
            'role' => 'Owner', 'status' => 'active', 'joined_at' => now(),
        ]);
    }

    /**
     * Test A — the happy path still works end to end: payment, redirect, QR.
     *
     * Guards the whole point of the other tests: sharper error reporting must not
     * have broken the success case.
     */
    public function test_a_a_working_provider_produces_a_payment_and_a_qr(): void
    {
        Http::fake([
            'qris.pw/*' => fn () => Http::response([
                'success' => true,
                'transaction_id' => 'TX-'.Str::random(24),
                'expires_at' => now()->addMinutes(10)->toIso8601String(),
                'qris_url' => 'https://example.test/qr.png',
            ]),
        ]);

        $res = $this->member()->post('/billing/checkout', ['plan' => 'starter', 'cycle' => 'monthly']);

        $payment = Payment::firstOrFail();

        $res->assertRedirect(route('billing.pay', $payment));
        $this->assertSame('pending', $payment->status);
        $this->assertNotNull($payment->provider_transaction_id);

        $this->member()->get(route('billing.pay', $payment))
            ->assertOk()
            ->assertSee('QRIS payment code', escape: false);
    }

    /**
     * Test B — bad credentials on the provider's side.
     *
     * The customer must be pointed at the administrator, not told the gateway is
     * unreachable, and the operator must get the status in the log.
     */
    public function test_b_a_rejected_api_key_reports_a_configuration_error_not_an_outage(): void
    {
        Http::fake([
            'qris.pw/*' => fn () => Http::response(['error' => 'invalid api key'], 401),
        ]);

        $res = $this->member()->post('/billing/checkout', ['plan' => 'starter', 'cycle' => 'monthly']);

        $res->assertRedirect(route('billing.index'));
        $res->assertSessionHas('error');
        $this->assertStringContainsString('Payment configuration error', session('error'));
        $this->assertStringNotContainsString('could not reach the payment provider', session('error'));

        // And it is visible on the page, not just sitting in the session.
        $this->assertStringContainsString('Payment configuration error', $this->billingHtml());

        // A refused gateway call must leave nothing behind to confuse the next attempt.
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, Invoice::count());
    }

    /**
     * Test B2 — the integration was never configured at all.
     *
     * This is the local .env state, and a very common production state after a config
     * cache is built before the keys are pasted in. The app used to fire a request
     * with empty headers and report the resulting 401 as an outage.
     */
    public function test_b2_missing_credentials_fail_before_any_request_is_made(): void
    {
        config(['services.qrispw.api_key' => '', 'services.qrispw.api_secret' => '']);

        Http::fake(); // any HTTP call at all would now be a test failure

        $res = $this->member()->post('/billing/checkout', ['plan' => 'starter', 'cycle' => 'monthly']);

        $res->assertRedirect(route('billing.index'));
        $this->assertStringContainsString('Payment configuration error', session('error'));

        Http::assertNothingSent();

        // No invoice and no payment were created for a request we never made.
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, Invoice::count());
    }

    /** An unfilled example value must be treated as missing, not sent to the provider. */
    public function test_b3_a_placeholder_credential_counts_as_missing(): void
    {
        config(['services.qrispw.api_key' => 'your-api-key-here', 'services.qrispw.api_secret' => 'test-secret']);

        Http::fake();

        $this->member()->post('/billing/checkout', ['plan' => 'starter', 'cycle' => 'monthly'])
            ->assertSessionHas('error');

        $this->assertStringContainsString('Payment configuration error', session('error'));
        Http::assertNothingSent();
    }

    /**
     * Test C — the provider is unreachable (timeout, refused connection, DNS).
     *
     * This is the case that used to escape as an uncaught ConnectionException and
     * render a 500, because ConnectionException was imported but never caught.
     */
    public function test_c_an_unreachable_provider_reports_a_temporary_outage_and_not_a_500(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out after 15000 milliseconds');
        });

        $res = $this->member()->post('/billing/checkout', ['plan' => 'starter', 'cycle' => 'monthly']);

        $res->assertRedirect(route('billing.index'));
        $this->assertStringContainsString(
            'The payment service is temporarily unavailable',
            session('error')
        );

        $this->assertSame(0, Payment::count());
        $this->assertSame(0, Invoice::count());
    }

    /** A refused connection is classified apart from a timeout. */
    public function test_c2_a_refused_connection_is_treated_as_a_transient_network_failure(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 7: Failed to connect to qris.pw port 443: Connection refused');
        });

        $this->member()->post('/billing/checkout', ['plan' => 'starter', 'cycle' => 'monthly'])
            ->assertSessionHas('error');

        $this->assertStringContainsString('temporarily unavailable', session('error'));
    }

    /** A rejected order is the customer's problem to retry, not an outage. */
    public function test_a_malformed_order_reports_a_retryable_request_error(): void
    {
        Http::fake(['qris.pw/*' => fn () => Http::response(['error' => 'amount is required'], 422)]);

        $this->member()->post('/billing/checkout', ['plan' => 'starter', 'cycle' => 'monthly'])
            ->assertSessionHas('error');

        $this->assertStringContainsString('Unable to create the payment request', session('error'));
        $this->assertStringNotContainsString('configuration error', session('error'));
    }

    /** A 200 response that reports success:false is a rejection, not a silent payment. */
    public function test_a_success_false_body_is_rejected_rather_than_treated_as_paid(): void
    {
        Http::fake(['qris.pw/*' => fn () => Http::response(['success' => false, 'error' => 'shop is closed'])]);

        $this->member()->post('/billing/checkout', ['plan' => 'starter', 'cycle' => 'monthly'])
            ->assertSessionHas('error');

        $this->assertSame(0, Payment::count());
    }

    /**
     * The operator log must be actionable and must never contain a credential.
     */
    public function test_failures_are_logged_with_ids_and_without_any_secret(): void
    {
        Log::spy();

        Http::fake(['qris.pw/*' => fn () => Http::response(['error' => 'invalid api key'], 401)]);

        $this->member()->post('/billing/checkout', ['plan' => 'starter', 'cycle' => 'monthly']);

        Log::shouldHaveReceived('error')
            ->withArgs(function (string $message, array $context = []) {
                if ($message !== 'qrispw.request_failed') {
                    return true;
                }

                // Correlation: which workspace, which user, which attempt.
                $this->assertSame('create-payment', $context['endpoint'] ?? null);
                $this->assertSame(401, $context['status'] ?? null);
                $this->assertSame($this->tenant->id, $context['tenant_id'] ?? null);
                $this->assertSame($this->owner->id, $context['user_id'] ?? null);
                $this->assertArrayHasKey('payment_id', $context);
                $this->assertSame('invalid api key', $context['provider_error'] ?? null);

                return true;
            })
            ->atLeast()->once();

        // No log line may carry the key, the secret, or even the header names.
        $serialised = $this->serialisedLogRecords();

        foreach (['test-secret', 'test-key', 'X-API-Secret', 'X-API-Key'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialised);
        }
    }

    /** An unreachable provider is logged as a transport failure with a safe reason. */
    public function test_a_transport_failure_is_logged_with_a_classified_reason(): void
    {
        Log::spy();

        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out after 15000 milliseconds');
        });

        $this->member()->post('/billing/checkout', ['plan' => 'starter', 'cycle' => 'monthly']);

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context = []) => $message === 'qrispw.create.transport_failed'
                && ($context['reason'] ?? null) === 'timeout')
            ->atLeast()->once();
    }

    // ------------------------------------------------------------- helpers

    private function member()
    {
        return $this->actingAs($this->owner)->withSession(['tenant_id' => $this->tenant->id]);
    }

    private function billingHtml(): string
    {
        return $this->member()->get('/billing')->assertOk()->getContent();
    }

    /** Everything written to the log during the test, so a secret sweep is meaningful. */
    private function serialisedLogRecords(): string
    {
        $records = [];

        foreach (\Illuminate\Support\Facades\Log::getFacadeRoot()?->records ?? [] as $record) {
            $records[] = [$record['message'] ?? null, $record['context'] ?? []];
        }

        return json_encode($records) ?: '';
    }
}