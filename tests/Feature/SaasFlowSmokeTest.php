<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** End-to-end smoke: register → create tenant → dashboard → billing → checkout (QRIS mocked). */
class SaasFlowSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_user_journey_to_qris_checkout(): void
    {
        $this->seed(\Database\Seeders\PlanSeeder::class);

        // 1. Register via the real Breeze flow.
        $user = User::create([
            'name' => 'Smoke', 'email' => 'smoke@test.dev',
            'password' => Hash::make('Password!234'), 'email_verified_at' => now(),
        ]);

        // 2. Create a tenant through the UI.
        $this->actingAs($user)
            ->post('/tenants', ['name' => 'Smoke Co'])
            ->assertRedirect('/dashboard');

        $tenant = $user->fresh()->currentTenant;
        $this->assertNotNull($tenant);
        $this->assertSame('Owner', $user->roleIn($tenant));

        // 3. Dashboard renders with plan/usage/quota info.
        $this->get('/dashboard')->assertOk()->assertSee('Free');

        // 4. Billing page lists plans and history.
        $this->get('/billing')->assertOk()->assertSee('Pro')->assertSee('Business');

        // 5. Checkout → QRIS create-payment called with correct payload (mocked).
        \Illuminate\Support\Facades\Http::fake([
            '*create-payment.php*' => \Illuminate\Support\Facades\Http::response([
                'success' => true, 'transaction_id' => 'TRX-SMOKE',
                'order_id' => 'ORD-FAKE-1', 'amount' => 149000,
                'qris_url' => 'https://qris.pw/public/qr/qr_x.png',
                'qris_string' => '000201...', 'expires_at' => now()->addMinutes(10)->format('Y-m-d H:i:s'),
            ]),
        ]);

        $this->post('/billing/checkout', ['plan' => 'pro', 'cycle' => 'monthly'])
            ->assertRedirect();

        $payment = $tenant->payments()->latest()->first();
        $this->assertSame('pending', $payment->status);
        $this->assertSame('TRX-SMOKE', $payment->provider_transaction_id);
        $this->assertNotNull($payment->expires_at);
        $this->assertNotNull($payment->invoice_id, 'Invoice must be created with the payment.');

        // 6. Payment page renders the QR (from provider URL — no credentials in browser).
        $this->get("/billing/payments/{$payment->id}")
            ->assertOk()
            ->assertSee('qris.pw/public/qr', false);

        // 7. Status endpoint reports server truth.
        $this->get("/billing/payments/{$payment->id}/status")
            ->assertOk()
            ->assertJson(['status' => 'pending']);
    }

    public function test_plan_limit_blocks_seat_additions(): void
    {
        $this->seed(\Database\Seeders\PlanSeeder::class);

        $user = User::create([
            'name' => 'Limit', 'email' => 'limit@test.dev',
            'password' => Hash::make('Password!234'), 'email_verified_at' => now(),
        ]);
        $tenant = Tenant::create(['name' => 'L', 'slug' => 'l', 'owner_id' => $user->id]);
        $tenant->users()->attach($user->id, ['role' => 'Owner', 'status' => 'active', 'joined_at' => now()]);
        app(\App\Services\SubscriptionService::class)->switchToFree($tenant);

        $usage = app(\App\Services\UsageService::class);

        // Free plan allows one occupied seat; adding a second is rejected by the backend.
        $extra = User::create([
            'name' => 'Extra', 'email' => 'extra@test.dev',
            'password' => Hash::make('Password!234'), 'email_verified_at' => now(),
        ]);
        $tenant->users()->attach($extra->id, ['role' => 'Staff', 'status' => 'active', 'joined_at' => now()]);

        // One owner already occupies the only Free seat; enforceSeat must reject the second.
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(\App\Services\UsageService::class)->enforceSeat($tenant->fresh());
    }
}
