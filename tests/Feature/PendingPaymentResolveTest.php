<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The "you already have a payment waiting" decision (Test A-E).
 *
 * Production defect this file exists for: both buttons in that modal appeared to do
 * nothing. The cause was client-side, not in this controller — the shared
 * submit-button handler disabled the submitter inside the capture-phase `submit`
 * listener, and the browser builds a form's entry list only *after* that event, so
 * `name="intent"` was stripped from the POST. The request was rejected as invalid,
 * the customer landed back on an unchanged /billing, and the error was invisible.
 *
 * These tests pin the server-side contract the buttons depend on: `intent` must be
 * the only thing distinguishing the two actions, and every refusal must be a visible
 * status rather than a silent no-op.
 */
class PendingPaymentResolveTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlanSeeder::class);

        $this->owner = User::create([
            'name' => 'Billing Owner', 'email' => 'resolve-owner@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);

        $this->tenant = Tenant::create([
            'name' => 'Resolve Tenant', 'slug' => 'resolve-tenant', 'owner_id' => $this->owner->id,
        ]);

        $this->tenant->users()->attach($this->owner->id, [
            'role' => 'Owner', 'status' => 'active', 'joined_at' => now(),
        ]);
    }

    /**
     * Test A — the payment is still pending and still inside its window.
     *
     * "Continue payment" must land on the checkout for the payment that already
     * exists, with the QR rendered, and must not create a second payment.
     */
    public function test_a_continue_payment_reuses_the_existing_payment_and_shows_the_qr(): void
    {
        $this->fakeGateway();
        $existing = $this->paymentWithQr(now()->addMinutes(10));

        $res = $this->member()->post('/billing/checkout/resolve', [
            'payment_id' => $existing->id,
            'invoice_id' => $existing->invoice_id,
            'invoice_id' => $existing->invoice_id,
            'plan' => 'starter',
            'cycle' => 'monthly',
            'intent' => 'continue',
        ]);

        $res->assertRedirect(route('billing.pay', $existing));

        // Reusing the record, not replacing it.
        $this->assertSame(1, Payment::count(), 'Continue payment must not create a payment.');
        $this->assertSame('pending', $existing->fresh()->status);
        $this->assertNull($existing->fresh()->cancelled_at);

        // And the QR is really on the page, not just a redirect.
        $this->member()
            ->get(route('billing.pay', $existing))
            ->assertOk()
            ->assertSee('QRIS payment code', escape: false);
    }

    /**
     * Test B — cancel the old payment and build a new one.
     *
     * The old row must survive as `cancelled` with a timestamp and a cancelled
     * invoice. Nothing is ever deleted: the history is the audit trail.
     */
    public function test_b_cancel_and_create_new_cancels_the_old_one_and_opens_a_new_checkout(): void
    {
        $this->fakeGateway();
        $existing = $this->paymentWithQr(now()->addMinutes(10));
        $oldInvoiceId = $existing->invoice_id;

        $res = $this->member()->post('/billing/checkout/resolve', [
            'payment_id' => $existing->id,
            'invoice_id' => $existing->invoice_id,
            'invoice_id' => $existing->invoice_id,
            'plan' => 'starter',
            'cycle' => 'monthly',
            'intent' => 'replace',
        ]);

        $old = $existing->fresh();
        $new = Payment::where('id', '!=', $old->id)->firstOrFail();

        $res->assertRedirect(route('billing.pay', $new));

        // pending -> cancelled, kept on disk.
        $this->assertSame('cancelled', $old->status);
        $this->assertNotNull($old->cancelled_at, 'A cancellation must be timestamped.');
        $this->assertSame('cancelled', Invoice::find($oldInvoiceId)->status);

        // A genuinely new checkout for the new order.
        $this->assertSame('pending', $new->status);
        $this->assertNotSame($old->invoice_id, $new->invoice_id);
        $this->assertSame(2, Payment::count());
        $this->assertSame(1, Payment::where('status', 'pending')->count());

        $this->member()
            ->get(route('billing.pay', $new))
            ->assertOk()
            ->assertSee('QRIS payment code', escape: false);
    }

    /**
     * Test C — the pending payment has already expired.
     *
     * "Continue payment" must not be offered, and subscribing must go straight to a
     * fresh payment instead of dead-ending on a QR that can no longer be paid.
     */
    public function test_c_expired_payment_is_not_offered_and_does_not_block_a_new_one(): void
    {
        $this->fakeGateway();
        $dead = $this->paymentWithQr(now()->subMinute());

        $res = $this->member()->post('/billing/checkout', ['plan' => 'starter', 'cycle' => 'monthly']);

        $new = Payment::where('status', 'pending')->firstOrFail();

        $res->assertRedirect(route('billing.pay', $new));
        $this->assertSame('expired', $dead->fresh()->status, 'A stale pending row must be settled, not left pending.');
        $this->assertNull(session('pending_checkout'), 'An expired payment must not raise the modal.');
        $this->assertStringNotContainsString('Continue payment', $this->billingHtml());
    }

    /**
     * Test D — the payment already succeeded.
     *
     * Cancelling a paid payment would destroy a real sale, so the server refuses
     * and says so. This is the rule the report called out as non-negotiable.
     */
    public function test_d_a_paid_payment_can_never_be_cancelled(): void
    {
        $this->fakeGateway();
        $paid = $this->paymentWithQr(now()->addMinutes(10), status: 'paid', paidAt: now());

        $res = $this->member()->post('/billing/checkout/resolve', [
            'payment_id' => $paid->id,
            'invoice_id' => $paid->invoice_id,
            'plan' => 'starter',
            'cycle' => 'monthly',
            'intent' => 'replace',
        ]);

        $res->assertRedirect(route('billing.index'));

        $this->assertSame('paid', $paid->fresh()->status);
        $this->assertNull($paid->fresh()->cancelled_at);
        $this->assertSame(1, Payment::count(), 'No replacement payment for a completed order.');

        // The refusal is reported instead of being swallowed.
        $this->assertStringContainsString('cannot be cancelled', session('status')['message']);
        $this->assertStringContainsString('cannot be cancelled', $this->billingHtml());
    }

    /**
     * Test E — the button is clicked twice in quick succession.
     *
     * Two cancel requests must not produce two cancellations or two replacements.
     * The client-side double-submit guard is one half of this; the row lock and the
     * already-cancelled status check on the server are the other half, and this
     * test covers the half that survives a disabled button, a slow network, or JS
     * that never ran.
     */
    public function test_e_repeated_clicks_cannot_cancel_twice_or_create_two_checkouts(): void
    {
        $this->fakeGateway();
        $existing = $this->paymentWithQr(now()->addMinutes(10));

        foreach (range(1, 4) as $ignored) {
            $this->member()->post('/billing/checkout/resolve', [
                'payment_id' => $existing->id,
                'invoice_id' => $existing->invoice_id,
                'invoice_id' => $existing->invoice_id,
                'plan' => 'starter',
                'cycle' => 'monthly',
                'intent' => 'replace',
            ]);
        }

        // Exactly one replacement, no matter how many requests arrived.
        $this->assertSame(2, Payment::count(), 'Repeated clicks must not open extra checkouts.');
        $this->assertSame(1, Payment::where('status', 'pending')->count());

        $old = $existing->fresh();
        $this->assertSame('cancelled', $old->status);
        $this->assertNotNull($old->cancelled_at);
    }

    /**
     * Scenario A — a live payment exists (created < 10 minutes ago), so the prompt shows.
     *
     * This is the exact reported path: subscribe Starter, land on the barcode, press the
     * browser Back button, then subscribe a different plan.
     */
    public function test_scenario_a_a_live_pending_payment_prompts_before_a_second_plan(): void
    {
        $this->fakeGateway();

        $this->member()->post('/billing/checkout', ['plan' => 'starter', 'cycle' => 'monthly']);
        $starter = Payment::firstOrFail();

        // The customer browses away and comes back to /billing.
        $this->assertStringContainsString('Pending payment', $this->billingHtml());

        // Now they subscribe a different plan.
        $res = $this->member()->post('/billing/checkout', ['plan' => 'pro', 'cycle' => 'monthly']);

        $res->assertRedirect(route('billing.index'));
        $res->assertSessionHas('pending_checkout');

        // Read the payload now: a flash lives for exactly one request, and the GET
        // below (which renders the prompt) consumes it.
        $payload = session('pending_checkout');
        $this->assertSame($starter->id, $payload['payment_id']);
        $this->assertSame($starter->invoice_id, $payload['invoice_id']);
        $this->assertSame('pro', $payload['plan']);

        // The prompt describes the payment already on file, and offers both choices.
        $html = $this->member()->get('/billing')->assertOk()->getContent();
        $this->assertStringContainsString('Payment pending', $html);
        $this->assertStringContainsString('Continue payment', $html);
        // The ampersand is HTML-escaped on render.
        $this->assertStringContainsString('Cancel &amp; create new', $html);

        // No second payment was created by merely asking.
        $this->assertSame(1, Payment::count());
    }

    /** The window is exactly ten minutes from creation, not the provider's choice. */
    public function test_scenario_a_a_new_payment_is_payable_for_exactly_ten_minutes(): void
    {
        $this->fakeGateway();

        $this->member()->post('/billing/checkout', ['plan' => 'starter', 'cycle' => 'monthly']);

        $payment = Payment::firstOrFail();

        $this->assertTrue(
            $payment->isActivePending(),
            'A payment created seconds ago must be payable.'
        );
        $this->assertEqualsWithDelta(
            10,
            $payment->created_at->diffInMinutes($payment->expires_at, false),
            0.05,
            'A pending payment must expire exactly PAYMENT_WINDOW_MINUTES after it was created.'
        );
    }

    /**
     * Scenario B — the window has closed.
     *
     * No prompt, the stale row is settled to `expired`, and the new plan is simply
     * allowed through.
     */
    public function test_scenario_b_an_expired_payment_neither_prompts_nor_blocks(): void
    {
        $this->fakeGateway();
        $dead = $this->paymentWithQr(now()->subMinute());

        $res = $this->member()->post('/billing/checkout', ['plan' => 'pro', 'cycle' => 'monthly']);

        $fresh = Payment::where('status', 'pending')->firstOrFail();

        $res->assertRedirect(route('billing.pay', $fresh));
        $this->assertNull(session('pending_checkout'), 'An expired payment must not raise the prompt.');
        $this->assertSame('expired', $dead->fresh()->status, 'The stale row must be settled, not left pending.');
        $this->assertSame(2, Payment::count());
    }
    /**
     * Scenario C — "Continue payment" returns to the barcode.
     *
     * The reported failure was "The intent field is required." These assertions are
     * the ones that regression protects: the redirect must land on the existing
     * payment's checkout page, with its QR, and create nothing.
     */
    public function test_scenario_c_continue_payment_returns_to_the_barcode(): void
    {
        $this->fakeGateway();
        $existing = $this->paymentWithQr(now()->addMinutes(9));

        $res = $this->member()->post('/billing/checkout/resolve', [
            'intent' => 'continue',
            'payment_id' => $existing->id,
            'invoice_id' => $existing->invoice_id,
            'plan' => 'pro',
            'cycle' => 'monthly',
        ]);

        // Straight to the checkout for the payment that already exists.
        $res->assertRedirect(route('billing.pay', $existing));
        $res->assertSessionHasNoErrors();

        // The QR is on that page.
        $this->member()->get(route('billing.pay', $existing))
            ->assertOk()
            ->assertSee('QRIS payment code', escape: false);

        // And nothing was created, cancelled or altered.
        $this->assertSame(1, Payment::count());
        $this->assertSame('pending', $existing->fresh()->status);
        $this->assertNull($existing->fresh()->cancelled_at);
    }

    /** A payload without `intent` is still rejected — but never silently half-applied. */
    public function test_scenario_c_a_missing_intent_is_rejected_without_side_effects(): void
    {
        $this->fakeGateway();
        $existing = $this->paymentWithQr(now()->addMinutes(9));

        $this->member()->post('/billing/checkout/resolve', [
            'payment_id' => $existing->id,
            'invoice_id' => $existing->invoice_id,
            'plan' => 'pro',
            'cycle' => 'monthly',
        ])->assertSessionHasErrors('intent');

        $this->assertSame('pending', $existing->fresh()->status);
        $this->assertSame(1, Payment::count());
    }

    /** The invoice in the payload must belong to the payment, or nothing is touched. */
    public function test_a_mismatched_invoice_is_refused(): void
    {
        $this->fakeGateway();
        $existing = $this->paymentWithQr(now()->addMinutes(9));
        $otherInvoice = Invoice::create([
            'tenant_id' => $this->tenant->id, 'invoice_number' => 'INV-X-'.Str::random(8),
            'amount' => 79_000, 'currency' => 'IDR', 'status' => 'open',
            'description' => 'other', 'issued_at' => now(), 'due_at' => now()->addHour(),
        ]);

        $res = $this->member()->post('/billing/checkout/resolve', [
            'intent' => 'replace',
            'payment_id' => $existing->id,
            'invoice_id' => $otherInvoice->id,
            'plan' => 'pro',
            'cycle' => 'monthly',
        ]);

        $res->assertRedirect(route('billing.index'));
        $this->assertSame('pending', $existing->fresh()->status, 'A mismatched payload must not cancel anything.');
        $this->assertSame(1, Payment::count());
    }

    /**
 * Scenario 1 — a previously cancelled payment must not block a new checkout.
 *
 * This is the reported production situation: INV-2026-000005 (Rp 39.000) was cancelled,
 * and the customer still could not subscribe. `cancelled` is terminal but not live, so
 * it must never be treated as an active pending payment.
 */
public function test_scenario_1_a_cancelled_payment_never_blocks_a_new_one(): void
{
    $this->fakeGateway();

    // The historical cancelled invoice from production, kept as audit history.
    $cancelledInvoice = Invoice::create([
        'tenant_id' => $this->tenant->id, 'invoice_number' => 'INV-2026-000005',
        'amount' => 39_000, 'currency' => 'IDR', 'status' => 'cancelled',
        'description' => 'Starter (monthly) subscription',
        'issued_at' => now()->subHour(), 'due_at' => now()->subHour(),
    ]);
    $cancelled = Payment::create([
        'tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id,
        'invoice_id' => $cancelledInvoice->id, 'order_id' => 'ORD-PAGVK7SRHDXWY',
        'amount' => 39_000, 'currency' => 'IDR', 'status' => 'cancelled',
        'cancelled_at' => now()->subMinutes(30), 'expires_at' => now()->addMinutes(5),
    ]);

    // A fresh subscription must go straight through.
    $res = $this->member()->post('/billing/checkout', ['plan' => 'pro', 'cycle' => 'monthly']);

    $fresh = Payment::where('status', 'pending')->firstOrFail();

    $res->assertRedirect(route('billing.pay', $fresh));
    $res->assertSessionHasNoErrors();
    $this->assertNull(session('pending_checkout'), 'A cancelled payment must not raise the prompt.');

    // Brand new identifiers, and the old record untouched.
    $this->assertNotSame($cancelled->order_id, $fresh->order_id);
    $this->assertNotSame($cancelledInvoice->id, $fresh->invoice_id);
    $this->assertNotNull($fresh->provider_transaction_id);
    $this->assertSame('cancelled', $cancelled->fresh()->status);
    $this->assertDatabaseHas('invoices', ['invoice_number' => 'INV-2026-000005', 'status' => 'cancelled']);
}

/** Every terminal status is ignored when looking for a blocking payment. */
public function test_scenario_1_every_terminal_status_is_ignored_when_looking_for_a_block(): void
{
    $this->fakeGateway();

    $invoice = Invoice::create([
        'tenant_id' => $this->tenant->id, 'invoice_number' => 'INV-TERM-'.Str::random(6),
        'amount' => 39_000, 'currency' => 'IDR', 'status' => 'cancelled',
        'description' => 'history', 'issued_at' => now(), 'due_at' => now()->addHour(),
    ]);

    // cancelled / expired / failed all sit in this workspace's history. None of them is
    // an active pending payment, so none may hold up a new checkout.
    foreach (['cancelled', 'expired', 'failed'] as $status) {
        Payment::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id,
            'invoice_id' => $invoice->id, 'order_id' => 'ORD-'.Str::random(14),
            'amount' => 39_000, 'currency' => 'IDR', 'status' => $status,
            // A future expiry on purpose: even a stale-dated terminal row is not live.
            'expires_at' => now()->addMinutes(10),
        ]);
    }

    $res = $this->member()->post('/billing/checkout', ['plan' => 'starter', 'cycle' => 'monthly']);

    $res->assertRedirect(route('billing.pay', Payment::where('status', 'pending')->firstOrFail()));
    $this->assertNull(session('pending_checkout'));
    $this->assertSame(1, Payment::where('status', 'pending')->count());
}

/** Scenario 2 — a genuinely live pending payment still uses the existing prompt. */
public function test_scenario_2_a_live_pending_payment_uses_the_existing_prompt(): void
{
    $this->fakeGateway();
    $live = $this->paymentWithQr(now()->addMinutes(8));

    $res = $this->member()->post('/billing/checkout', ['plan' => 'pro', 'cycle' => 'monthly']);

    $res->assertRedirect(route('billing.index'));
    $res->assertSessionHas('pending_checkout');
    $this->assertSame(1, Payment::count(), 'No duplicate active payment may be created.');
    $this->assertSame('pending', $live->fresh()->status);
}

/** Scenario 3 — a pending payment past its window is settled and does not block. */
public function test_scenario_3_a_stale_pending_payment_is_settled_and_does_not_block(): void
{
    $this->fakeGateway();
    $stale = $this->paymentWithQr(now()->subMinute());

    $res = $this->member()->post('/billing/checkout', ['plan' => 'pro', 'cycle' => 'monthly']);

    $fresh = Payment::where('status', 'pending')->firstOrFail();

    $res->assertRedirect(route('billing.pay', $fresh));
    $this->assertNull(session('pending_checkout'));
    $this->assertSame('expired', $stale->fresh()->status);
}

/**
 * Scenario 4 — the provider is unreachable: the customer gets a safe message and the
 * operator keeps the technical detail.
 */
public function test_scenario_4_a_provider_failure_is_safe_for_the_user_and_verbose_in_the_log(): void
{
    Log::spy();

    Http::fake(function () {
        throw new ConnectionException('cURL error 28: Operation timed out after 15000 milliseconds');
    });

    $res = $this->member()->post('/billing/checkout', ['plan' => 'starter', 'cycle' => 'monthly']);

    $res->assertRedirect(route('billing.index'));
    $this->assertStringContainsString('temporarily unavailable', session('error'));

    // The user is told nothing about the provider, the host, or the transport.
    foreach (['cURL', 'qris.pw', 'ConnectionException', 'timed out'] as $leak) {
        $this->assertStringNotContainsString($leak, session('error'));
    }

    // The operator keeps it.
    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context = []) => $message === 'qrispw.create.transport_failed'
            && ($context['reason'] ?? null) === 'timeout')
        ->atLeast()->once();

    $this->assertSame(0, Payment::count());
}

/** Both buttons must submit the intent that the backend switches on. */
    public function test_the_modal_buttons_post_the_two_intents_the_backend_expects(): void
    {
        $html = $this->modalHtml();

        $this->assertStringContainsString('name="intent" value="continue"', $html);
        $this->assertStringContainsString('name="intent" value="replace"', $html);
        $this->assertStringContainsString(route('billing.checkout.resolve'), $html);
    }

    /**
     * `intent` must never travel on a submit button again.
     *
     * A submitter that is disabled while the browser builds the form's entry list is
     * dropped from the POST, which is how production ended up answering "The intent
     * field is required." A hidden input inside its own form cannot be dropped by a
     * button state, by Alpine, or by a stale bundle.
     */
    public function test_intent_is_never_carried_by_a_submit_button(): void
    {
        $html = $this->modalHtml();

        $this->assertDoesNotMatchRegularExpression(
            '/<button[^>]*\bname="intent"/',
            $html,
            'intent must not ride on the submitter; it belongs in a hidden input.'
        );

        // And each intent lives in its own form, so neither depends on the other.
        $this->assertSame(
            2,
            substr_count($html, 'action="'.route('billing.checkout.resolve').'"'),
            'The modal must post two independent forms, one per choice.'
        );
    }

    /** A complete checkout payload: payment, invoice, plan and cycle all travel. */
    public function test_the_modal_carries_the_full_checkout_payload(): void
    {
        $html = $this->modalHtml();

        foreach (['payment_id', 'invoice_id', 'plan', 'cycle'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $html);
        }
    }

    /** The modal copy must be English, matching the rest of the application. */
    public function test_the_modal_copy_is_english_and_consistent(): void
    {
        $html = $this->modalHtml();

        foreach (['Payment pending', 'Continue payment'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }

        // The ampersand is HTML-escaped on render, so assert the escaped form.
        $this->assertStringContainsString('Cancel &amp; create new', $html);

        foreach (['Pembayaran', 'Lanjut Bayar', 'Batalkan & Buat Baru'] as $leftover) {
            $this->assertStringNotContainsString($leftover, $html, "Indonesian copy leaked into the UI: {$leftover}");
        }
    }

    // ---------------------------------------------------------------- helpers

    private function member()
    {
        return $this->actingAs($this->owner)->withSession(['tenant_id' => $this->tenant->id]);
    }

    private function billingHtml(): string
    {
        return $this->member()->get('/billing')->assertOk()->getContent();
    }

    private function modalHtml(): string
    {
        return $this->member()
            ->withSession(['pending_checkout' => [
                'payment_id' => 1, 'invoice_id' => 1,
                'plan' => 'starter', 'cycle' => 'monthly', 'amount' => 79_000,
            ]])
            ->get('/billing')
            ->assertOk()
            ->getContent();
    }

    private function fakeGateway(): void
    {
        Http::fake([
            'qris.pw/*' => fn () => Http::response([
                'success' => true,
                'transaction_id' => 'TX-'.Str::random(24),
                'expires_at' => now()->addMinutes(10)->toIso8601String(),
                'qris_url' => 'https://example.test/qr.png',
            ]),
        ]);
    }

    /** A pending payment that already carries a QR, so the checkout page can render one. */
    private function paymentWithQr(
        $expiresAt,
        string $status = 'pending',
        ?\DateTimeInterface $paidAt = null
    ): Payment {
        $invoice = Invoice::create([
            'tenant_id' => $this->tenant->id,
            'invoice_number' => 'INV-R-'.Str::random(8),
            'amount' => 79_000, 'currency' => 'IDR', 'status' => 'open',
            'description' => 'test', 'issued_at' => now(), 'due_at' => now()->addHour(),
        ]);

        return Payment::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id,
            'invoice_id' => $invoice->id, 'order_id' => 'ORD-'.Str::random(14),
            'amount' => 79_000, 'currency' => 'IDR',
            'status' => $status, 'paid_at' => $paidAt, 'expires_at' => $expiresAt,
            'provider_transaction_id' => 'TX-'.Str::random(24),
            'payload' => ['create' => ['qris_url' => 'https://example.test/qr.png']],
        ]);
    }

    /** A rejected request must leave a visible reason, never a silent no-op. */
    public function test_an_unknown_intent_is_reported_instead_of_silently_ignored(): void
    {
        $this->fakeGateway();
        $existing = $this->paymentWithQr(now()->addMinutes(10));

        $this->member()->post('/billing/checkout/resolve', [
            'payment_id' => $existing->id,
            'invoice_id' => $existing->invoice_id,
            'invoice_id' => $existing->invoice_id,
            'plan' => 'starter',
            'cycle' => 'monthly',
            'intent' => 'nonsense',
        ])->assertSessionHasErrors('intent');

        // Nothing was cancelled and nothing was created.
        $this->assertSame('pending', $existing->fresh()->status);
        $this->assertSame(1, Payment::count());
    }
}
