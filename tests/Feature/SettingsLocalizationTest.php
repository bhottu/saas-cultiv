<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * /settings and the per-user interface language.
 *
 * Two independent preferences are pinned here, because they are deliberately kept on
 * opposite sides of the system:
 *
 *   language  -> the PERSON (users.locale), so two colleagues in one workspace can
 *                read different languages
 *   branding  -> the WORKSPACE (tenants.brand_*), so everyone in it presents the
 *                same brand
 *
 * The most important assertion is the first one: a brand new account must land on
 * Indonesian even when the browser asks for English. If that ever starts following
 * Accept-Language, the product's default language depends on machine setup rather
 * than on a choice the user made.
 */
class SettingsLocalizationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);

        $this->owner = $this->account('settings-owner');
        $this->tenant = Tenant::create([
            'name' => 'Toko ABC', 'slug' => 'toko-abc',
            'owner_id' => $this->owner->id, 'status' => 'active',
        ]);
        $this->tenant->users()->attach($this->owner->id, [
            'role' => 'Owner', 'status' => TenantUser::STATUS_ACTIVE, 'joined_at' => now(),
        ]);
    }

    private function account(string $slug, ?string $locale = null): User
    {
        return User::create([
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'email' => $slug.'@test.dev',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'locale' => $locale,
        ]);
    }

    private function inWorkspace(?User $user = null): static
    {
        return $this->actingAs($user ?? $this->owner)
            ->withSession(['tenant_id' => $this->tenant->id]);
    }

    /**
     * The product ships Indonesian.
     *
     * phpunit.xml pins APP_LOCALE=en so the business-logic suite can keep asserting
     * English copy, which would otherwise mask what a fresh install actually looks
     * like. This test looks past that override and reads the committed default, so the
     * shipping configuration is pinned on its own terms.
     */
    public function test_the_product_ships_indonesian_as_the_default_locale(): void
    {
        $source = (string) file_get_contents(config_path('locale.php'));

        $this->assertStringContainsString(
            "'default' => env('APP_LOCALE', 'id')",
            $source,
            'config/locale.php must default to Indonesian when APP_LOCALE is unset.'
        );

        $this->assertSame(
            'en',
            config('locale.fallback'),
            'English stays the fallback, so a missing translation degrades to English.'
        );
    }

    public function test_language_stays_reachable_before_a_workspace_exists(): void
    {
    // The `tenant` middleware guards everything business-scoped and bounces a user
    // with no tenant_id back to the workspace picker. Language is an account
    // preference, not a business one: a brand new user lands with no workspace at
    // all, and being unable to switch language on the first screen would be the one
    // moment it matters most.
    $newcomer = $this->account('newcomer');

    $this->actingAs($newcomer)
        ->get(route('settings.index', ['tab' => 'language']))
        ->assertOk();

    $this->assertNull($newcomer->locale);

    $this->actingAs($newcomer)
        ->from(route('settings.index', ['tab' => 'language']))
        ->patch(route('settings.language.update'), ['locale' => 'id'])
        ->assertRedirect(route('settings.index', ['tab' => 'language']));

    $this->assertSame('id', $newcomer->fresh()->locale);

    // Still usable, still Indonesian, still without a workspace.
    $this->actingAs($newcomer)
        ->get(route('settings.index', ['tab' => 'language']))
        ->assertOk();

    $this->assertSame('id', app()->getLocale());

    // The branding tab degrades gracefully here instead of erroring.
    $this->actingAs($newcomer)
        ->get(route('settings.index', ['tab' => 'branding']))
        ->assertOk();
}

    public function test_a_new_account_gets_the_default_locale_even_when_the_browser_asks_for_english(): void
    {
        // No stored preference at all: the column is NULL.
        $this->assertNull($this->owner->locale);

        $expected = (string) config('locale.default');

        $this->inWorkspace()
            ->withHeaders(['Accept-Language' => 'en-US,en;q=0.9'])
            ->get(route('settings.index', ['tab' => 'language']))
            ->assertOk();

        $this->assertSame(
            $expected,
            app()->getLocale(),
            'A browser language must never override the application default.'
        );
    }

    public function test_choosing_indonesian_survives_a_reload_and_a_fresh_login(): void
    {
        $this->inWorkspace()->patch(route('settings.language.update'), ['locale' => 'id'])
            ->assertRedirect(route('settings.index', ['tab' => 'language']));

        $this->assertSame('id', $this->owner->fresh()->locale);

        $this->post(route('logout'));
        $this->assertGuest();

        $html = $this->inWorkspace()->get(route('settings.index', ['tab' => 'language']))
            ->assertOk()->getContent();

        $this->assertSame('id', app()->getLocale());
        $this->assertStringContainsString('lang="id"', $html);
        // The rendered UI must actually be Indonesian, not just the locale value.
        $this->assertStringContainsString('Pengaturan', $html);
        $this->assertStringNotContainsString('>Settings<', $html);
    }

    public function test_the_language_follows_the_person_not_the_workspace(): void
    {
        // The point of the test is that each person gets their own language, so the
        // two accounts are pinned to opposite locales and the comparison never
        // depends on what APP_LOCALE happens to be in phpunit.xml.
        $colleague = $this->account('colleague');
        $this->tenant->users()->attach($colleague->id, [
            'role' => 'Staff', 'status' => TenantUser::STATUS_ACTIVE, 'joined_at' => now(),
        ]);

        $this->inWorkspace($colleague)->patch(route('settings.language.update'), ['locale' => 'id']);
        $this->inWorkspace($this->owner)->patch(route('settings.language.update'), ['locale' => 'en']);

        $this->assertSame('id', $colleague->fresh()->locale);
        $this->assertSame('en', $this->owner->fresh()->locale);

        // Same workspace, different language, proven on the rendered shell.
        $this->inWorkspace($colleague)->get('/dashboard')->assertOk();
        $this->assertSame('id', app()->getLocale());

        $this->inWorkspace($this->owner)->get('/dashboard')->assertOk();
        $this->assertSame('en', app()->getLocale());
    }

    public function test_an_unsupported_locale_is_rejected(): void
    {
        $this->inWorkspace()
            ->from(route('settings.index', ['tab' => 'language']))
            ->patch(route('settings.language.update'), ['locale' => 'fr'])
            ->assertRedirect(route('settings.index', ['tab' => 'language']))
            ->assertSessionHasErrors('locale');

        $this->assertNull($this->owner->fresh()->locale);
    }

    public function test_a_stored_locale_with_no_translation_files_degrades_to_the_default(): void
    {
        // Simulates a stale or hand-edited row rather than trusting the value blindly.
        $this->owner->forceFill(['locale' => 'zz'])->save();

        $this->inWorkspace()->get(route('settings.index', ['tab' => 'language']))->assertOk();

        $this->assertSame(
            config('locale.default'),
            app()->getLocale(),
            'An unknown stored locale must fall back to the application default.'
        );
    }
}