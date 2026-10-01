<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Plan pricing the gateway will actually accept.
 *
 * Production failed because `plans.price_monthly` for Pro had been set to 5 in the
 * database, below QRIS.PW's Rp 1,000 floor. The gateway refused every checkout with a
 * 400 and the customer was told "Unable to create the payment request. Please try
 * again." — advice that can never work, because retrying does not change a price.
 *
 * Nothing in the application writes plan prices except this seeder, so asserting the
 * seeded values against the provider floor turns that silent data corruption into a
 * failing test rather than a support ticket.
 */
class PlanPricingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlanSeeder::class);

        $this->owner = User::create([
            'name' => 'Pricing Owner', 'email' => 'plan-pricing@test.dev',
            'password' => bcrypt('password'), 'email_verified_at' => now(),
        ]);
    }

    public function test_every_purchasable_plan_clears_the_provider_minimum(): void
    {
        $minimum = (int) config('services.qrispw.min_amount');

        $this->assertGreaterThan(0, $minimum, 'The provider minimum must be configured.');

        foreach (Plan::where('is_active', true)->where('is_free_tier', false)->get() as $plan) {
            foreach (['monthly' => $plan->price_monthly, 'yearly' => $plan->price_yearly] as $cycle => $price) {
                if ((int) $price <= 0) {
                    continue; // annual billing is not offered yet
                }

                $this->assertGreaterThanOrEqual(
                    $minimum,
                    (int) $price,
                    "Plan \"{$plan->name}\" ({$plan->slug}) is priced Rp {$price} for {$cycle}, below the QRIS.PW "
                        ."minimum of Rp {$minimum}. Every checkout for this plan would fail."
                );
            }
        }
    }

    /** A price below the floor is refused locally, before the gateway is contacted. */
    public function test_a_plan_priced_below_the_minimum_never_reaches_the_gateway(): void
    {
        Log::spy();

        Plan::where('slug', 'pro')->firstOrFail()->update(['price_monthly' => 5]); // the production value

        Http::fake(); // any outbound call at all is the failure we are preventing

        $tenant = Tenant::create([
            'name' => 'Pricing Tenant', 'slug' => 'pricing-tenant', 'owner_id' => $this->owner->id,
        ]);
        $tenant->users()->attach($this->owner->id, [
            'role' => 'Owner', 'status' => 'active', 'joined_at' => now(),
        ]);

        $res = $this->actingAs($this->owner)
            ->withSession(['tenant_id' => $tenant->id])
            ->post('/billing/checkout', ['plan' => 'pro', 'cycle' => 'monthly']);

        $res->assertRedirect(route('billing.index'));

        // Not a "try again": retrying cannot change a price.
        $this->assertStringContainsString('Payment configuration error', session('error'));

        Http::assertNothingSent();
        $this->assertSame(0, Payment::count(), 'A refused amount must leave no payment behind.');
        $this->assertSame(0, Invoice::count(), 'A refused amount must leave no invoice behind.');

        // The operator gets the numbers needed to find the offending row.
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context = []) => $message === 'qrispw.amount_below_minimum'
                && ($context['amount'] ?? null) === 5
                && ($context['provider_minimum'] ?? null) === 1000)
            ->atLeast()->once();
    }
}