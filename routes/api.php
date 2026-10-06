<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Payment provider webhooks: no auth, signature-verified, rate-limited, idempotent.
Route::post('/webhooks/qris', [WebhookController::class, 'qris'])
    ->middleware('throttle:webhook')
    ->name('webhooks.qris');

// Kasera Pay delivers to its own path with its own header signature scheme; the
// throttle bucket is shared so two gateways cannot be used to double the rate.
Route::post('/webhooks/kasera', [WebhookController::class, 'kasera'])
    ->middleware('throttle:webhook')
    ->name('webhooks.kasera');

// Versioned API (Sanctum personal access tokens).
Route::prefix('v1')->middleware(['auth:sanctum', 'tenant', 'plan.feature:api_access', 'throttle:api'])->group(function () {
    Route::get('/me', fn (\Illuminate\Http\Request $r) => response()->json([
        'id' => $r->user()->id,
        'name' => $r->user()->name,
        'email' => $r->user()->email,
        'tenant' => app('tenant.context')->tenant()?->only(['id', 'name', 'slug']),
        'role' => app('tenant.context')->role(),
    ]))->name('api.v1.me');

    Route::get('/usage', function (\Illuminate\Http\Request $r) {
        $ctx = app('tenant.context');
        $ctx->check();
        $usage = app(\App\Services\UsageService::class);

        return response()->json([
            'tenant' => $ctx->tenant()->name,
            'metrics' => collect(['max_users', 'api_calls', 'storage_mb'])->mapWithKeys(fn ($m) => [
                $m => ['used' => $usage->usage($ctx->tenant(), $m), 'limit' => $usage->limit($ctx->tenant(), $m)],
            ]),
        ]);
    })->name('api.v1.usage');

    Route::get('/payments', function (\Illuminate\Http\Request $r) {
        $ctx = app('tenant.context');
        $ctx->check();

        return response()->json($ctx->tenant()->payments()->latest()->take(50)
            ->get(['id', 'order_id', 'amount', 'currency', 'status', 'created_at']));
    })->name('api.v1.payments');

    // ---------------------------------------------------------------- business data
    //
    // Read-only on purpose. The business API is a data surface for integrations
    // (POS, mobile, reporting, marketplaces); writes stay behind the web layer so they
    // keep the full validation, permission and stock-locking rules of the domain
    // services instead of being re-implemented per endpoint.
    //
    // Static segments are declared BEFORE the {id} routes so a path like
    // "products/lookup" is never parsed as a product id.
    Route::get('/products/lookup', [\App\Http\Controllers\Api\V1\ProductController::class, 'lookup'])
        ->name('api.v1.products.lookup');
    Route::get('/products', [\App\Http\Controllers\Api\V1\ProductController::class, 'index'])
        ->name('api.v1.products.index');
    Route::get('/products/{product}', [\App\Http\Controllers\Api\V1\ProductController::class, 'show'])
        ->name('api.v1.products.show');

    Route::get('/categories', [\App\Http\Controllers\Api\V1\CategoryController::class, 'index'])
        ->name('api.v1.categories.index');
    Route::get('/categories/{category}', [\App\Http\Controllers\Api\V1\CategoryController::class, 'show'])
        ->name('api.v1.categories.show');

    Route::get('/brands', [\App\Http\Controllers\Api\V1\BrandController::class, 'index'])
        ->name('api.v1.brands.index');
    Route::get('/brands/{brand}', [\App\Http\Controllers\Api\V1\BrandController::class, 'show'])
        ->name('api.v1.brands.show');

    Route::get('/customers', [\App\Http\Controllers\Api\V1\CustomerController::class, 'index'])
        ->name('api.v1.customers.index');
    Route::get('/customers/{customer}', [\App\Http\Controllers\Api\V1\CustomerController::class, 'show'])
        ->name('api.v1.customers.show');
    Route::get('/customers/{customer}/sales', [\App\Http\Controllers\Api\V1\CustomerController::class, 'sales'])
        ->name('api.v1.customers.sales');

    Route::get('/suppliers', [\App\Http\Controllers\Api\V1\SupplierController::class, 'index'])
        ->name('api.v1.suppliers.index');
    Route::get('/suppliers/{supplier}', [\App\Http\Controllers\Api\V1\SupplierController::class, 'show'])
        ->name('api.v1.suppliers.show');

    Route::get('/warehouses', [\App\Http\Controllers\Api\V1\WarehouseController::class, 'index'])
        ->name('api.v1.warehouses.index');
    Route::get('/warehouses/{warehouse}', [\App\Http\Controllers\Api\V1\WarehouseController::class, 'show'])
        ->name('api.v1.warehouses.show');

    Route::get('/stock/summary', [\App\Http\Controllers\Api\V1\StockController::class, 'summary'])
        ->name('api.v1.stock.summary');
    Route::get('/stock', [\App\Http\Controllers\Api\V1\StockController::class, 'index'])
        ->name('api.v1.stock.index');
    Route::get('/stock/{product}', [\App\Http\Controllers\Api\V1\StockController::class, 'show'])
        ->name('api.v1.stock.show');

    Route::get('/sales', [\App\Http\Controllers\Api\V1\SaleController::class, 'index'])
        ->name('api.v1.sales.index');
    Route::get('/sales/{sale}', [\App\Http\Controllers\Api\V1\SaleController::class, 'show'])
        ->name('api.v1.sales.show');

    Route::get('/purchases', [\App\Http\Controllers\Api\V1\PurchaseController::class, 'index'])
        ->name('api.v1.purchases.index');
    Route::get('/purchases/{purchase}', [\App\Http\Controllers\Api\V1\PurchaseController::class, 'show'])
        ->name('api.v1.purchases.show');
});
