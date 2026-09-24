<?php

use App\Http\Controllers\BillingController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\BrandsController;
use App\Http\Controllers\CategoriesController;
use App\Http\Controllers\CustomersController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProductsController;
use App\Http\Controllers\PurchasesController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\SuppliersController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TenantController;

Route::get('/', fn () => view('welcome', ['plans' => \App\Models\Plan::active()->get()]))->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // Tenant onboarding / switching (no tenant context required yet).
    Route::get('/tenants', [TenantController::class, 'index'])->name('tenants.index');
    Route::post('/tenants', [TenantController::class, 'store'])->name('tenants.store');
    Route::post('/tenants/{tenant}/switch', [TenantController::class, 'switchTenant'])
        ->middleware('throttle:20,1')->name('tenants.switch');

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

        // Team management (invite/roles/remove).
        Route::get('/team', [TeamController::class, 'index'])->name('team.index');
        Route::post('/team/invite', [TeamController::class, 'invite'])->name('team.invite');
        Route::patch('/team/{membership}/role', [TeamController::class, 'updateRole'])->name('team.role');
        Route::delete('/team/{membership}', [TeamController::class, 'remove'])->name('team.remove');

        Route::middleware('throttle:billing')->group(function () {
            Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
            Route::post('/billing/checkout', [BillingController::class, 'checkout'])->name('billing.checkout');
            Route::get('/billing/payments/{payment}', [BillingController::class, 'showPayment'])->name('billing.pay');
            Route::get('/billing/payments/{payment}/status', [BillingController::class, 'paymentStatus'])->name('billing.payment.status');
            Route::post('/billing/payments/{payment}/check', [BillingController::class, 'checkPayment'])->name('billing.payment.check');
                });

        // Business Management — all inherit auth, verified, tenant (server-side enforced).
        Route::resource('products', ProductsController::class);
        Route::resource('brands', BrandsController::class)->except(['show']);
        Route::resource('categories', CategoriesController::class)->except(['show']);
        Route::resource('customers', CustomersController::class)->except(['show']);
        Route::resource('suppliers', SuppliersController::class)->except(['show']);
        Route::resource('purchases', PurchasesController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);
        Route::post('/purchases/{purchase}/receive', [PurchasesController::class, 'receive'])->name('purchases.receive');
        Route::post('/purchases/{purchase}/cancel', [PurchasesController::class, 'cancel'])->name('purchases.cancel');
        Route::resource('sales', SalesController::class);
        Route::post('/sales/{sale}/complete', [SalesController::class, 'complete'])->name('sales.complete');
        Route::post('/sales/{sale}/cancel', [SalesController::class, 'cancel'])->name('sales.cancel');
        Route::post('/sales/{sale}/refund', [SalesController::class, 'refund'])->name('sales.refund');

        // Stock — custom, not a REST resource.
        Route::get('/stock', [StockController::class, 'index'])->name('stock.index');
        Route::get('/stock/movements', [StockController::class, 'movements'])->name('stock.movements');
        Route::post('/stock/adjust', [StockController::class, 'adjust'])->name('stock.adjust');
                Route::get('/stock/low', [StockController::class, 'lowStock'])->name('stock.low');
    });
});

// Platform admin (staff) panel — audited, no casual tenant data exposure.
Route::middleware(['auth', 'verified', 'platform.admin'])->prefix('admin')->group(function () {
    Route::get('/', \App\Http\Controllers\Admin\DashboardController::class)->name('admin.dashboard');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // API tokens (Sanctum) — per-user, not tenant-scoped.
    Route::get('/tokens', [ApiTokenController::class, 'index'])->name('tokens.index');
    Route::post('/tokens', [ApiTokenController::class, 'store'])->name('tokens.store');
    Route::delete('/tokens/{token}', [ApiTokenController::class, 'destroy'])->name('tokens.destroy');

    // Team invitation acceptance — signed URL, outside the tenant group
    // because the invitee may not have a valid tenant context yet.
    Route::get('/team/invitations/{membership}/accept', [TeamController::class, 'accept'])
        ->middleware('signed')
        ->name('team.accept');
});

require __DIR__.'/auth.php';
require __DIR__.'/api.php';

