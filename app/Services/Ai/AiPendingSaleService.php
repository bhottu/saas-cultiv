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
use App\Services\BusinessUsageService;
use App\Services\InventoryService;
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
        private readonly BusinessUsageService $usage,
        private readonly InventoryService $inventory,
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

                return match ($action->action) {
                    'record_expense' => __('The expense was cancelled and was not recorded.'),
                    'record_purchase' => __('The purchase draft was cancelled and was not recorded.'),
                    'record_customer' => __('The customer was cancelled and was not created.'),
                    'record_category' => __('The category was cancelled and was not created.'),
                    'record_brand' => __('The brand was cancelled and was not created.'),
                    'record_add_stock' => __('The stock addition was cancelled and no stock was changed.'),
                    default => __('The sale was cancelled and was not recorded.'),
                };
            }

            $tenant = Tenant::query()->find($action->tenant_id);
            $user = User::query()->find($action->user_id);
            if (! $tenant || ! $user || ! $user->membershipIn($tenant)) {
                $action->forceFill(['status' => 'failed'])->save();
                $this->audit->pendingAction($action, 'ai.action.failed', 'membership_revoked');

                return match ($action->action) {
                    'record_expense' => __('Workspace access has changed. The expense was not recorded.'),
                    'record_purchase' => __('Workspace access has changed. The purchase draft was not recorded.'),
                    'record_customer' => __('Workspace access has changed. The customer was not created.'),
                    'record_category' => __('Workspace access has changed. The category was not created.'),
                    'record_brand' => __('Workspace access has changed. The brand was not created.'),
                    'record_add_stock' => __('Workspace access has changed. No stock was changed.'),
                    default => __('Workspace access has changed. The sale was not recorded.'),
                };
            }

            $this->context->set($tenant, $user);
            if (! $this->modules->active('ai_agent', $tenant)) {
                $action->forceFill(['status' => 'failed'])->save();
                $this->audit->pendingAction($action, 'ai.action.failed', 'module_inactive');

                return __('AI Assistant Telegram is no longer active for this workspace.');
            }

            return match ($action->action) {
                'record_expense' => $this->decideExpense($action, $tenant, $user),
                'record_purchase' => $this->decidePurchase($action, $tenant, $user),
                'record_customer' => $this->decideCustomer($action, $tenant, $user),
                'record_category' => $this->decideCategory($action, $tenant, $user),
                'record_brand' => $this->decideBrand($action, $tenant, $user),
                'record_add_stock' => $this->decideAddStock($action, $tenant, $user),
                default => $this->decideSale($action, $tenant, $user),
            };
        });
    }

    /** @return array{0: \\App\\Models\\Expense|bool} */
    private function decideExpense(AiPendingAction $action, Tenant $tenant, User $user): string
    {
        if (! $this->authorization->can('expenses.create')) {
            $action->forceFill(['status' => 'failed'])->save();
            $this->audit->pendingAction($action, 'ai.action.failed', 'permission_revoked');

            return __('Expenses permission has changed. The expense was not recorded.');
        }

        $payload = $action->payload;
        $category = isset($payload['category_id']) ? \App\Models\ExpenseCategory::query()->where('is_active', true)->find($payload['category_id']) : null;
        if (isset($payload['category_id']) && ! $category) {
            $action->forceFill(['status' => 'failed'])->save();
            $this->audit->pendingAction($action, 'ai.action.failed', 'reference_unavailable');

            return __('The selected expense category is no longer available. The expense was not recorded.');
        }

        try {
            $expense = \App\Models\Expense::create([
                'tenant_id' => $tenant->id,
                'category_id' => $category?->id,
                'description' => $payload['description'],
                'amount' => (int) $payload['amount_cents'],
                'expense_date' => $payload['expense_date'],
                'payment_method' => $payload['payment_method'] ?? 'cash',
                'notes' => $payload['notes'] ?? null,
                'created_by' => $user->id,
            ]);
        } catch (ValidationException|SubscriptionLimitException) {
            $action->forceFill(['status' => 'failed'])->save();
            $this->audit->pendingAction($action, 'ai.action.failed', 'expense_validation_failed');

            return __('The expense could not be recorded because the details changed. No expense was recorded.');
        }

        $action->forceFill(['status' => 'completed', 'confirmed_at' => now()])->save();
        $this->audit->pendingAction($action, 'ai.expense.recorded', 'completed', [
            'expense_id' => $expense->id,
            'amount' => $expense->amount,
            'category_id' => $expense->category_id,
        ]);

        return __('Expense :description was recorded. Amount: :amount.', [
            'description' => $expense->description,
            'amount' => \App\Services\Money::format($expense->amount),
        ]);
    }

    private function decidePurchase(AiPendingAction $action, Tenant $tenant, User $user): string
    {
        if (! $this->authorization->can('purchases.create')) {
            $action->forceFill(['status' => 'failed'])->save();
            $this->audit->pendingAction($action, 'ai.action.failed', 'permission_revoked');

            return __('Purchase permission has changed. The purchase draft was not recorded.');
        }

        $payload = $action->payload;
        $supplier = \App\Models\Supplier::query()->active()->find($payload['supplier_id']);
        $warehouse = Warehouse::query()->active()->find($payload['warehouse_id']);
        if (! $supplier || ! $warehouse) {
            $action->forceFill(['status' => 'failed'])->save();
            $this->audit->pendingAction($action, 'ai.action.failed', 'reference_unavailable');

            return __('The selected supplier or warehouse is no longer available. The purchase draft was not recorded.');
        }

        try {
            $purchase = \App\Models\Purchase::create([
                'tenant_id' => $tenant->id,
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouse->id,
                'invoice_number' => app(\App\Services\DocumentNumberingService::class)->next('purchase', $tenant->id),
                'status' => 'ordered',
                'ordered_at' => now(),
                'expected_at' => $payload['expected_at'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'shipping' => 0,
                'discount' => 0,
                'tax' => 0,
                'subtotal' => 0,
                'total' => 0,
                'created_by' => $user->id,
            ]);
            foreach ($payload['items'] as $item) {
                $product = Product::query()->where('is_active', true)->find($item['product_id']);
                if (! $product) {
                    throw ValidationException::withMessages(['items' => __('One or more products are unavailable.')]);
                }
                $unitCost = (int) $item['unit_cost'];
                $purchase->items()->create([
                    'tenant_id' => $tenant->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'unit' => $product->unit,
                    'quantity' => (int) $item['quantity'],
                    'unit_cost' => $unitCost,
                    'subtotal' => $unitCost * (int) $item['quantity'],
                ]);
            }
            $purchase->forceFill([ 'subtotal' => $purchase->items()->sum('subtotal'), 'total' => $purchase->items()->sum('subtotal') ])->save();
        } catch (ValidationException|SubscriptionLimitException) {
            $action->forceFill(['status' => 'failed'])->save();
            $this->audit->pendingAction($action, 'ai.action.failed', 'purchase_validation_failed');

            return __('The purchase draft could not be recorded because the details changed. No purchase was recorded.');
        }

        $action->forceFill(['status' => 'completed', 'confirmed_at' => now()])->save();
        $this->audit->pendingAction($action, 'ai.purchase.recorded', 'completed', [
            'purchase_id' => $purchase->id,
            'invoice_number' => $purchase->invoice_number,
            'total' => $purchase->total,
        ]);

        return __('Purchase :invoice was recorded. Total: :total.', [
            'invoice' => $purchase->invoice_number,
            'total' => \App\Services\Money::format($purchase->total),
        ]);
    }

    private function decideCustomer(AiPendingAction $action, Tenant $tenant, User $user): string
    {
        if (! $this->authorization->can('customers.create')) {
            $action->forceFill(['status' => 'failed'])->save();
            $this->audit->pendingAction($action, 'ai.action.failed', 'permission_revoked');

            return __('Customers permission has changed. The customer was not created.');
        }

        $payload = $action->payload;
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            $action->forceFill(['status' => 'failed'])->save();
            $this->audit->pendingAction($action, 'ai.action.failed', 'invalid_payload');

            return __('The customer could not be created because the details changed. No customer was created.');
        }

        $phone = trim((string) ($payload['phone'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
        $duplicate = Customer::query()
            ->where('name', $name)
            ->when($phone !== '', fn ($query) => $query->where('phone', $phone))
            ->exists();
        if ($duplicate) {
            $action->forceFill(['status' => 'failed'])->save();
            $this->audit->pendingAction($action, 'ai.action.failed', 'customer_duplicate');

            return __('A customer with this name and phone already exists in this workspace.');
        }

        try {
            // Same metered plan limit the website Customers page enforces.
            $this->usage->enforce($tenant, 'customers_count');
            $customer = Customer::create([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'phone' => $phone === '' ? null : $phone,
                'email' => $email === '' ? null : $email,
                'address' => $payload['address'] ?? null,
                'notes' => $payload['notes'] ?? null,
                // Same money convention as the website: display value in, cents stored.
                'credit_limit' => (int) ($payload['credit_limit_cents'] ?? 0),
                'is_active' => true,
            ]);
        } catch (ValidationException|SubscriptionLimitException) {
            $action->forceFill(['status' => 'failed'])->save();
            $this->audit->pendingAction($action, 'ai.action.failed', 'customer_validation_failed');

            return __('The customer could not be created because the details or plan limits changed. No customer was created.');
        }

        $this->usage->recordMetric($tenant, 'customers_count');
        $action->forceFill(['status' => 'completed', 'confirmed_at' => now()])->save();
        $this->audit->pendingAction($action, 'ai.customer.created', 'completed', [
            'customer_id' => $customer->id,
            'name' => $customer->name,
        ]);

        return __('Customer :name was created.', ['name' => $customer->name]);
    }

    private function decideCategory(AiPendingAction $action, Tenant $tenant, User $user): string
    {
        if (! $this->authorization->can('categories.create')) {
            $action->forceFill(['status' => 'failed'])->save();
            $this->audit->pendingAction($action, 'ai.action.failed', 'permission_revoked');

            return __('Categories permission has changed. The category was not created.');
        }

        $payload = $action->payload;
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            $action->forceFill(['status' => 'failed'])->save();
            $this->audit->pendingAction($action, 'ai.action.failed', 'invalid_payload');

            return __('The category name is required.');
        }

        $existing = \App\Models\Category::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first(['id', 'name']);
        if ($existing) {
            $action->forceFill(['status' => 'failed'])->save();
            $this->audit->pendingAction($action, 'ai.action.failed', 'category_duplicate');

            return __('A category named :name already exists (ID :id).', [
                'name' => $existing->name,
                'id' => $existing->id,
            ]);
        }

        $category = \App\Models\Category::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'description' => $payload['description'] ?? null,
            'is_active' => true,
        ]);

        $action->forceFill(['status' => 'completed', 'confirmed_at' => now()])->save();
        $this->audit->pendingAction($action, 'ai.category.created', 'completed', [
            'category_id' => $category->id,
            'name' => $category->name,
        ]);

        return __('ai.category_recorded', [
            'id' => $category->id,
            'name' => $category->name,
            'description' => $category->description ?: '-',
            'status' => __('Active'),
        ]);
    }

    private function decideBrand(AiPendingAction $action, Tenant $tenant, User $user): string
    {
        if (! $this->authorization->can('brands.create')) {
            $action->forceFill(['status' => 'failed'])->save();
            $this->audit->pendingAction($action, 'ai.action.failed', 'permission_revoked');

            return __('Brands permission has changed. The brand was not created.');
        }

        $payload = $action->payload;
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            $action->forceFill(['status' => 'failed'])->save();
            $this->audit->pendingAction($action, 'ai.action.failed', 'invalid_payload');

            return __('The brand name is required.');
        }

        $existing = \App\Models\Brand::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first(['id', 'name']);
        if ($existing) {
            $action->forceFill(['status' => 'failed'])->save();
            $this->audit->pendingAction($action, 'ai.action.failed', 'brand_duplicate');

            return __('A brand named :name already exists (ID :id).', [
                'name' => $existing->name,
                'id' => $existing->id,
            ]);
        }

        $brand = \App\Models\Brand::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'description' => $payload['description'] ?? null,
            'is_active' => true,
        ]);

        $action->forceFill(['status' => 'completed', 'confirmed_at' => now()])->save();
        $this->audit->pendingAction($action, 'ai.brand.created', 'completed', [
            'brand_id' => $brand->id,
            'name' => $brand->name,
        ]);

        return __('ai.brand_recorded', [
            'id' => $brand->id,
            'name' => $brand->name,
            'description' => $brand->description ?: '-',
            'status' => __('Active'),
        ]);
    }

    private function decideAddStock(AiPendingAction $action, Tenant $tenant, User $user): string
    {
        if (! $this->authorization->can('stock.adjust')) {
            $action->forceFill(['status' => 'failed'])->save();
            $this->audit->pendingAction($action, 'ai.action.failed', 'permission_revoked');

            return __('Stock permission has changed. The stock was not changed.');
        }

        $payload = $action->payload;
        $product = Product::query()->where('is_active', true)->find($payload['product_id'] ?? null);
        $warehouse = Warehouse::query()->active()->find($payload['warehouse_id'] ?? null);
        $quantity = (int) ($payload['quantity'] ?? 0);
        $reason = trim((string) ($payload['reason'] ?? ''));
        if (! $product || ! $warehouse || $quantity < 1 || $reason === '' || ! $product->track_inventory) {
            $action->forceFill(['status' => 'failed'])->save();
            $this->audit->pendingAction($action, 'ai.action.failed', 'reference_unavailable');

            return __('The product, warehouse or quantity is no longer available. The stock was not changed.');
        }

        try {
            $movement = $this->inventory->adjust(
                $tenant,
                $product,
                $warehouse->id,
                $quantity,
                $reason,
                $user->id,
                $payload['notes'] ?? null,
            );
        } catch (\InvalidArgumentException|\Illuminate\Database\QueryException) {
            $action->forceFill(['status' => 'failed'])->save();
            $this->audit->pendingAction($action, 'ai.action.failed', 'stock_adjustment_failed');

            return __('The stock could not be added because the details changed. No stock was changed.');
        }

        $balance = (int) \App\Models\StockBalance::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->value('quantity');

        $action->forceFill(['status' => 'completed', 'confirmed_at' => now()])->save();
        $this->audit->pendingAction($action, 'ai.stock.added', 'completed', [
            'movement_id' => $movement->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => $quantity,
        ]);

        return __('ai.stock_recorded', [
            'product' => $product->name,
            'quantity' => $quantity,
            'unit' => $product->unit ?: 'pcs',
            'warehouse' => $warehouse->name,
            'reason' => $reason,
            'balance' => $balance,
        ]);
    }

    private function decideSale(AiPendingAction $action, Tenant $tenant, User $user): string
    {
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

        $action->forceFill(['status' => 'completed', 'confirmed_at' => now()])->save();
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
    }
}
