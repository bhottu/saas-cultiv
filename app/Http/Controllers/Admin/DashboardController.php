<?php

namespace App\Http\Controllers\Admin;

use App\Models\Customer;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Models\StockBalance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends AdminController
{
    public function __invoke(Request $request)
    {
        return view('admin.dashboard', [
            'stats' => $this->stats(),
            'workspacesByPlan' => $this->workspacesByPlan(),
            'webhookEvents' => $this->acrossTenants(\App\Models\WebhookEvent::class)->latest()->take(15)->get(),
            'recentAudit' => $this->acrossTenants(\App\Models\AuditLog::class)->latest()->take(15)->get(),
        ]);
    }

    /**
     * Every figure is a single aggregate query — no per-workspace loops, so the
     * dashboard stays flat as the platform grows.
     */
    private function stats(): array
    {
        $users = \App\Models\User::query();
        $subscriptions = $this->acrossTenants(\App\Models\Subscription::class);
        $payments = $this->acrossTenants(\App\Models\Payment::class);
        $sales = $this->acrossTenants(Sale::class);
        $purchases = $this->acrossTenants(Purchase::class);

        $mrr = (int) $subscriptions->where('status', 'active')->where('billing_cycle', 'monthly')->sum('amount')
            + (int) ($subscriptions->where('status', 'active')->where('billing_cycle', 'yearly')->sum('amount') / 12);

        return [
            'users_total' => (clone $users)->count(),
            'users_verified' => (clone $users)->whereNotNull('email_verified_at')->count(),
            'users_platform_admin' => (clone $users)->where('is_platform_admin', true)->count(),

            'workspaces_active' => Tenant::count(),
            'workspaces_deleted' => Tenant::onlyTrashed()->count(),

            'products_total' => $this->acrossTenants(Product::class)->count(),
            'customers_total' => $this->acrossTenants(Customer::class)->count(),
            'suppliers_total' => $this->acrossTenants(Supplier::class)->count(),
            'warehouses_total' => $this->acrossTenants(Warehouse::class)->count(),

            'sales_total' => (clone $sales)->count(),
            'sales_value' => (int) $sales->whereIn('status', Sale::REVENUE_STATUSES)->sum('total'),

            'purchases_total' => (clone $purchases)->count(),
            'purchases_value' => (int) (clone $purchases)->where('status', 'received')->sum('total'),

            'inventory_units' => (int) $this->acrossTenants(StockBalance::class)->sum('quantity'),

            'subscriptions_total' => (clone $subscriptions)->count(),
            'subscriptions_active' => (clone $subscriptions)->where('status', 'active')->count(),
            'mrr' => $mrr,

            'payments_pending' => (clone $payments)->where('status', 'pending')->count(),
            'payments_paid' => (clone $payments)->where('status', 'paid')->count(),
            'payments_failed' => (clone $payments)->whereIn('status', ['failed', 'expired'])->count(),
        ];
    }

    /** @return array<string, int> */
    private function workspacesByPlan(): array
    {
        return $this->acrossTenants(\App\Models\Subscription::class)
            ->whereIn('status', ['active', 'trialing'])
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->selectRaw('plans.name as name, COUNT(DISTINCT subscriptions.tenant_id) as total')
            ->groupBy('plans.name')
            ->pluck('total', 'name')
            ->all();
    }
}
