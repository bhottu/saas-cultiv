<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\PaymentService;
use App\Services\SubscriptionService;
use App\Services\UsageService;
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
            'pendingPayment' => $tenant->payments()->where('status', 'pending')->latest()->first(),
            'invoices' => $tenant->invoices()->latest()->take(20)->get(),
            'payments' => $tenant->payments()->latest()->take(20)->get(),
        ]);
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

        // Free tier switch: no payment needed.
        if ($plan->price_monthly <= 0 && $plan->price_yearly <= 0) {
            $this->subscriptions->switchToFree($tenant);
            \App\Services\AuditLogger::log('subscription.downgraded', $tenant, ['plan' => $plan->slug]);

            return redirect()->route('billing.index')->with('success', "Switched to {$plan->name}.");
        }

        // First-time trial (if this plan allows it and tenant hasn't trialed).
        if ($tenant->trial_ends_at === null && $plan->is_free_tier === false && $request->boolean('trial')) {
            $this->subscriptions->startTrial($tenant, $plan);
            \App\Services\AuditLogger::log('subscription.trial_started', $tenant, ['plan' => $plan->slug]);

            return redirect()->route('dashboard')->with('success', "Trial started: {$plan->name}.");
        }

        // Otherwise create invoice + QRIS payment.
        $payment = $this->payments->createCheckout($tenant, $plan, $data['cycle'], $request->user());

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