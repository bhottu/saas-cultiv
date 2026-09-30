<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Placeholder examples on the secondary forms — customers and profile.
 * The product form is pinned in ProductCrudTest, the sale form in SalesCreateUxTest.
 */
class PlaceholderExamplesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create([
            'name' => 'Placeholder Owner',
            'email' => 'placeholder-owner@test.dev',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $this->tenant = Tenant::create([
            'name' => 'Placeholder Tenant',
            'slug' => 'placeholder-tenant',
            'owner_id' => $this->owner->id,
        ]);
        $this->tenant->users()->attach($this->owner->id, [
            'role' => 'Owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }

    private function asOwner()
    {
        return $this->actingAs($this->owner)->withSession(['tenant_id' => $this->tenant->id]);
    }

    public function test_customer_form_placeholders_show_name_phone_email_examples(): void
    {
        $html = $this->asOwner()->get('/customers/create')->assertOk()->getContent();

        $this->assertStringContainsString('placeholder="e.g. Budi Santoso"', $html);
        $this->assertStringContainsString('placeholder="e.g. 081234567890"', $html);
        $this->assertStringContainsString('placeholder="e.g. budi@example.com"', $html);
        $this->assertStringContainsString('placeholder="e.g. 500000"', $html); // credit limit (Rp)
    }

    public function test_profile_form_placeholders_show_name_and_email_examples(): void
    {
        $html = $this->asOwner()->get('/profile')->assertOk()->getContent();

        $this->assertStringContainsString('placeholder="e.g. Budi Santoso"', $html);
        $this->assertStringContainsString('placeholder="e.g. budi@example.com"', $html);
    }
}