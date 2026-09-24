<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Payment provider webhook: no auth, signature-verified, rate-limited, idempotent.
Route::post('/webhooks/qris', [WebhookController::class, 'qris'])
    ->middleware('throttle:webhook')
    ->name('webhooks.qris');

// Versioned API (Sanctum personal access tokens).
Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:api'])->group(function () {
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
});
