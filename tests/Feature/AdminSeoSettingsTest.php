<?php

namespace Tests\Feature;

use App\Models\SeoSetting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * /admin/seo — platform-wide SEO settings.
 *
 * What matters here is not "the form saves" but that a saved value actually changes the
 * markup a crawler sees, that an unset value still falls back to config, and that a
 * normal workspace user can never open the screen.
 */
class AdminSeoSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $member;

    private Tenant $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Platform Admin', 'email' => 'admin-seo@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
            'is_platform_admin' => true,
        ]);

        $this->member = User::create([
            'name' => 'Normal User', 'email' => 'member-seo@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);

        $this->workspace = Tenant::create([
            'name' => 'Toko SEO', 'slug' => 'toko-seo',
            'owner_id' => $this->member->id, 'status' => 'active',
        ]);
        $this->workspace->users()->attach($this->member->id, [
            'role' => 'Owner', 'status' => 'active', 'joined_at' => now(),
        ]);
    }

    private function asAdmin()
    {
        return $this->actingAs($this->admin)->withSession(['tenant_id' => $this->workspace->id]);
    }

    private function asMember()
    {
        return $this->actingAs($this->member)->withSession(['tenant_id' => $this->workspace->id]);
    }

    // ------------------------------------------------------------ authorization

    public function test_only_a_platform_admin_can_open_the_seo_screen(): void
    {
        $this->asAdmin()->get('/admin/seo')->assertOk();

        // A signed-in workspace member with a perfectly valid session is still refused.
        $this->asMember()->get('/admin/seo')->assertForbidden();
    }

    public function test_a_normal_user_cannot_change_the_seo_settings(): void
    {
        $this->asMember()->put('/admin/seo', ['site_name' => 'Hijacked'])->assertForbidden();

        $this->assertNull(SeoSetting::query()->first()?->site_name);
    }

    public function test_an_anonymous_visitor_cannot_reach_the_seo_screen(): void
    {
        $this->get('/admin/seo')->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------ saving

    public function test_seo_settings_are_saved_and_survive_a_reload(): void
    {
        $this->asAdmin()->put('/admin/seo', [
            'site_name' => 'Cultiv',
            'default_title' => 'Cultiv — Kelola Bisnis',
            'default_description' => 'Kelola operasional bisnis dalam satu workspace.',
            'canonical_url' => 'https://cultiv.id',
            'theme_color' => '#4f46e5',
        ])->assertRedirect(route('admin.seo.edit'))
            ->assertSessionHas('status.message', __('SEO changes saved.'));

        $settings = SeoSetting::query()->firstOrFail();

        $this->assertSame('Cultiv', $settings->site_name);
        $this->assertSame('Cultiv — Kelola Bisnis', $settings->default_title);

        // The values must come back on a fresh load of the screen.
        $this->asAdmin()->get('/admin/seo')
            ->assertOk()
            ->assertSee('Cultiv — Kelola Bisnis');
    }

    public function test_blank_fields_fall_back_to_config_rather_than_blanking_the_tags(): void
    {
        $this->asAdmin()->put('/admin/seo', [
            'site_name' => 'Cultiv',
            // Submitted empty on purpose: the site must return to its config default.
            'default_title' => '',
            'og_title' => '',
        ])->assertRedirect();

        $settings = SeoSetting::query()->firstOrFail();

        $this->assertNull($settings->default_title, 'A cleared field must be NULL, not an empty string.');
        $this->assertNull($settings->og_title);

        // …so the public page still renders a complete title.
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('<title>'.config('seo.default_title').'</title>', $html);
    }

    // --------------------------------------------- applied to the public website

    public function test_saved_settings_are_rendered_into_the_public_metadata(): void
    {
        $this->asAdmin()->put('/admin/seo', [
            'site_name' => 'Cultiv Nusantara',
            'default_title' => 'Cultiv Nusantara — Judul Utama',
            'default_description' => 'Deskripsi khusus untuk mesin pencari.',
            'og_title' => 'Judul Sosial',
            'og_description' => 'Deskripsi sosial.',
            'og_site_name' => 'Situs Sosial',
            'og_type' => 'website',
            'twitter_card_type' => 'summary',
            'twitter_title' => 'Judul X',
            'twitter_description' => 'Deskripsi X',
        ])->assertRedirect();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<title>Cultiv Nusantara — Judul Utama</title>', $html);
        $this->assertStringContainsString('content="Deskripsi khusus untuk mesin pencari."', $html);
        $this->assertStringContainsString('property="og:title" content="Judul Sosial"', $html);
        $this->assertStringContainsString('property="og:description" content="Deskripsi sosial."', $html);
        $this->assertStringContainsString('property="og:site_name" content="Situs Sosial"', $html);
        $this->assertStringContainsString('name="twitter:card" content="summary"', $html);
        $this->assertStringContainsString('name="twitter:title" content="Judul X"', $html);
        $this->assertStringContainsString('name="twitter:description" content="Deskripsi X"', $html);
    }

    public function test_the_site_name_is_appended_to_page_titles(): void
    {
        $this->asAdmin()->put('/admin/seo', ['site_name' => 'Cultiv Nusantara'])->assertRedirect();

        // Log back out: /login is guest-only, so it redirects an authenticated session.
        $this->post('/logout');

        // /login is a titled, non-home route, so it assembles "[page] — [site name]".
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('— Cultiv Nusantara', $html);
    }

    public function test_a_canonical_url_override_is_used_for_every_page(): void
    {
        $this->asAdmin()->put('/admin/seo', ['canonical_url' => 'https://new-domain.example'])
            ->assertRedirect();

        $this->post('/logout');

        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString(
            '<link rel="canonical" href="https://new-domain.example/login">',
            $html
        );
    }

    public function test_the_theme_colour_override_reaches_the_browser_toolbar_tag(): void
    {
        $this->asAdmin()->put('/admin/seo', ['theme_color' => '#ff0000'])->assertRedirect();

        $this->get('/')->assertOk()->assertSee('<meta name="theme-color" content="#ff0000">', false);
    }

    // ------------------------------------------------- favicon and share images

    public function test_an_uploaded_favicon_is_used_and_the_old_one_is_deleted(): void
    {
        Storage::fake(config('saas.uploads.disk'));

        $this->asAdmin()->put('/admin/seo', [
            'favicon' => UploadedFile::fake()->image('icon.png', 64, 64),
        ])->assertRedirect();

        $path = SeoSetting::query()->firstOrFail()->favicon_path;

        $this->assertNotNull($path, 'The favicon was not stored.');
        Storage::disk(config('saas.uploads.disk'))->assertExists($path);

        // Referenced by the rendered head, not merely saved.
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString(route('seo.asset', ['file' => $path]), $html);

        // And actually servable, so the reference is not a 404 in the tab strip.
        $this->get(route('seo.asset', ['file' => $path]))->assertOk();

        // Replacing it drops the superseded file.
        $this->asAdmin()->put('/admin/seo', [
            'favicon' => UploadedFile::fake()->image('icon2.png', 64, 64),
        ])->assertRedirect();

        Storage::disk(config('saas.uploads.disk'))->assertMissing($path);
    }

    public function test_an_uploaded_og_image_is_used_for_both_social_cards(): void
    {
        Storage::fake(config('saas.uploads.disk'));

        $this->asAdmin()->put('/admin/seo', [
            'og_image' => UploadedFile::fake()->image('share.png', 1200, 630),
        ])->assertRedirect();

        $path = SeoSetting::query()->firstOrFail()->og_image_path;
        $url = route('seo.asset', ['file' => $path]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('property="og:image" content="'.$url.'"', $html);
        // No twitter image was uploaded, so the OG image is reused rather than left blank.
        $this->assertStringContainsString('name="twitter:image" content="'.$url.'"', $html);
    }

    public function test_a_removed_favicon_falls_back_to_the_built_in_file(): void
    {
        Storage::fake(config('saas.uploads.disk'));

        $this->asAdmin()->put('/admin/seo', [
            'favicon' => UploadedFile::fake()->image('icon.png', 64, 64),
        ])->assertRedirect();

        $this->asAdmin()->put('/admin/seo', ['remove_favicon' => '1'])->assertRedirect();

        $this->assertNull(SeoSetting::query()->firstOrFail()->favicon_path);

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString(asset('favicon.svg'), $html);
    }

    public function test_the_asset_route_refuses_a_path_that_is_not_published(): void
    {
        Storage::fake(config('saas.uploads.disk'));

        // A real file on the disk, but nothing in the settings references it: this route
        // must not become a way to read arbitrary tenant uploads.
        Storage::disk(config('saas.uploads.disk'))->put('tenants/1/secret.pdf', 'x');

        $this->get(route('seo.asset', ['file' => 'tenants/1/secret.pdf']))->assertNotFound();
    }

    public function test_a_non_image_favicon_is_rejected(): void
    {
        Storage::fake(config('saas.uploads.disk'));

        $this->asAdmin()->put('/admin/seo', [
            'favicon' => UploadedFile::fake()->create('payload.php', 8),
        ])->assertSessionHasErrors('favicon');

        $this->assertNull(SeoSetting::query()->first()?->favicon_path);
    }

    // -------------------------------------------------------------- validation

    public function test_invalid_values_are_rejected_and_nothing_is_stored(): void
    {
        $this->asAdmin()->put('/admin/seo', [
            'canonical_url' => 'not-a-url',
            'theme_color' => 'blue',
            'og_type' => 'nonsense',
            'twitter_card_type' => 'banner',
            'twitter_handle' => 'way too long for a handle',
        ])->assertSessionHasErrors([
            'canonical_url', 'theme_color', 'og_type', 'twitter_card_type', 'twitter_handle',
        ]);

        $settings = SeoSetting::query()->first();

        // No partial write: either no row yet, or a row holding none of the rejected values.
        $this->assertTrue(
            $settings === null || $settings->canonical_url === null,
            'A rejected submission must not store a partial value.'
        );
    }

    public function test_an_over_long_description_is_rejected(): void
    {
        $this->asAdmin()->put('/admin/seo', [
            'default_description' => str_repeat('a', 1001),
        ])->assertSessionHasErrors('default_description');
    }

    // ------------------------------------------------------------------ robots

    public function test_indexing_can_be_switched_off_site_wide(): void
    {
        // Default: unchanged from the behaviour the site had before this feature.
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('name="robots" content="index, follow"', $html);

        $this->asAdmin()->put('/admin/seo')->assertRedirect();

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('name="robots" content="noindex, nofollow"', $html);
    }

    public function test_switching_indexing_off_never_unpublishes_a_public_page_into_an_error(): void
    {
        $this->asAdmin()->put('/admin/seo')->assertRedirect();

        // Turning it off must still render a complete, well-formed page.
        $this->get('/')->assertOk()->assertSee('<title>', false);
    }

    public function test_the_switch_can_never_publish_a_private_page(): void
    {
        // Even with the master switch explicitly ON, an authenticated admin screen stays
        // noindex — the per-route allow list is still the real gate. (A platform admin is
        // not a member of the workspace, so the tenant /dashboard is not a page they can
        // render at all.)
        $this->asAdmin()->put('/admin/seo', ['allow_indexing' => '1'])->assertRedirect();

        $html = $this->asAdmin()->get('/admin/seo')->assertOk()->getContent();
        $this->assertStringContainsString('name="robots" content="noindex, nofollow"', $html);
    }

    public function test_the_sitemap_stays_valid_and_contains_no_private_route(): void
    {
        $this->asAdmin()->put('/admin/seo', [
            'allow_indexing' => '1',
            'canonical_url' => 'https://new-domain.example',
        ])->assertRedirect();

        $body = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString('<urlset', $body);

        foreach (['/admin', '/dashboard', '/customers', '/settings', '/profile'] as $private) {
            $this->assertStringNotContainsString($private, $body, "The sitemap leaked {$private}.");
        }
    }

    // -------------------------------------------------------------------- audit

    public function test_saving_seo_settings_is_audited(): void
    {
        $this->asAdmin()->put('/admin/seo', ['site_name' => 'Cultiv'])->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'seo.updated',
        ]);
    }
}