<?php

namespace App\Http\Controllers\Admin;

use App\Models\PaymentSetting;
use App\Services\PaymentGatewayManager;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Platform gateway selection (/admin/billing).
 *
 * Exactly ONE choice lives here: which gateway NEW checkouts are sent to. Payments
 * already created keep the gateway recorded on their own row (payments.provider), so
 * flipping this switch never moves an in-flight payment, and reconcile/webhooks keep
 * resolving to the provider that actually holds the transaction.
 *
 * A gateway without credentials is refused AT THE FORM rather than saved: pointing
 * every checkout of every workspace at a provider with no API key would silently
 * break billing platform-wide, which is precisely the kind of damage a settings
 * screen must not be able to cause.
 */
class BillingSettingController extends AdminController
{
    public function edit(Request $request, PaymentGatewayManager $gateways)
    {
        return view('admin.billing.index', [
            'settings' => PaymentSetting::current(),
            'gateways' => $gateways->descriptors(),
            'migrated' => PaymentSetting::isMigrated(),
            'webhookUrls' => [
                'qrispw' => route('webhooks.qris'),
                'kasera' => route('webhooks.kasera'),
            ],
        ]);
    }

    public function update(Request $request, PaymentGatewayManager $gateways)
    {
        $data = $request->validate([
            'active_gateway' => ['required', 'string', 'in:qrispw,kasera'],
        ]);

        // A save against a schema that lacks payment_settings would 500 (relation does
        // not exist), so refuse with a message that names the fix instead of crashing.
        if (! PaymentSetting::isMigrated()) {
            throw ValidationException::withMessages([
                'active_gateway' => __('The payment_settings table is missing. Run "php artisan migrate" first, then save again.'),
            ]);
        }

        $gateway = $gateways->driver($data['active_gateway']);

        if (! $gateway->isConfigured()) {
            throw ValidationException::withMessages([
                'active_gateway' => __(':gateway is not configured. Set its API key before selecting it.', [
                    'gateway' => $gateway->label(),
                ]),
            ]);
        }

        $before = PaymentSetting::activeGateway();

        PaymentSetting::current()->update(['active_gateway' => $gateway->name()]);

        $this->audit($request, 'billing.gateway_updated', null, [
            'from' => $before,
            'to' => $gateway->name(),
        ]);

        return redirect()->route('admin.billing.edit')
            ->with('status', ['type' => 'success', 'message' => __('Payment gateway saved.')]);
    }
}