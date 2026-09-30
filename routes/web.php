<?php

use App\Http\Controllers\BillingController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\BusinessInvoicesController;
use App\Http\Controllers\BrandsController;
use App\Http\Controllers\CategoriesController;
use App\Http\Controllers\CustomersController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpensesController;
use App\Http\Controllers\ProductsController;
use App\Http\Controllers\PurchasesController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\SuppliersController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\WarehousesController;
use App\Http\Controllers\WorkspaceBrandingController;
use App\Http\Controllers\ModulesController;
use App\Http\Controllers\PosController;


Route::get('/', fn () => view('welcome', ['plans' => \App\Models\Plan::active()->get()]))->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // Tenant onboarding / switching (no tenant context required yet).
    Route::get('/tenants', [TenantController::class, 'index'])->name('tenants.index');
    Route::post('/tenants', [TenantController::class, 'store'])->name('tenants.store');
    Route::post('/tenants/{tenant}/switch', [TenantController::class, 'switchTenant'])
        ->middleware('throttle:20,1')->name('tenants.switch');

    // "Don't show again" for the workspace notices on this page. Account-level state, so
    // it lives outside the `tenant` group: an account with no active workspace (the very
    // audience of these notices) must still be able to close them.
    Route::post('/tenants/notices/dismiss', [TenantController::class, 'dismissNotice'])
        ->name('tenants.notices.dismiss');

    // Workspace management. These sit outside the `tenant` group on purpose: a user
    // may have no active workspace yet, and must still be able to edit or close one.
    Route::get('/tenants/{tenant}/edit', [TenantController::class, 'edit'])->name('tenants.edit');
    Route::put('/tenants/{tenant}', [TenantController::class, 'update'])->name('tenants.update');
    Route::delete('/tenants/{tenant}', [TenantController::class, 'destroy'])->name('tenants.destroy');

    // Tenant-scoped area: every route inside requires a validated tenant context.
    Route::middleware('tenant')->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');

        // Files (uploads rate-limited strictly; downloads/views share tenant scope).
        Route::middleware('throttle:uploads')->group(function () {
            Route::post('/files', [FileController::class, 'store'])->name('files.store');
        });
        Route::get('/files', [FileController::class, 'index'])->name('files.index');
        Route::get('/files/{file}/download', [FileController::class, 'download'])->name('files.download');
        Route::delete('/files/{file}', [FileController::class, 'destroy'])->name('files.destroy');

        // Team management (invite/roles/remove/restore).
        Route::get('/team', [TeamController::class, 'index'])->name('team.index');
        Route::post('/team/invite', [TeamController::class, 'invite'])->name('team.invite');
        Route::patch('/team/{membership}/role', [TeamController::class, 'updateRole'])
            ->middleware('plan.feature:advanced_permissions')
            ->name('team.role');
        Route::delete('/team/{membership}', [TeamController::class, 'remove'])->name('team.remove');

        // Restoring a membership that was revoked earlier. The removed row is
        // soft-deleted, so it is resolved explicitly (a trashed model is invisible to
        // implicit binding) and revives with status = active.
        Route::patch('/team/{membership}/restore', [TeamController::class, 'restore'])->name('team.restore');

        // Owner-only clean-up of a revoked membership. This is the one team action that
        // really deletes a row: the membership history is erased for good and the
        // (tenant_id, user_id) pair becomes free again. The USER ACCOUNT is still never
        // touched — removing a member and forgetting a membership are different things.
        Route::delete('/team/{membership}/permanent', [TeamController::class, 'forceDelete'])
            ->name('team.force-destroy');

        Route::middleware('throttle:billing')->group(function () {
            Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
            Route::post('/billing/checkout', [BillingController::class, 'checkout'])->name('billing.checkout');
        Route::post('/billing/notices/dismiss', [BillingController::class, 'dismissNotice'])->name('billing.notice.dismiss');
            Route::get('/billing/payments/{payment}', [BillingController::class, 'showPayment'])->name('billing.pay');
            Route::get('/billing/payments/{payment}/status', [BillingController::class, 'paymentStatus'])->name('billing.payment.status');
            Route::post('/billing/payments/{payment}/check', [BillingController::class, 'checkPayment'])->name('billing.payment.check');
                });

        // Business Management — all inherit auth, verified, tenant (server-side enforced).
        Route::resource('products', ProductsController::class);
        Route::resource('brands', BrandsController::class)->except(['show']);
        Route::resource('categories', CategoriesController::class)->except(['show']);
        Route::resource('customers', CustomersController::class)->except(['show']);
        Route::get('/customers/{customer}', [CustomersController::class, 'show'])->name('customers.show');
        Route::resource('suppliers', SuppliersController::class)->except(['show']);
        Route::get('/suppliers/{supplier}', [SuppliersController::class, 'show'])->name('suppliers.show');
        Route::resource('purchases', PurchasesController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);
        Route::post('/purchases/{purchase}/receive', [PurchasesController::class, 'receive'])->name('purchases.receive');
        Route::post('/purchases/{purchase}/cancel', [PurchasesController::class, 'cancel'])->name('purchases.cancel');
        // Sales. The report/dashboard/return screens are registered BEFORE the resource so
        // "dashboard", "report" and "returns" are never parsed as a sale id.
        Route::get('/sales/dashboard', [SalesController::class, 'dashboard'])->name('sales.dashboard');
        Route::get('/sales/report', [SalesController::class, 'report'])->name('sales.report');
        Route::get('/sales/returns', [SalesController::class, 'returns'])->name('sales.returns');
        Route::resource('sales', SalesController::class)->only(['index', 'create', 'store', 'show']);
        Route::get('/sales/{sale}/print', [SalesController::class, 'invoice'])->name('sales.print');
        Route::post('/sales/{sale}/complete', [SalesController::class, 'complete'])->name('sales.complete');
        Route::post('/sales/{sale}/cancel', [SalesController::class, 'cancel'])->name('sales.cancel');
        Route::post('/sales/{sale}/refund', [SalesController::class, 'refund'])->name('sales.refund');
        Route::get('/sales/{sale}/return', [SalesController::class, 'returnForm'])->name('sales.return');
        Route::post('/sales/{sale}/return', [SalesController::class, 'storeReturn'])->name('sales.return.store');

        // Advanced reporting + analytics are plan-gated (Pro/Business). The plan.feature
        // middleware runs the entitlement check server-side; RBAC alone is not enough.
        Route::middleware('plan.feature:advanced_reports')->group(function () {
            Route::get('/reports', [ReportsController::class, 'index'])->name('reports.index');
            Route::get('/reports/sales', [ReportsController::class, 'sales'])->name('reports.sales');
            Route::get('/reports/inventory', [ReportsController::class, 'inventory'])->name('reports.inventory');
            Route::get('/reports/purchases', [ReportsController::class, 'purchases'])->name('reports.purchases');
            Route::get('/reports/customers', [ReportsController::class, 'customers'])->name('reports.customers');
            Route::get('/reports/profit', [ReportsController::class, 'profit'])->name('reports.profit');
        });

        Route::middleware('plan.feature:advanced_analytics')
            ->get('/analytics', [AnalyticsController::class, 'index'])
            ->name('analytics.index');

        // Audit trail: plan entitlement (Pro/Business) AND the manage_settings registry
        // verb inside the controller, so a Manager without governance rights is still
        // refused. Entries are addressed by id, so show() re-checks tenant ownership.
        Route::middleware('plan.feature:audit_log')->group(function () {
            Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
            Route::get('/audit-logs/{entry}', [AuditLogController::class, 'show'])->name('audit-logs.show');
        });

        // Stock — custom, not a REST resource.
        Route::get('/stock', [StockController::class, 'index'])->name('stock.index');
        Route::get('/stock/movements', [StockController::class, 'movements'])->name('stock.movements');
        Route::post('/stock/adjust', [StockController::class, 'adjust'])->name('stock.adjust');
        Route::get('/stock/low', [StockController::class, 'lowStock'])->name('stock.low');

        // Warehouse, purchasing, expense and business-invoice modules.
        Route::resource('warehouses', WarehousesController::class);
        Route::resource('expenses', ExpensesController::class);
        Route::get('/business-invoices', [BusinessInvoicesController::class, 'index'])->name('business-invoices.index');
        Route::get('/business-invoices/{invoice}', [BusinessInvoicesController::class, 'show'])->name('business-invoices.show');
        Route::post('/business-invoices/{invoice}/payments', [BusinessInvoicesController::class, 'pay'])->name('business-invoices.pay');

        // Module Directory & Management (scoped to active workspace)
        Route::get('/modules', [ModulesController::class, 'index'])->name('modules.index');
        Route::get('/modules/{module:slug}', [ModulesController::class, 'show'])->name('modules.show');
        Route::post('/modules/{module:slug}/install', [ModulesController::class, 'install'])->name('modules.install');
        Route::post('/modules/{module:slug}/activate', [ModulesController::class, 'activate'])->name('modules.activate');
        Route::post('/modules/{module:slug}/deactivate', [ModulesController::class, 'deactivate'])->name('modules.deactivate');
        Route::delete('/modules/{module:slug}/uninstall', [ModulesController::class, 'uninstall'])->name('modules.uninstall');

        // POS Module (Availability gated by module:pos, access gated by sales.create)
        Route::middleware('module:pos')->prefix('pos')->name('pos.')->group(function () {
            Route::get('/', [PosController::class, 'index'])->name('index');
            Route::get('/products', [PosController::class, 'products'])->name('products');
            Route::post('/calculate', [PosController::class, 'calculate'])->name('calculate');
            Route::post('/checkout', [PosController::class, 'checkout'])->name('checkout');
        });

    });
});

// Platform admin (staff) panel — cross-workspace monitoring, audited, no write access
// to tenant business data. Reuses the existing /admin prefix and platform.admin gate.
Route::middleware(['auth', 'verified', 'platform.admin'])->prefix('admin')->group(function () {
    Route::get('/', \App\Http\Controllers\Admin\DashboardController::class)->name('admin.dashboard');

    Route::get('/users', [\App\Http\Controllers\Admin\UserController::class, 'index'])->name('admin.users.index');
    Route::get('/users/{user}', [\App\Http\Controllers\Admin\UserController::class, 'show'])->name('admin.users.show');

    Route::get('/workspaces', [\App\Http\Controllers\Admin\TenantController::class, 'index'])->name('admin.workspaces.index');
    Route::get('/workspaces/{tenant}', [\App\Http\Controllers\Admin\TenantController::class, 'show'])->name('admin.workspaces.show');
    Route::post('/workspaces/{tenant}/restore', [\App\Http\Controllers\Admin\TenantController::class, 'restore'])->name('admin.workspaces.restore');

    Route::get('/products', [\App\Http\Controllers\Admin\ProductController::class, 'index'])->name('admin.products.index');
    Route::get('/customers', [\App\Http\Controllers\Admin\CustomerController::class, 'index'])->name('admin.customers.index');
    Route::get('/sales', [\App\Http\Controllers\Admin\SaleController::class, 'index'])->name('admin.sales.index');
    Route::get('/purchases', [\App\Http\Controllers\Admin\PurchaseController::class, 'index'])->name('admin.purchases.index');
    Route::get('/inventory', [\App\Http\Controllers\Admin\InventoryController::class, 'index'])->name('admin.inventory.index');

    Route::get('/subscriptions', [\App\Http\Controllers\Admin\SubscriptionController::class, 'index'])->name('admin.subscriptions.index');
    Route::get('/payments', [\App\Http\Controllers\Admin\PaymentController::class, 'index'])->name('admin.payments.index');
    Route::get('/plans', [\App\Http\Controllers\Admin\PlanController::class, 'index'])->name('admin.plans.index');
    Route::get('/audit-logs', [\App\Http\Controllers\Admin\AuditLogController::class, 'index'])->name('admin.audit-logs.index');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // API tokens remain user-owned, but entitlement checks require an active workspace.
    Route::middleware('tenant')->group(function () {
        Route::get('/tokens', [ApiTokenController::class, 'index'])->name('tokens.index');
        Route::post('/tokens', [ApiTokenController::class, 'store'])->name('tokens.store');
        Route::delete('/tokens/{token}', [ApiTokenController::class, 'destroy'])->name('tokens.destroy');

        // Custom workspace branding. Editing needs a validated workspace context, and
        // the write itself is gated on `manage_settings` inside the controller
        // (Owner + Admin only), so no new role or permission is introduced.
        Route::patch('/branding', [WorkspaceBrandingController::class, 'update'])->name('branding.update');

        // The logo is fetched by the shell's <img>, which carries the session cookie
        // but no CSRF token — hence a GET inside the authenticated tenant group.
        Route::get('/branding/logo/{tenant}', [WorkspaceBrandingController::class, 'logo'])
            ->name('branding.logo');
    });

    // Team invitation acceptance — signed URL, outside the tenant group
    // because the invitee may not have a valid tenant context yet.
    Route::get('/team/invitations/{membership}/accept', [TeamController::class, 'accept'])
        ->middleware('signed')
        ->name('team.accept');

    // The same accept/reject actions for a signed-in recipient coming from the
    // dashboard or /team (no signed token in the browser, recipient re-checked
    // server-side). Also outside the tenant group: an invitee may have no
    // workspace yet when they respond.
    Route::post('/team/invitations/{membership}/accept', [TeamController::class, 'acceptInvitation'])
        ->name('team.invitations.accept');
    Route::post('/team/invitations/{membership}/reject', [TeamController::class, 'rejectInvitation'])
        ->name('team.invitations.reject');
});

require __DIR__.'/auth.php';
require __DIR__.'/api.php';

