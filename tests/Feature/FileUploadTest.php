<?php

namespace Tests\Feature;

use App\Http\Controllers\FileController;
use App\Models\FileEntry;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The tenant file manager (/files) is switched OFF in config/saas.php, which stops the
 * routes from being registered at all.
 *
 * That is the point of testing it this way: the protection lives at the ROUTE layer, so a
 * request cannot reach FileController no matter what the client sends. Hiding the menu
 * link is a consequence, never the mechanism. The controller, the model and the `files`
 * table are untouched, so the feature stays reversible with one env flag plus a route
 * cache clear — test_the_whole_surface_comes_back demonstrates that by registering the
 * routes the flag would produce and re-running the behavioural checks.
 *
 * Internal uploads other features depend on are a separate concern and must keep working:
 * test_the_branding_logo_upload_is_unaffected pins the workspace logo, which travels
 * through `branding.update` and not through /files.
 */
class FileUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(\Database\Seeders\PlanSeeder::class);

        $this->owner = User::create([
            'name' => 'Owner', 'email' => 'fileowner@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);
        $this->tenant = Tenant::create(['name' => 'FT', 'slug' => 'ft', 'owner_id' => $this->owner->id]);
        $this->tenant->users()->attach($this->owner->id, ['role' => 'Owner', 'status' => 'active', 'joined_at' => now()]);
    }

    private function actingAsTenant(?User $user = null, ?Tenant $tenant = null): static
    {
        return $this->actingAs($user ?? $this->owner)
            ->withSession(['tenant_id' => ($tenant ?? $this->tenant)->id]);
    }

    /**
     * Register the file-manager routes exactly as config('saas.features.files_manager')
     * does in routes/web.php, so the enabled behaviour stays covered without a second
     * process or a real env change.
     */
    private function enableFileManager(): void
    {
        config(['saas.features.files_manager' => true]);

        Route::middleware(['web', 'auth', 'verified', 'tenant'])->group(function () {
            Route::middleware('throttle:uploads')->group(function () {
                Route::post('/files', [FileController::class, 'store'])->name('files.store');
            });
            Route::get('/files', [FileController::class, 'index'])->name('files.index');
            Route::get('/files/{file}/download', [FileController::class, 'download'])->name('files.download');
            Route::delete('/files/{file}', [FileController::class, 'destroy'])->name('files.destroy');
        });

        // RouteCollection caches its name index; routes added after boot are not in it,
        // so Route::has() would keep reporting the names as absent.
        Route::getRoutes()->refreshNameLookups();
    }

    // ------------------------------------------------------------- disabled state

    public function test_the_file_manager_is_off_by_default(): void
    {
        $this->assertFalse(
            config('saas.features.files_manager'),
            'The file manager must default to disabled.'
        );
    }

    public function test_every_file_manager_route_is_unreachable(): void
    {
        // An existing row, so "not found" proves the ROUTE is gone rather than the record
        // simply not existing yet.
        $file = FileEntry::create([
            'tenant_id' => $this->tenant->id, 'uploaded_by' => $this->owner->id,
            'disk' => 'local', 'path' => 'tenants/x/probe.txt',
            'original_name' => 'probe.txt', 'mime_type' => 'text/plain', 'size' => 5,
        ]);

        $upload = UploadedFile::fake()->create('report.pdf', 50, 'application/pdf');

        $this->actingAsTenant()->get('/files')->assertNotFound();
        $this->actingAsTenant()->get("/files/{$file->id}/download")->assertNotFound();
        $this->actingAsTenant()->post('/files', ['file' => $upload])->assertNotFound();
        $this->actingAsTenant()->delete("/files/{$file->id}")->assertNotFound();

        // The row is untouched: disabling a surface is not the same as deleting data.
        $this->assertSame(1, FileEntry::withoutGlobalScopes()->count());
    }

    public function test_the_names_are_not_even_registered(): void
    {
        foreach (['files.index', 'files.store', 'files.download', 'files.destroy'] as $name) {
            $this->assertFalse(Route::has($name), "{$name} must not be registered while the feature is off.");
        }
    }

    public function test_the_file_manager_is_absent_from_the_navigation(): void
    {
        $html = $this->actingAsTenant()->get('/dashboard')->assertOk()->getContent();

        preg_match('/<aside id="sidebar".*?<\/aside>/s', $html, $matches);
        $this->assertNotEmpty($matches, 'The shared shell sidebar is missing on /dashboard.');

        $this->assertStringNotContainsString('href="'.url('/files').'"', $matches[0]);
        $this->assertStringNotContainsString('>Files<', $matches[0]);
    }

    public function test_a_guest_meets_the_same_closed_door(): void
    {
        // 404, not a redirect to /login: with the routes unregistered there is nothing
        // for the auth middleware to protect, so even the existence of the page is hidden.
        $this->get('/files')->assertNotFound();
    }

    // ------------------------------------------------- internal uploads unaffected

    public function test_the_branding_logo_upload_is_unaffected(): void
    {
        // The workspace logo does NOT travel through /files: it posts to branding.update
        // and is served back from branding.logo, neither of which the flag touches.
        $logo = UploadedFile::fake()->image('logo.png', 200, 200);

        $this->actingAsTenant()
            ->patch(route('branding.update'), ['brand_name' => 'KopiKita', 'brand_logo' => $logo])
            ->assertRedirect();

        $this->tenant->refresh();

        $this->assertNotNull($this->tenant->brand_logo_path, 'The branding logo was not stored.');
        Storage::disk('local')->assertExists($this->tenant->brand_logo_path);

        $this->actingAsTenant()->get(route('branding.logo', $this->tenant))->assertOk();
    }

    // ------------------------------------------------------------- reversibility

    public function test_the_whole_surface_comes_back_when_the_flag_is_on(): void
    {
        // Proves the feature was disabled, not deleted: with the routes registered, every
        // endpoint works again and the original safety checks still hold.
        $this->enableFileManager();

        foreach (['files.index', 'files.store', 'files.download', 'files.destroy'] as $name) {
            $this->assertTrue(Route::has($name), "{$name} should exist once the feature is enabled.");
        }

        $this->actingAsTenant()->get('/files')->assertOk();

        $upload = UploadedFile::fake()->create('report.pdf', 50, 'application/pdf');
        $this->actingAsTenant()->post('/files', ['file' => $upload])->assertRedirect();

        $file = FileEntry::first();
        $this->assertNotNull($file);
        $this->assertSame($this->tenant->id, $file->tenant_id);
        $this->assertStringStartsWith("tenants/{$this->tenant->id}/", $file->path);

        $this->actingAsTenant()->get("/files/{$file->id}/download")->assertOk();

        $this->actingAsTenant()->delete("/files/{$file->id}")->assertRedirect();
        $this->assertSame(0, FileEntry::count());
        Storage::disk('local')->assertMissing($file->path);
    }

    public function test_the_upload_safety_checks_survive_re_enabling(): void
    {
        $this->enableFileManager();

        // MIME is verified from content, never from the filename.
        $this->actingAsTenant()->get('/files');
        $this->actingAsTenant()
            ->post('/files', ['file' => UploadedFile::fake()->create('evil.exe', 10, 'application/x-msdownload')])
            ->assertSessionHasErrors('file');
        $this->assertSame(0, FileEntry::count());

        // Cross-tenant reads still resolve to nothing.
        $this->actingAsTenant()->post('/files', ['file' => UploadedFile::fake()->create('mine.txt', 5, 'text/plain')]);
        $file = FileEntry::first();

        $attacker = User::create([
            'name' => 'Attacker', 'email' => 'fileattacker@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);
        $attackerTenant = Tenant::create(['name' => 'AT', 'slug' => 'at', 'owner_id' => $attacker->id]);
        $attackerTenant->users()->attach($attacker->id, ['role' => 'Owner', 'status' => 'active', 'joined_at' => now()]);

        $this->actingAsTenant($attacker, $attackerTenant)
            ->get("/files/{$file->id}/download")
            ->assertNotFound();
    }
}
