<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Custom workspace branding.
 *
 * Branding is workspace data, not account data: the same person can belong to several
 * workspaces and each presents itself differently. These tests pin the three rules
 * that make that safe — it is per workspace, it falls back to the product identity
 * when unset, and only an account holding `manage_settings` (Owner/Admin) may change
 * it or read another workspace's logo.
 */
class WorkspaceBrandingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(PlanSeeder::class);

        // The owner is created before the workspace exists (the workspace needs an
        // owner_id), so account creation and membership are separate helpers.
        $this->owner = $this->account('brand-owner');
        $this->tenant = $this->workspace($this->owner, 'brand-workspace');
    }

    /** A plain account, attached to the shared workspace as $role. */
    private function account(string $slug): User
    {
        return User::create([
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'email' => $slug.'@test.dev',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
    }

    private function user(string $slug, string $role = 'Staff'): User
    {
        $user = $this->account($slug);

        $this->tenant->users()->attach($user->id, [
            'role' => $role, 'status' => TenantUser::STATUS_ACTIVE, 'joined_at' => now(),
        ]);

        return $user;
    }

    private function workspace(User $owner, string $slug): Tenant
    {
        $tenant = Tenant::create([
            'name' => ucfirst($slug), 'slug' => $slug, 'owner_id' => $owner->id, 'status' => 'active',
        ]);

        $tenant->users()->attach($owner->id, [
            'role' => 'Owner', 'status' => TenantUser::STATUS_ACTIVE, 'joined_at' => now(),
        ]);

        return $tenant;
    }

    private function inWorkspace(?User $user = null, ?Tenant $tenant = null): static
    {
        return $this->actingAs($user ?? $this->owner)
            ->withSession(['tenant_id' => ($tenant ?? $this->tenant)->id]);
    }

    public function test_the_owner_saves_a_brand_name_and_description(): void
    {
        $this->inWorkspace()->patch('/branding', [
            'brand_name' => 'Toko ABC',
            'brand_tagline' => 'Solusi inventory dan penjualan modern',
        ])->assertRedirect(route('settings.index', ['tab' => 'branding']))->assertSessionHas('success');

        $this->tenant->refresh();

        $this->assertSame('Toko ABC', $this->tenant->brand_name);
        $this->assertSame('Solusi inventory dan penjualan modern', $this->tenant->brand_tagline);
    }

    public function test_saved_branding_replaces_the_product_identity_in_the_shell(): void
    {
        $this->inWorkspace()->patch('/branding', [
            'brand_name' => 'Toko ABC',
            'brand_tagline' => 'Inventory solution',
        ]);

        $html = $this->inWorkspace()->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('Toko ABC', $html);
        $this->assertStringContainsString('Inventory solution', $html);
    }

    public function test_a_workspace_without_branding_keeps_the_cultiv_default(): void
    {
        $html = $this->inWorkspace()->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString(config('app.name'), $html);
        $this->assertStringContainsString(config('app.tagline'), $html);
        $this->assertFalse($this->tenant->hasCustomBranding());
    }

    public function test_clearing_the_fields_restores_the_default_identity(): void
    {
        $this->inWorkspace()->patch('/branding', ['brand_name' => 'Toko ABC', 'brand_tagline' => 'Custom']);

        // Empty strings are stored as NULL, which the model reads as "use the default".
        $this->inWorkspace()->patch('/branding', ['brand_name' => '', 'brand_tagline' => '']);

        $this->tenant->refresh();

        $this->assertNull($this->tenant->brand_name);
        $this->assertNull($this->tenant->brand_tagline);
        $this->assertSame(config('app.name'), $this->tenant->brandName());
        $this->assertSame(config('app.tagline'), $this->tenant->brandTagline());
    }

    public function test_branding_is_per_workspace(): void
    {
        $otherOwner = $this->user('other-brand-owner', 'Owner');
        $other = $this->workspace($otherOwner, 'second-workspace');

        $this->inWorkspace()->patch('/branding', ['brand_name' => 'Bisnis Cultiv']);
        $this->inWorkspace($otherOwner, $other)->patch('/branding', ['brand_name' => 'Toko ABC']);

        $this->assertSame('Bisnis Cultiv', $this->tenant->fresh()->brandName());
        $this->assertSame('Toko ABC', $other->fresh()->brandName());
    }

    public function test_an_admin_may_change_branding_but_a_plain_member_may_not(): void
    {
        $admin = $this->user('brand-admin', 'Admin');
        $staff = $this->user('brand-staff', 'Staff');

        // `manage_settings` is the existing registry entry for workspace settings.
        $this->inWorkspace($admin)->patch('/branding', ['brand_name' => 'By Admin'])
            ->assertRedirect(route('settings.index', ['tab' => 'branding']));
        $this->assertSame('By Admin', $this->tenant->fresh()->brand_name);

        $this->inWorkspace($staff)->patch('/branding', ['brand_name' => 'By Staff'])
            ->assertForbidden();
        $this->assertSame('By Admin', $this->tenant->fresh()->brand_name);
    }

    public function test_a_member_only_sees_the_branding_read_only(): void
    {
        $staff = $this->user('readonly-staff', 'Staff');
        $this->tenant->update(['brand_name' => 'Toko ABC']);

        $html = $this->inWorkspace($staff)->get(route('settings.index', ['tab' => 'branding']))->assertOk()->getContent();

        $this->assertStringContainsString('Toko ABC', $html);
        // No editable controls for somebody who cannot save.
        $this->assertStringNotContainsString('name="brand_name"', $html);
        $this->assertStringNotContainsString('name="brand_logo"', $html);
        $this->assertStringContainsString(
            __('Only the workspace owner or an admin can change the branding.'),
            $html
        );
    }

    public function test_branding_cannot_be_saved_without_an_active_workspace(): void
    {
        // No tenant context: the tenant middleware sends the account to the hub.
        $this->actingAs($this->owner)
            ->patch('/branding', ['brand_name' => 'No Workspace'])
            ->assertRedirect(route('tenants.index'));

        $this->assertNull($this->tenant->fresh()->brand_name);
    }

    public function test_the_length_caps_are_enforced_server_side(): void
    {
        $this->inWorkspace()->patch('/branding', ['brand_name' => str_repeat('a', 31)])
            ->assertSessionHasErrors('brand_name');

        $this->inWorkspace()->patch('/branding', ['brand_tagline' => str_repeat('b', 61)])
            ->assertSessionHasErrors('brand_tagline');

        $this->assertNull($this->tenant->fresh()->brand_name);
        $this->assertNull($this->tenant->fresh()->brand_tagline);
    }

    public function test_a_logo_is_uploaded_and_then_served_to_the_workspace(): void
    {
        $logo = UploadedFile::fake()->image('logo.png', 256, 256);

        $this->inWorkspace()->patch('/branding', ['brand_logo' => $logo])
            ->assertRedirect(route('settings.index', ['tab' => 'branding']));

        $path = $this->tenant->fresh()->brand_logo_path;

        $this->assertNotNull($path);
        $this->assertStringStartsWith("tenants/{$this->tenant->id}/branding/", $path);
        Storage::disk('local')->assertExists($path);

        // The shell serves it through the authenticated route, not a public URL.
        $this->inWorkspace()->get(route('branding.logo', $this->tenant))->assertOk();
    }

    public function test_uploading_a_new_logo_replaces_and_deletes_the_previous_one(): void
    {
        $this->inWorkspace()->patch('/branding', [
            'brand_logo' => UploadedFile::fake()->image('first.png', 256, 256),
        ]);

        $first = $this->tenant->fresh()->brand_logo_path;
        Storage::disk('local')->assertExists($first);

        $this->inWorkspace()->patch('/branding', [
            'brand_logo' => UploadedFile::fake()->image('second.png', 256, 256),
        ]);

        $second = $this->tenant->fresh()->brand_logo_path;

        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertExists($second);
        // The superseded asset is cleaned up instead of piling up on the disk.
        Storage::disk('local')->assertMissing($first);
    }

    public function test_a_logo_can_be_removed_explicitly(): void
    {
        $this->inWorkspace()->patch('/branding', [
            'brand_logo' => UploadedFile::fake()->image('logo.png', 256, 256),
        ]);

        $path = $this->tenant->fresh()->brand_logo_path;

        $this->inWorkspace()->patch('/branding', ['remove_logo' => '1'])
            ->assertRedirect(route('settings.index', ['tab' => 'branding']));

        $this->assertNull($this->tenant->fresh()->brand_logo_path);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_a_non_image_upload_is_rejected(): void
    {
        // SVG is deliberately excluded: it can carry script, and a brand mark is
        // rendered in the chrome of every page.
        $this->inWorkspace()
            ->patch('/branding', ['brand_logo' => UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml')])
            ->assertSessionHasErrors('brand_logo');

        $this->assertNull($this->tenant->fresh()->brand_logo_path);
    }

    public function test_a_workspace_logo_is_not_served_to_another_workspace(): void
    {
        $this->inWorkspace()->patch('/branding', [
            'brand_logo' => UploadedFile::fake()->image('logo.png', 256, 256),
        ]);

        $otherOwner = $this->user('logo-other-owner', 'Owner');
        $other = $this->workspace($otherOwner, 'logo-other-workspace');

        // Membership in your own workspace is not access to somebody else's brand mark.
        $this->inWorkspace($otherOwner, $other)
            ->get(route('branding.logo', $this->tenant))
            ->assertNotFound();
    }

    public function test_the_brand_identity_form_is_hidden_without_a_workspace(): void
    {
        $html = $this->actingAs($this->owner)->get(route('settings.index', ['tab' => 'branding']))->assertOk()->getContent();

        $this->assertStringContainsString(__('Brand Identity'), $html);
        $this->assertStringContainsString(__('Select or create a workspace first'), $html);
        $this->assertStringNotContainsString('name="brand_name"', $html);
    }
}
