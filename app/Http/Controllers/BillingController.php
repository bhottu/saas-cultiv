<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Models\DismissedNotification;
use App\Exceptions\PaymentProviderException;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\PaymentService;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function index(Request $request)
    {
        $ctx = app('tenant.context');
        $ctx->check();

        $tenant = $ctx->tenant();
        $subscription = $tenant->activeSubscription()->with('plan')->first();

        return view('billing.index', [
            'tenant' => $tenant,
            'subscription' => $subscription,
            'plans' => Plan::active()->get(),
            'pendingPayment' => $this->noticeFor($request, $tenant),
            'invoices' => $tenant->invoices()->latest()->take(20)->get(),
            'payments' => $tenant->payments()->latest()->take(20)->get(),
        ]);
    }

    /**
     * The newest still-open payment, already resolved to the state the user should see.
     *
     * Two things are decided here, on the server, so the view never has to guess:
     *  - a pending payment whose window has closed is EXPIRED. The row is settled first,
     *    so the status shown is the same one the webhook and the QR page will report.
     *  - a notice the account already dismissed stays dismissed, but the payment record
     *    itself is never touched — dismissal is a per-user preference, not a deletion.
     */
    private function noticeFor(Request $request, Tenant $tenant): ?Payment
    {
        $payment = $tenant->payments()->where('status', 'pending')->latest()->first();

        if (! $payment) {
            return null;
        }

        // Source of truth for "is this still payable?" is the stored expires_at compared
        // against the app clock, which Laravel resolves in the configured app timezone —
        // no timezone is hardcoded here.
        if ($payment->isExpired()) {
            app(PaymentService::class)->markExpired($payment);
            $payment->refresh();
        }

        $key = DismissedNotification::pendingPaymentKey($payment);

        if (DismissedNotification::where('user_id', $request->user()->id)->where('key', $key)->exists()) {
            return null;
        }

        return $payment;
    }

    /**
     * Permanently hide the pending/expired payment notice for this account.
     *
     * Reuses the existing dismissed_notifications table and key convention, so the
     * decision survives a refresh, a new session and another device. The payment and its
     * invoice are left exactly as they are.
     */
    public function dismissNotice(Request $request)
    {
        $ctx = app('tenant.context');
        $ctx->check();

        $data = $request->validate([
            'key' => ['required', 'integer', 'min:1'],
        ]);

        $payment = $ctx->tenant()->payments()->find($data['key']);

        // Only a payment this workspace actually owns, and only one that is still
        // unresolved, may be dismissed. Anything else is a 422 rather than a silent
        // no-op, so a bad key can never be mistaken for "dismissed".
        $eligible = $payment && $payment->status === 'pending';

        if (! $eligible) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'key' => 'That payment notice is no longer available.',
            ]);
        }

        DismissedNotification::updateOrCreate(
            ['user_id' => $request->user()->id, 'key' => DismissedNotification::pendingPaymentKey($payment)],
            ['dismissed_at' => now()]
        );

        return redirect()->route('billing.index');
    }

    /** Checkout: trial if eligible, otherwise QRIS payment. */
    public function checkout(Request $request)
    {
        $ctx = app('tenant.context');
        $ctx->check();
        $ctx->authorize('manage_billing');

        $data = $request->validate([
            'plan' => ['required', 'string', 'exists:plans,slug'],
            'cycle' => ['required', 'in:monthly,yearly'],
        ]);

        $plan = Plan::where('slug', $data['plan'])->where('is_active', true)->firstOrFail();
        $tenant = $ctx->tenant();

        // Annual prices were not part of the approved pricing. Keep the existing billing
        // schema/cycle support, but reject it until a non-zero official annual price exists.
        if ($data['cycle'] === 'yearly' && $plan->price_yearly <= 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'cycle' => 'Yearly billing is not available yet. Please choose monthly billing.',
            ]);
        }

        // Free tier switch: no payment needed.
        if ($plan->price_monthly <= 0 && $plan->price_yearly <= 0) {
            $this->subscriptions->switchToFree($tenant);
            \App\Services\AuditLogger::log('subscription.downgraded', $tenant, ['plan' => $plan->slug]);

            return redirect()->route('billing.index')->with('success', "Switched to {$plan->name}.");
        }

        // Paid subscriptions always use the existing invoice + QRIS.PW payment flow.
        // A browser must not be able to activate a paid plan by appending ?trial=1.
        // Otherwise create invoice + QRIS payment.
        try {
            $payment = $this->payments->createCheckout($tenant, $plan, $data['cycle'], $request->user());
        } catch (PaymentProviderException $e) {
            // The payment gateway refused (usually missing/invalid credentials, a network
            // hiccup, or a rate limit). A gateway problem is not an application crash:
            // billing the customer with a 500 loses the checkout and leaves them with no
            // way forward. Log for the operator, then send them back with a clear message
            // and a still-usable /billing page.
            //
            // Deliberately no credentials, headers or full provider body are logged —
            // only the exception class/message and non-identifying ids.
            \Illuminate\Support\Facades\Log::error('billing.checkout.provider_failed', [
                'tenant_id' => $tenant->id,
                'plan' => $plan->slug,
                'cycle' => $data['cycle'],
                'user_id' => $request->user()->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'provider_status' => $e->providerStatus,
            ]);

            return redirect()->route('billing.index')
                ->with('error', 'We could not reach the payment provider. Please try again in a moment.');
        }

        return redirect()->route('billing.pay', $payment);
    }

    public function showPayment(Request $request, $paymentId)
    {
        $ctx = app('tenant.context');
        $ctx->check();

        // Scoped by tenant global scope → IDOR-proof.
        $payment = $ctx->tenant()->payments()->where('status', 'pending')->findOrFail($paymentId);

        return view('billing.pay', ['payment' => $payment]);
    }

    /** Frontend polls this; backend remains the single source of truth. */
    public function paymentStatus(Request $request, $paymentId)
    {
        $ctx = app('tenant.context');
        $ctx->check();

        $payment = $ctx->tenant()->payments()->findOrFail($paymentId);

        // Lazily mark expired when past expires_at (no external call).
        if ($payment->status === 'pending' && $payment->expires_at && $payment->expires_at->isPast()) {
            $this->payments->markExpired($payment);
            $payment->refresh();
        }

        return response()->json([
            'status' => $payment->status,
            'expires_at' => $payment->expires_at?->toIso8601String(),
            'seconds_left' => $payment->expires_at ? max(0, now()->diffInSeconds($payment->expires_at, false)) : null,
        ]);
    }

    /** Manual status check against QRIS.PW (rate-limited). */
    public function checkPayment(Request $request, $paymentId)
    {
        $ctx = app('tenant.context');
        $ctx->check();

        $payment = $ctx->tenant()->payments()->where('status', 'pending')->findOrFail($paymentId);
        $status = $this->payments->reconcile($payment);

        return response()->json(['status' => $status]);
    }
}