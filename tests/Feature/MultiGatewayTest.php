<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentSetting;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Two gateways behind one contract: selection, dispatch, webhook and reconcile.
 *
 * What matters here is not "the form saves" but the routing rules:
 *  - a NEW checkout goes to the gateway the administrator selected (and only to it),
 *  - the payment row remembers that choice, so dispatch and reconcile are keyed off
 *    payments.provider rather than off whatever is active today,
 *  - an unconfigured gateway can never be selected (it would break billing for
 *    every workspace at once),
 *  - Kasera's header signature is verified over the RAW body, is idempotent, and
 *    obeys the "money wins" rule after the local window closed.
 */
class MultiGatewayTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $owner;

    private Tenant $tenant;

    private string $whSecret = 'kpwhsec_test_456';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlanSeeder::class);

        config([
            'services.kasera.webhook_secret' => $this->whSecret,
            'services.kasera.api_key' => 'kp_test_abc123',
        ]);

        $this->admin = User::create([
            'name' => 'Platform Admin', 'email' => 'admin-gw@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
            'is_platform_admin' => true,
        ]);

        $this->owner = User::create([
            'name' => 'GW Owner', 'email' => 'gw-owner@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);

        $this->tenant = Tenant::create([
            'name' => 'GW Tenant', 'slug' => 'gw-tenant', 'owner_id' => $this->owner->id,
        ]);
        $this->tenant->users()->attach($this->owner->id, [
            'role' => 'Owner', 'status' => 'active', 'joined_at' => now(),
        ]);
    }

    // ------------------------------------------------------------ authorization

    public function test_only_a_platform_admin_can_open_the_gateway_screen(): void
    {
        $this->asAdmin()->get('/admin/billing')->assertOk();

        $this->asMember()->get('/admin/billing')->assertForbidden();
    }

    public function test_the_gateway_screen_survives_a_database_that_was_never_migrated(): void
    {
        // Regression for the production 500: relation "payment_settings" does not exist.
        // The screen must render on the config default (with the migrate banner) and
        // refuse the save with a message that names the fix.
        \Illuminate\Support\Facades\Schema::dropIfExists('payment_settings');

        $this->assertFalse(PaymentSetting::isMigrated());
        $this->assertSame('qrispw', PaymentSetting::activeGateway());

        $this->asAdmin()->get('/admin/billing')
            ->assertOk()
            ->assertSee('payment_settings table is missing', false);

        $this->asAdmin()->put('/admin/billing', ['active_gateway' => 'kasera'])
            ->assertSessionHasErrors('active_gateway');
    }

    public function test_a_normal_user_cannot_change_the_active_gateway(): void
    {
        $this->asMember()->put('/admin/billing', ['active_gateway' => 'kasera'])->assertForbidden();

        $this->assertSame('qrispw', PaymentSetting::activeGateway());
    }

    public function test_an_anonymous_visitor_cannot_reach_the_gateway_screen(): void
    {
        $this->get('/admin/billing')->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------ saving

    public function test_a_gateway_choice_is_saved_and_audited(): void
    {
        $this->asAdmin()->put('/admin/billing', ['active_gateway' => 'kasera'])
            ->assertRedirect(route('admin.billing.edit'));

        $this->assertSame('kasera', PaymentSetting::activeGateway());
        $this->assertDatabaseHas('payment_settings', ['active_gateway' => 'kasera']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.gateway_updated']);

        // The saved choice is what the form shows on reload.
        $this->asAdmin()->get('/admin/billing')->assertOk()->assertSee('Kasera Pay');
    }

    public function test_an_unconfigured_gateway_cannot_be_selected(): void
    {
        config(['services.kasera.api_key' => '']);

        $this->asAdmin()->put('/admin/billing', ['active_gateway' => 'kasera'])
            ->assertSessionHasErrors('active_gateway');

        // Still QRIS.PW: saving a gateway with no key would break every checkout.
        $this->assertSame('qrispw', PaymentSetting::activeGateway());
    }

    public function test_an_unknown_gateway_value_is_refused(): void
    {
        $this->asAdmin()->put('/admin/billing', ['active_gateway' => 'stripe'])
            ->assertSessionHasErrors('active_gateway');

        $this->assertSame('qrispw', PaymentSetting::activeGateway());
    }

    public function test_qrispw_is_the_default_when_no_row_exists(): void
    {
        $this->assertNull(PaymentSetting::query()->first());
        $this->assertSame('qrispw', PaymentSetting::activeGateway());
    }

    // --------------------------------------------------------------- dispatch

    public function test_a_new_checkout_is_sent_only_to_the_selected_gateway(): void
    {
        $this->asAdmin()->put('/admin/billing', ['active_gateway' => 'kasera'])->assertRedirect();

        $this->fakeKaseraCreate();

        $this->member()->post('/billing/checkout', ['plan' => 'starter', 'cycle' => 'monthly'])
            ->assertRedirect();

        $payment = Payment::firstOrFail();

        $this->assertSame('kasera', $payment->provider);
        $this->assertSame('payreq_abc123', $payment->provider_transaction_id);
        $this->assertSame(
            'https://pay.kasera.id/checkout/session-1',
            $payment->payload['create']['checkout_url']
        );
        $this->assertSame(39000, (int) $payment->amount);

        // Nothing may have been sent to the other gateway.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'qris.pw'));

        // The pay page offers the hosted checkout link Kasera returned.
        $html = $this->member()->get('/billing/payments/'.$payment->id)->assertOk()->getContent();
        $this->assertStringContainsString('https://pay.kasera.id/checkout/session-1', $html);
        $this->assertStringContainsString('Pay now', $html);
    }

    public function test_a_payment_keeps_its_gateway_when_the_platform_switches(): void
    {
        // Payment created under Kasera…
        $this->asAdmin()->put('/admin/billing', ['active_gateway' => 'kasera'])->assertRedirect();

        // ONE method-aware stub for the whole lifecycle: POST creates (pending),
        // GET retrieves (succeeded). Registering a second Http::fake mid-test would
        // race the first one for the same URL pattern.
        Http::fake(function ($request) {
            if ($request->method() === 'POST') {
                return Http::response([
                    'id' => 'payreq_abc123',
                    'status' => 'pending',
                    'amount' => 39000,
                    'checkout_url' => 'https://pay.kasera.id/checkout/session-1',
                    'expires_at' => now()->addMinutes(60)->toIso8601String(),
                ], 201);
            }

            return Http::response([
                'id' => 'payreq_abc123', 'status' => 'succeeded', 'amount' => 39000,
            ], 200);
        });

        $this->member()->post('/billing/checkout', ['plan' => 'starter', 'cycle' => 'monthly']);
        $payment = Payment::firstOrFail();

        // …then the administrator switches back to QRIS.PW.
        $this->asAdmin()->put('/admin/billing', ['active_gateway' => 'qrispw'])->assertRedirect();

        // Reconcile must still ask KASERA about it — that is who holds the money.
        $status = app(\App\Services\PaymentService::class)->reconcile($payment->fresh());

        $this->assertSame('paid', $status);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'pay.kasera.id'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'qris.pw'));
    }

    public function test_a_kasera_checkout_without_a_key_leaves_no_rows(): void
    {
        config(['services.kasera.api_key' => '']);

        // Simulate an already-saved selection (the form refuses to save this state,
        // but a key revoked AFTER selection must fail safely too).
        PaymentSetting::current()->update(['active_gateway' => 'kasera']);

        $this->member()->post('/billing/checkout', ['plan' => 'starter', 'cycle' => 'monthly'])
            ->assertRedirect(route('billing.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, Payment::count());
        $this->assertSame(0, Invoice::count());
    }

    // ---------------------------------------------------------------- webhook

    public function test_valid_webhook_pays_and_activates_subscription_once(): void
    {
        $this->kaseraPendingPayment();

        $event = $this->event('payment.paid');
        $this->postKasera($event, $this->v1Header(json_encode($event)))->assertOk();

        $this->assertDatabaseHas('payments', ['order_id' => 'ORD-GW-1', 'status' => 'paid']);
        $this->assertDatabaseHas('invoices', ['invoice_number' => 'INV-GW-000001', 'status' => 'paid']);

        $sub = Subscription::where('tenant_id', $this->tenant->id)->where('status', 'active')->first();
        $this->assertNotNull($sub);
        $this->assertEquals($this->plan()->id, $sub->plan_id);
    }

    public function test_webhook_replays_are_processed_exactly_once(): void
    {
        $this->kaseraPendingPayment();
        $event = $this->event('payment.paid');
        $header = $this->v1Header(json_encode($event));

        $this->postKasera($event, $header)->assertOk();
        $this->postKasera($event, $header)->assertOk();
        $this->postKasera($event, $header)->assertOk();

        $this->assertSame(1, WebhookEvent::count());
        $this->assertSame(1, WebhookEvent::where('status', 'processed')->count());
        $this->assertSame(1, Subscription::where('tenant_id', $this->tenant->id)->where('status', 'active')->count());
        $this->assertSame(1, Invoice::where('status', 'paid')->count());
    }

    public function test_webhook_rejects_an_invalid_signature(): void
    {
        $this->kaseraPendingPayment();

        $this->postKasera($this->event('payment.paid'), 't='.time().',v1=deadbeef')
            ->assertStatus(403);

        $this->assertDatabaseHas('payments', ['order_id' => 'ORD-GW-1', 'status' => 'pending']);
        $this->assertDatabaseHas('webhook_events', ['signature_valid' => false, 'status' => 'rejected']);
    }

    public function test_webhook_rejects_a_signature_outside_the_timestamp_window(): void
    {
        $this->kaseraPendingPayment();

        // A captured body + correct HMAC, but signed five minutes ago: the timestamp is
        // inside the signed string, so this must fail the 300s tolerance, not replay.
        $event = $this->event('payment.paid');
        $stale = time() - 400;
        $sig = hash_hmac('sha256', $stale.'.'.json_encode($event), $this->whSecret);

        $this->postKasera($event, "t={$stale},v1={$sig}")
            ->assertStatus(403);

        $this->assertDatabaseHas('payments', ['order_id' => 'ORD-GW-1', 'status' => 'pending']);
    }

    public function test_a_missing_webhook_secret_rejects_everything(): void
    {
        $this->kaseraPendingPayment();
        config(['services.kasera.webhook_secret' => '']);

        // Even a "correct" signature is meaningless when the platform has no secret:
        // nothing is ever accepted by default.
        $event = $this->event('payment.paid');
        $raw = json_encode($event);
        $sig = hash_hmac('sha256', time().'.'.$raw, '');

        $this->postKasera($event, 't='.time().",v1={$sig}")->assertStatus(403);

        $this->assertDatabaseHas('payments', ['order_id' => 'ORD-GW-1', 'status' => 'pending']);
    }

    public function test_the_legacy_signature_header_is_still_accepted(): void
    {
        $this->kaseraPendingPayment();

        $event = $this->event('payment.paid');
        $sig = hash_hmac('sha256', json_encode($event), $this->whSecret);

        $this->postKasera($event, $sig, 'Kasera-Signature')->assertOk();

        $this->assertDatabaseHas('payments', ['order_id' => 'ORD-GW-1', 'status' => 'paid']);
    }

    public function test_webhook_rejects_an_amount_mismatch(): void
    {
        $this->kaseraPendingPayment();

        $event = $this->event('payment.paid');
        $event['data']['amount'] = 1;

        $this->postKasera($event, $this->v1Header(json_encode($event)))->assertOk();

        $this->assertDatabaseHas('payments', ['order_id' => 'ORD-GW-1', 'status' => 'pending']);
        $this->assertDatabaseHas('webhook_events', ['status' => 'rejected', 'note' => 'amount mismatch']);
    }

    public function test_money_that_arrives_after_the_local_window_still_activates(): void
    {
        $payment = $this->kaseraPendingPayment();

        // Our 10-minute window closed first (the invoice went with it)…
        $payment->update(['status' => 'expired']);
        $payment->invoice->update(['status' => 'cancelled']);

        // …then Kasera reports the money. Their spec says the money wins.
        $event = $this->event('payment.paid');
        $this->postKasera($event, $this->v1Header(json_encode($event)))->assertOk();

        $this->assertDatabaseHas('payments', ['order_id' => 'ORD-GW-1', 'status' => 'paid']);
        $this->assertDatabaseHas('invoices', ['invoice_number' => 'INV-GW-000001', 'status' => 'paid']);
        $this->assertSame(1, Subscription::where('tenant_id', $this->tenant->id)->where('status', 'active')->count());
    }

    public function test_an_expired_event_never_activates(): void
    {
        $this->kaseraPendingPayment();

        $event = $this->event('payment.expired');
        $this->postKasera($event, $this->v1Header(json_encode($event)))->assertOk();

        $this->assertDatabaseHas('payments', ['order_id' => 'ORD-GW-1', 'status' => 'expired']);
        $this->assertSame(0, Subscription::where('tenant_id', $this->tenant->id)->where('status', 'active')->count());
    }

    // -------------------------------------------------------------- reconcile

    public function test_reconcile_marks_a_succeeded_kasera_transaction_paid(): void
    {
        $payment = $this->kaseraPendingPayment();

        Http::fake([
            'pay.kasera.id/*' => Http::response([
                'id' => 'payreq_gw1', 'status' => 'succeeded', 'amount' => 39000,
            ], 200),
        ]);

        $status = app(\App\Services\PaymentService::class)->reconcile($payment->fresh());

        $this->assertSame('paid', $status);
        $this->assertDatabaseHas('payments', ['order_id' => 'ORD-GW-1', 'status' => 'paid']);
        $this->assertSame(1, Subscription::where('tenant_id', $this->tenant->id)->where('status', 'active')->count());
    }

    public function test_reconcile_maps_a_canceled_transaction_to_cancelled(): void
    {
        $payment = $this->kaseraPendingPayment();

        Http::fake([
            'pay.kasera.id/*' => Http::response([
                'id' => 'payreq_gw1', 'status' => 'canceled', 'amount' => 39000,
            ], 200),
        ]);

        $status = app(\App\Services\PaymentService::class)->reconcile($payment->fresh());

        $this->assertSame('cancelled', $status);
        $this->assertDatabaseHas('payments', ['order_id' => 'ORD-GW-1', 'status' => 'cancelled']);
        $this->assertSame(0, Subscription::where('status', 'active')->count());
    }

    // ================================================================ helpers

    private function plan(): Plan
    {
        return Plan::where('slug', 'starter')->firstOrFail();
    }

    private function asAdmin()
    {
        return $this->actingAs($this->admin)->withSession(['tenant_id' => $this->tenant->id]);
    }

    private function asMember()
    {
        return $this->actingAs($this->owner)->withSession(['tenant_id' => $this->tenant->id]);
    }

    private function member()
    {
        return $this->asMember();
    }

    /** A pending Kasera payment, exactly as createCheckout would have written it. */
    private function kaseraPendingPayment(): Payment
    {
        $invoice = Invoice::create([
            'tenant_id' => $this->tenant->id,
            'invoice_number' => 'INV-GW-000001',
            'amount' => 39000, 'currency' => 'IDR', 'status' => 'open',
            'metadata' => ['plan_id' => $this->plan()->id, 'billing_cycle' => 'monthly', 'period_months' => 1],
        ]);

        return Payment::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id,
            'invoice_id' => $invoice->id, 'provider' => 'kasera',
            'order_id' => 'ORD-GW-1', 'amount' => 39000, 'currency' => 'IDR',
            'status' => 'pending', 'provider_transaction_id' => 'payreq_gw1',
            'expires_at' => now()->addMinutes(10),
        ]);
    }

    private function event(string $type): array
    {
        return [
            'id' => 'evt_'.substr(sha1($type), 0, 12),
            'type' => $type,
            'livemode' => false,
            'created_at' => now()->toIso8601String(),
            'data' => [
                'payment_request_id' => 'payreq_gw1',
                'external_id' => 'ORD-GW-1',
                'merchant_ref' => 'INV-GW-000001',
                'amount' => 39000,
                'currency' => 'IDR',
                'status' => $type === 'payment.paid' ? 'succeeded' : $type,
                'paid_at' => now()->toIso8601String(),
            ],
        ];
    }

    /** Signed over the exact bytes that will be POSTed — Kasera signs the raw body. */
    private function v1Header(string $raw, ?int $timestamp = null): string
    {
        $t = $timestamp ?? time();

        return 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$raw, $this->whSecret);
    }

    /**
     * POST the webhook with byte-exact control of the body (the HMAC is over the raw
     * JSON), sending each header name as-is through the server bag.
     */
    private function postKasera(array $payload, ?string $signature, string $headerName = 'Kasera-Signature-V1')
    {
        $raw = json_encode($payload);

        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        if ($signature !== null) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $headerName))] = $signature;
        }

        return $this->call('POST', '/api/webhooks/kasera', [], [], [], $server, $raw);
    }

    private function fakeKaseraCreate(): void
    {
        Http::fake([
            'pay.kasera.id/*' => Http::response([
                'id' => 'payreq_abc123',
                'status' => 'pending',
                'amount' => 39000,
                'currency' => 'IDR',
                'checkout_url' => 'https://pay.kasera.id/checkout/session-1',
                'expires_at' => now()->addMinutes(60)->toIso8601String(),
                'payment' => ['qr_string' => '00020101021126610014COM.KASERA.WWW01189360091421234567890210'],
            ], 201),
            'qris.pw/*' => Http::response(['error' => 'must not be called'], 500),
        ]);
    }
}