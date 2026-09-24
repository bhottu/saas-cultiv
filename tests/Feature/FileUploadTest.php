<?php

namespace Tests\Feature;

use App\Models\FileEntry;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

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

    public function test_owner_can_upload_and_download_file(): void
    {
        $upload = UploadedFile::fake()->create('report.pdf', 50, 'application/pdf');

        $this->actingAsTenant()->post('/files', ['file' => $upload])->assertRedirect();

        $file = FileEntry::first();
        $this->assertNotNull($file);
        $this->assertSame('application/pdf', $file->mime_type);
        $this->assertSame($this->tenant->id, $file->tenant_id);
        $this->assertStringStartsWith("tenants/{$this->tenant->id}/", $file->path);
        Storage::disk('local')->assertExists($file->path);

        $this->actingAsTenant()->get("/files/{$file->id}/download")->assertOk();
    }

    public function test_disallowed_mime_is_rejected(): void
    {
        $upload = UploadedFile::fake()->create('evil.exe', 10, 'application/x-msdownload');
        $detectedMime = $upload->getMimeType();

        // Simulate the real user flow: land on /files, then upload.
        // This sets the session's intended URL so back() returns to /files and
        // the error renders in-page (not Laravel's exception page).
        $this->actingAsTenant()->get('/files');

        $response = $this->actingAsTenant()->post('/files', ['file' => $upload]);

        $response->assertRedirect('/files');
        $response->assertSessionHasErrors('file');

        // Read the session store directly (RedirectResponse has no public session()).
        $session = $this->app['session.store'];
        $viewBag = $session->get('errors');
        $fileErrors = $viewBag?->get('file', []);
        $this->assertNotEmpty($fileErrors);
        $this->assertStringContainsString("File type not allowed ({$detectedMime}).", $fileErrors[0]);

        $this->assertSame(0, FileEntry::count());
    }

    public function test_oversized_file_is_rejected(): void
    {
        $upload = UploadedFile::fake()->create('big.pdf', 20000, 'application/pdf'); // 20 MB > 10 MB cap

        $this->actingAsTenant()->post('/files', ['file' => $upload])->assertStatus(302);
        $this->assertSame(0, FileEntry::count());
    }

    public function test_cross_tenant_download_is_denied(): void
    {
        $upload = UploadedFile::fake()->create('mine.txt', 5, 'text/plain');
        $this->actingAsTenant()->post('/files', ['file' => $upload])->assertRedirect();
        $file = FileEntry::first();

        // Attacker: owner of a DIFFERENT tenant.
        $attacker = User::create([
            'name' => 'Attacker', 'email' => 'attacker@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);
        $attackerTenant = Tenant::create(['name' => 'AT', 'slug' => 'at', 'owner_id' => $attacker->id]);
        $attackerTenant->users()->attach($attacker->id, ['role' => 'Owner', 'status' => 'active', 'joined_at' => now()]);

        // Global tenant scope → foreign ID resolves to 404, never the file.
        $this->actingAsTenant($attacker, $attackerTenant)
            ->get("/files/{$file->id}/download")
            ->assertNotFound();
    }

    public function test_storage_quota_enforced(): void
    {
        // Give the tenant's free plan a zero storage quota via the active subscription.
        Plan::where('is_free_tier', true)->update(['entitlements' => ['max_users' => 2, 'storage_mb' => 0]]);
        app(\App\Services\SubscriptionService::class)->switchToFree($this->tenant);

        $upload = UploadedFile::fake()->create('nope.txt', 5, 'text/plain');

        // 0 MB quota → any upload exceeds the limit → 429.
        $this->actingAsTenant()->post('/files', ['file' => $upload])->assertStatus(429);
        $this->assertSame(0, FileEntry::count());
    }

    public function test_delete_removes_file_and_blob(): void
    {
        $upload = UploadedFile::fake()->create('gone.txt', 5, 'text/plain');
        $this->actingAsTenant()->post('/files', ['file' => $upload])->assertRedirect();
        $file = FileEntry::first();
        $path = $file->path;

        $this->actingAsTenant()->delete("/files/{$file->id}")->assertRedirect();

        $this->assertSame(0, FileEntry::count());
        Storage::disk('local')->assertMissing($path);
    }
}
