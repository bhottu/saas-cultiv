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

Route::post('/ai/telegram/webhook', \App\Http\Controllers\AiTelegramWebhookController::class)
    ->middleware('throttle:webhook')
    ->name('ai.telegram.webhook');

// Versioned API (Sanctum personal access tokens).
Route::prefix('v1')->middleware(['auth:sanctum', 'api.tenant', 'plan.feature:api_access', 'throttle:api'])->group(function () {
    Route::get('/me', fn (\Illuminate\Http\Request $r) => response()->json([
        'id' => $r->user()->id,
        'name' => $r->user()->name,
        'email' => $r->user()->email,
        'tenant' => app('tenant.context')->tenant()?->only(['id', 'name', 'slug']),
        'role' => app('tenant.context')->role(),
    ]))->middleware('api.guard')->name('api.v1.me');

    Route::get('/usage', function (\Illuminate\Http\Request $r) {
        $ctx = app('tenant.context');
        $ctx->check();
        $usage = app(\App\Services\UsageService::class);

        return response()->json([
            'tenant' => $ctx->tenant()->name,
            'metrics' => collect(['max_users', 'api_calls', 'storage_mb', 'ai_messages'])->mapWithKeys(fn ($m) => [
                $m => ['used' => $usage->usage($ctx->tenant(), $m), 'limit' => $usage->limit($ctx->tenant(), $m)],
            ]),
        ]);
    })->middleware('api.guard')->name('api.v1.usage');

    Route::get('/payments', function (\Illuminate\Http\Request $r) {
        $ctx = app('tenant.context');
        $ctx->check();

        return response()->json($ctx->tenant()->payments()->latest()->take(50)
            ->get(['id', 'order_id', 'amount', 'currency', 'status', 'created_at']));
    })->middleware('api.guard:payments,read')->name('api.v1.payments');

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
        ->middleware('api.guard:products,read')->name('api.v1.products.lookup');
    Route::get('/products', [\App\Http\Controllers\Api\V1\ProductController::class, 'index'])
        ->middleware('api.guard:products,read')->name('api.v1.products.index');
    Route::get('/products/{product}', [\App\Http\Controllers\Api\V1\ProductController::class, 'show'])
        ->middleware('api.guard:products,read')->name('api.v1.products.show');
    Route::post('/products', [\App\Http\Controllers\Api\V1\ProductController::class, 'store'])
        ->middleware('api.guard:products,write')
        ->name('api.v1.products.store');
    Route::match(['put', 'patch'], '/products/{product}', [\App\Http\Controllers\Api\V1\ProductController::class, 'update'])
        ->middleware('api.guard:products,write')
        ->name('api.v1.products.update');

    Route::get('/categories', [\App\Http\Controllers\Api\V1\CategoryController::class, 'index'])
        ->middleware('api.guard:categories,read')->name('api.v1.categories.index');
    Route::get('/categories/{category}', [\App\Http\Controllers\Api\V1\CategoryController::class, 'show'])
        ->middleware('api.guard:categories,read')->name('api.v1.categories.show');
    Route::post('/categories', [\App\Http\Controllers\Api\V1\CategoryController::class, 'store'])
        ->middleware('api.guard:categories,write')
        ->name('api.v1.categories.store');
    Route::match(['put', 'patch'], '/categories/{category}', [\App\Http\Controllers\Api\V1\CategoryController::class, 'update'])
        ->middleware('api.guard:categories,write')
        ->name('api.v1.categories.update');

    Route::get('/brands', [\App\Http\Controllers\Api\V1\BrandController::class, 'index'])
        ->middleware('api.guard:brands,read')->name('api.v1.brands.index');
    Route::get('/brands/{brand}', [\App\Http\Controllers\Api\V1\BrandController::class, 'show'])
        ->middleware('api.guard:brands,read')->name('api.v1.brands.show');
    Route::post('/brands', [\App\Http\Controllers\Api\V1\BrandController::class, 'store'])
        ->middleware('api.guard:brands,write')
        ->name('api.v1.brands.store');
    Route::match(['put', 'patch'], '/brands/{brand}', [\App\Http\Controllers\Api\V1\BrandController::class, 'update'])
        ->middleware('api.guard:brands,write')
        ->name('api.v1.brands.update');

    Route::get('/customers', [\App\Http\Controllers\Api\V1\CustomerController::class, 'index'])
        ->middleware('api.guard:customers,read')->name('api.v1.customers.index');
    Route::get('/customers/{customer}', [\App\Http\Controllers\Api\V1\CustomerController::class, 'show'])
        ->middleware('api.guard:customers,read')->name('api.v1.customers.show');
    Route::post('/customers', [\App\Http\Controllers\Api\V1\CustomerController::class, 'store'])
        ->middleware('api.guard:customers,write')
        ->name('api.v1.customers.store');
    Route::match(['put', 'patch'], '/customers/{customer}', [\App\Http\Controllers\Api\V1\CustomerController::class, 'update'])
        ->middleware('api.guard:customers,write')
        ->name('api.v1.customers.update');
    Route::get('/customers/{customer}/sales', [\App\Http\Controllers\Api\V1\CustomerController::class, 'sales'])
        ->middleware('api.guard:customers,read')->name('api.v1.customers.sales');

    Route::get('/suppliers', [\App\Http\Controllers\Api\V1\SupplierController::class, 'index'])
        ->middleware('api.guard:suppliers,read')->name('api.v1.suppliers.index');
    Route::get('/suppliers/{supplier}', [\App\Http\Controllers\Api\V1\SupplierController::class, 'show'])
        ->middleware('api.guard:suppliers,read')->name('api.v1.suppliers.show');
    Route::post('/suppliers', [\App\Http\Controllers\Api\V1\SupplierController::class, 'store'])
        ->middleware('api.guard:suppliers,write')
        ->name('api.v1.suppliers.store');
    Route::match(['put', 'patch'], '/suppliers/{supplier}', [\App\Http\Controllers\Api\V1\SupplierController::class, 'update'])
        ->middleware('api.guard:suppliers,write')
        ->name('api.v1.suppliers.update');

    Route::get('/warehouses', [\App\Http\Controllers\Api\V1\WarehouseController::class, 'index'])
        ->middleware('api.guard:warehouses,read')->name('api.v1.warehouses.index');
    Route::get('/warehouses/{warehouse}', [\App\Http\Controllers\Api\V1\WarehouseController::class, 'show'])
        ->middleware('api.guard:warehouses,read')->name('api.v1.warehouses.show');

    Route::get('/stock/summary', [\App\Http\Controllers\Api\V1\StockController::class, 'summary'])
        ->middleware('api.guard:stock,read')->name('api.v1.stock.summary');
    Route::get('/stock', [\App\Http\Controllers\Api\V1\StockController::class, 'index'])
        ->middleware('api.guard:stock,read')->name('api.v1.stock.index');
    Route::get('/stock/{product}', [\App\Http\Controllers\Api\V1\StockController::class, 'show'])
        ->middleware('api.guard:stock,read')->name('api.v1.stock.show');

    Route::get('/sales', [\App\Http\Controllers\Api\V1\SaleController::class, 'index'])
        ->middleware('api.guard:sales,read')->name('api.v1.sales.index');
    Route::get('/sales/{sale}', [\App\Http\Controllers\Api\V1\SaleController::class, 'show'])
        ->middleware('api.guard:sales,read')->name('api.v1.sales.show');

    Route::get('/purchases', [\App\Http\Controllers\Api\V1\PurchaseController::class, 'index'])
        ->middleware('api.guard:purchases,read')->name('api.v1.purchases.index');
    Route::get('/purchases/{purchase}', [\App\Http\Controllers\Api\V1\PurchaseController::class, 'show'])
        ->middleware('api.guard:purchases,read')->name('api.v1.purchases.show');
});
