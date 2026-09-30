<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\ModuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Module lifecycle: NOT INSTALLED -> INSTALLED -> ACTIVE -> INACTIVE -> ACTIVE.
 *
 * The rule under test: DEACTIVATE IS NOT UNINSTALL. Switching a module off must keep
 * its install record, so the Module Center keeps offering "Activate" instead of
 * falling back to "Install" (which is a no-op on an existing record and previously
 * left the module permanently stuck).
 *
 * Every step goes through the real HTTP routes, so the rendered button set is
 * asserted as well as the database state: the reported bug was a UI that could not
 * express the state it was actually in.
 */
class ModuleLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Tenant $tenant;
    private Warehouse $warehouse;
    private Module $pos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ModuleSeeder::class);

        $this->owner = User::create([
            'name' => 'Lifecycle Owner', 'email' => 'lifecycle-owner@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);

        $this->tenant = Tenant::create([
            'name' => 'Toko Lifecycle', 'slug' => 'toko-lifecycle',
            'owner_id' => $this->owner->id, 'status' => 'active',
        ]);
        $this->tenant->users()->attach($this->owner->id, [
            'role' => 'Owner', 'status' => 'active', 'joined_at' => now(),
        ]);

        $this->warehouse = Warehouse::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Gudang Utama',
            'code' => 'MAIN', 'is_active' => true,
        ]);

        $this->pos = Module::where('key', 'pos')->firstOrFail();
    }

    private function asOwner()
    {
        return $this->actingAs($this->owner)->withSession(['tenant_id' => $this->tenant->id]);
    }

    private function install(): void
    {
        $this->asOwner()->post('/modules/'.$this->pos->slug.'/install');
    }

    private function activate(): void
    {
        $this->asOwner()->post('/modules/'.$this->pos->slug.'/activate');
    }

    private function deactivate(): void
    {
        $this->asOwner()->post('/modules/'.$this->pos->slug.'/deactivate');
    }

    /** The install record for this workspace, ignoring the tenant global scope. */
    private function installRecord(): ?TenantModule
    {
        return TenantModule::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('module_id', $this->pos->id)
            ->first();
    }

    private function assertStatus(string $expected): void
    {
        $this->assertSame($expected, $this->installRecord()?->status);
    }

    /** The rendered Module Center card for POS. */
    private function moduleCenterHtml(): string
    {
        return $this->asOwner()->get('/modules')->assertOk()->getContent();
    }

    // ------------------------------------------------------- the reported bug

    /**
     * THE regression: install -> activate -> deactivate -> activate.
     *
     * Before the fix the last POST hit "no install record" (404) and the page offered
     * only "Install", so a deactivated module could never be switched back on.
     */
    public function test_a_deactivated_module_can_be_activated_again(): void
    {
        $this->install();
        $this->activate();
        $this->assertStatus(TenantModule::STATUS_ACTIVE);

        $this->deactivate();
        $this->assertStatus(TenantModule::STATUS_INACTIVE);

        // The install record must survive: deactivate is not uninstall.
        $this->assertNotNull($this->installRecord(), 'Deactivate deleted the install record.');

        // The UI must OFFER the way back. This is the assertion that reproduces the
        // report: the record was intact all along, but the card rendered only
        // "Install", so there was no way to switch the module back on.
        $html = $this->moduleCenterHtml();
        $this->assertStringContainsString('Activate', $html, 'No Activate action was offered.');
        $this->assertStringNotContainsString(
            'modules/'.$this->pos->slug.'/install',
            $html,
            'A deactivated module still offered Install.'
        );

        $this->asOwner()->post('/modules/'.$this->pos->slug.'/activate')->assertRedirect();
        $this->assertStatus(TenantModule::STATUS_ACTIVE);

        // And the module is genuinely usable again.
        $this->asOwner()->get('/pos')->assertOk();
    }

    public function test_deactivate_keeps_the_record_and_the_page_offers_activate(): void
    {
        $this->install();
        $this->activate();
        $this->deactivate();

        $html = $this->moduleCenterHtml();

        $this->assertSame(1, TenantModule::withoutGlobalScopes()->count(), 'Uninstall was triggered.');

        // The state must be readable as "switched off", never as "Available".
        $this->assertStringContainsString(__('Inactive'), $html);
        $this->assertStringContainsString('Activate', $html);
        $this->assertStringNotContainsString(__('Available'), $html);
    }

    // --------------------------------------------------------- the 6-step flow

    public function test_not_installed_then_install_yields_installed(): void
    {
        $this->assertNull($this->installRecord(), 'Fixture should start with no install record.');

        $this->install();

        $this->assertStatus(TenantModule::STATUS_INSTALLED);
    }

    public function test_installed_then_activate_yields_active(): void
    {
        $this->install();
        $this->assertStatus(TenantModule::STATUS_INSTALLED);

        $this->activate();
        $this->assertStatus(TenantModule::STATUS_ACTIVE);
    }

    public function test_active_module_is_openable(): void
    {
        $this->install();
        $this->activate();

        // Open is not a state change: it just has to be reachable.
        $this->asOwner()->get('/pos')->assertOk();
    }

    public function test_full_lifecycle_round_trip(): void
    {
        // 1. Not Installed -> Install
        $this->install();
        $this->assertStatus(TenantModule::STATUS_INSTALLED);

        // 2. Installed -> Activate
        $this->activate();
        $this->assertStatus(TenantModule::STATUS_ACTIVE);

        // 3. Active -> Open
        $this->asOwner()->get('/pos')->assertOk();

        // 4. Active -> Deactivate (stays installed, switched off)
        $this->deactivate();
        $this->assertStatus(TenantModule::STATUS_INACTIVE);
        $this->assertNotNull($this->installRecord());

        // 5. Inactive -> Activate again, no re-install
        $this->activate();
        $this->assertStatus(TenantModule::STATUS_ACTIVE);

        // 6. Active -> Open again
        $this->asOwner()->get('/pos')->assertOk();

        // Still exactly one install row for the whole round trip.
        $this->assertSame(1, TenantModule::withoutGlobalScopes()->count());
    }

    public function test_a_deactivated_module_is_no_longer_reachable(): void
    {
        $this->install();
        $this->activate();
        $this->asOwner()->get('/pos')->assertOk();

        $this->deactivate();

        // Availability is still enforced: inactive means the routes are gated.
        $this->asOwner()->get('/pos')->assertRedirect(route('modules.index'));
    }

    // ------------------------------------------------------------ idempotency

    public function test_installing_twice_never_creates_a_second_record(): void
    {
        $this->install();
        $first = $this->installRecord()?->id;

        $this->install();
        $this->install();

        $this->assertSame(1, TenantModule::withoutGlobalScopes()->count());
        $this->assertSame($first, $this->installRecord()?->id);
    }

    public function test_install_after_deactivate_does_not_silently_reactivate(): void
    {
        $this->install();
        $this->activate();
        $this->deactivate();

        // A stale/duplicate Install click must not flip the module back on: only an
        // explicit Activate may. The user asked for a deliberate transition.
        $this->install();
        $this->assertStatus(TenantModule::STATUS_INACTIVE);
    }

    // ------------------------------------------------------ workspace isolation

    public function test_state_is_scoped_to_the_active_workspace(): void
    {
        $this->install();
        $this->activate();

        $otherOwner = User::create([
            'name' => 'Other Owner', 'email' => 'lifecycle-other@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);
        $otherTenant = Tenant::create([
            'name' => 'Toko Kedua', 'slug' => 'toko-kedua-'.Str::lower(Str::random(4)),
            'owner_id' => $otherOwner->id, 'status' => 'active',
        ]);
        $otherTenant->users()->attach($otherOwner->id, [
            'role' => 'Owner', 'status' => 'active', 'joined_at' => now(),
        ]);

        $this->deactivate();

        // Workspace A is off; workspace B is untouched and still not installed.
        $this->assertStatus(TenantModule::STATUS_INACTIVE);

        $html = $this->actingAs($otherOwner)
            ->withSession(['tenant_id' => $otherTenant->id])
            ->get('/modules')->assertOk()->getContent();

        $this->assertStringContainsString(__('Available'), $html);
        $this->assertStringNotContainsString(__('Inactive'), $html);

        // Workspace B never installed POS, so it must hold no record at all.
        $this->assertSame(
            0,
            TenantModule::withoutGlobalScopes()->where('tenant_id', $otherTenant->id)->count(),
            'Workspace A\'s install leaked into workspace B.',
        );
    }

    public function test_one_workspace_cannot_deactivate_anothers_module(): void
    {
        $this->install();
        $this->activate();

        $otherOwner = User::create([
            'name' => 'Owner Tiga', 'email' => 'lifecycle-third@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);
        $otherTenant = Tenant::create([
            'name' => 'Toko Ketiga', 'slug' => 'toko-ketiga-'.Str::lower(Str::random(4)),
            'owner_id' => $otherOwner->id, 'status' => 'active',
        ]);
        $otherTenant->users()->attach($otherOwner->id, [
            'role' => 'Owner', 'status' => 'active', 'joined_at' => now(),
        ]);

        $this->actingAs($otherOwner)
            ->withSession(['tenant_id' => $otherTenant->id])
            ->post('/modules/'.$this->pos->slug.'/deactivate');

        $this->assertStatus(TenantModule::STATUS_ACTIVE);
    }
}
