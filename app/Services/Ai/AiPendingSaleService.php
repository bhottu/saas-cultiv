<?php

namespace App\Services\Ai;

use App\Models\AiChannelLink;
use App\Models\AiPendingAction;
use App\Exceptions\SubscriptionLimitException;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BusinessAuthorization;
use App\Services\ModuleManager;
use App\Services\RecordSaleService;
use App\Services\TenantContext;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AiPendingSaleService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly BusinessAuthorization $authorization,
        private readonly ModuleManager $modules,
        private readonly RecordSaleService $sales,
        private readonly AiAuditService $audit,
    ) {}

    public function decide(string $id, AiChannelLink $link, bool $confirm): string
    {
        return DB::transaction(function () use ($id, $link, $confirm): string {
            $action = AiPendingAction::query()->whereKey($id)
                ->where('channel_link_id', $link->id)
                ->where('tenant_id', $link->tenant_id)
                ->where('user_id', $link->user_id)
                ->lockForUpdate()->first();

            if (! $action || $action->status !== 'pending') {
                return __('This confirmation is no longer available.');
            }
            if ($action->expires_at->isPast()) {
                $action->forceFill(['status' => 'expired'])->save();
                $this->audit->pendingAction($action, 'ai.action.expired', 'expired');

                return __('This confirmation has expired. Please prepare the action again.');
            }
            if (! $confirm) {
                $action->forceFill(['status' => 'cancelled'])->save();
                $this->audit->pendingAction($action, 'ai.action.cancelled', 'cancelled');

                return __('The sale was cancelled and was not recorded.');
            }

            $tenant = Tenant::query()->find($action->tenant_id);
            $user = User::query()->find($action->user_id);
            if (! $tenant || ! $user || ! $user->membershipIn($tenant)) {
                $action->forceFill(['status' => 'failed'])->save();
                $this->audit->pendingAction($action, 'ai.action.failed', 'membership_revoked');

                return __('Workspace access has changed. The sale was not recorded.');
            }

            $this->context->set($tenant, $user);
            if (! $this->modules->active('ai_agent', $tenant)) {
                $action->forceFill(['status' => 'failed'])->save();
                $this->audit->pendingAction($action, 'ai.action.failed', 'module_inactive');

                return __('AI Assistant Telegram is no longer active for this workspace.');
            }
            if (! $this->authorization->can('sales.create')) {
                $action->forceFill(['status' => 'failed'])->save();
                $this->audit->pendingAction($action, 'ai.action.failed', 'permission_revoked');

                return __('Sales permission has changed. The sale was not recorded.');
            }

            $payload = $action->payload;
            $warehouse = Warehouse::query()->active()->find($payload['warehouse_id']);
            $customer = $payload['customer_id']
                ? Customer::query()->where('is_active', true)->find($payload['customer_id'])
                : null;
            if (! $warehouse || ($payload['customer_id'] && ! $customer)) {
                $action->forceFill(['status' => 'failed'])->save();
                $this->audit->pendingAction($action, 'ai.action.failed', 'reference_unavailable');

                return __('The selected warehouse or customer is no longer available. The sale was not recorded.');
            }

            $items = [];
            foreach ($payload['items'] as $item) {
                $product = Product::query()->where('is_active', true)->find($item['product_id']);
                if (! $product) {
                    $action->forceFill(['status' => 'failed'])->save();
                    $this->audit->pendingAction($action, 'ai.action.failed', 'product_unavailable');

                    return __('A product is no longer available. The sale was not recorded.');
                }
                $items[] = [
                    'product_id' => $product->id,
                    'quantity' => (int) $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount_type' => 'fixed',
                    'discount_value' => 0,
                ];
            }

            try {
                $sale = $this->sales->record($tenant, [
                    'customer_id' => $customer?->id,
                    'warehouse_id' => $payload['warehouse_id'],
                    'sales_channel' => 'manual',
                    'status' => 'completed',
                    'sold_at' => now()->toDateTimeString(),
                    'discount_type' => 'fixed',
                    'discount_value' => 0,
                    'tax_percent' => $payload['tax_percent'],
                    'shipping' => 0,
                    'payment_method' => $payload['payment_method'],
                    'payment_amount' => $payload['payment_amount'],
                    'client_reference' => 'ai-'.$action->id,
                    'items' => $items,
                ], $user->id);
            } catch (ValidationException|SubscriptionLimitException) {
                $action->forceFill(['status' => 'failed'])->save();
                $this->audit->pendingAction($action, 'ai.action.failed', 'sale_validation_failed');

                return __('The sale could not be recorded because stock or plan limits changed. No sale was recorded.');
            }

            $action->forceFill([
                'status' => 'completed',
                'confirmed_at' => now(),
            ])->save();
            $this->audit->pendingAction($action, 'ai.sale.recorded', 'completed', [
                'sale_id' => $sale->id,
                'invoice_number' => $sale->invoice_number,
                'total' => $sale->total,
            ]);

            return __('Sale :invoice was recorded. Total: :total. Payment status: :payment.', [
                'invoice' => $sale->invoice_number,
                'total' => \App\Services\Money::format($sale->total),
                'payment' => match ($sale->payment_status) {
                    Sale::PAYMENT_PAID => __('Paid'),
                    Sale::PAYMENT_PARTIAL => __('Partially Paid'),
                    Sale::PAYMENT_REFUNDED => __('Refunded'),
                    default => __('Unpaid'),
                },
            ]);
        });
    }
}
