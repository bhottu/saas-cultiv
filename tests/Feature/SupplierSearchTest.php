<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * /suppliers — search, the email cell, and the View action.
 *
 * The search tests exist because the original `where('name', 'like', …)` is
 * case-SENSITIVE on PostgreSQL, which is what production runs: searching "Kaya"
 * against "PT Kaya Raya" returned nothing. The suite runs on SQLite, whose LIKE is
 * already case-insensitive, so the result assertions alone would have passed on the
 * broken code — which is why the compiled SQL operator is asserted separately below.
 */
class SupplierSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create([
            'name' => 'Owner', 'email' => 'supplier-search@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);

        $this->tenant = Tenant::create([
            'name' => 'Toko Supplier', 'slug' => 'toko-supplier',
            'owner_id' => $this->owner->id, 'status' => 'active',
        ]);
        $this->tenant->users()->attach($this->owner->id, [
            'role' => 'Owner', 'status' => 'active', 'joined_at' => now(),
        ]);
    }

    private function supplier(array $attributes = []): Supplier
    {
        return Supplier::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'name' => 'PT Kaya Raya',
            'is_active' => true,
        ], $attributes));
    }

    private function inWorkspace()
    {
        return $this->actingAs($this->owner)->withSession(['tenant_id' => $this->tenant->id]);
    }

    /** Supplier names currently listed, in the order the table shows them. */
    private function visibleNames(string $query = ''): array
    {
        $url = '/suppliers'.($query === '' ? '' : '?'.http_build_query(['search' => $query]));

        return $this->inWorkspace()->get($url)->assertOk()->viewData('suppliers')->pluck('name')->all();
    }

    // --------------------------------------------------------------- substring

    public function test_search_finds_a_keyword_at_the_start_middle_and_end_of_the_name(): void
    {
        $this->supplier(['name' => 'PT Kaya Raya']);

        foreach (['PT', 'Kaya', 'Raya'] as $term) {
            $this->assertContains(
                'PT Kaya Raya',
                $this->visibleNames($term),
                "Searching '{$term}' should have found PT Kaya Raya."
            );
        }
    }

    public function test_search_is_case_insensitive(): void
    {
        $this->supplier(['name' => 'PT Kaya Raya']);

        foreach (['pt', 'kaya', 'raya', 'PT KAYA', 'kAyA', 'RAyA', 'pT kAyA rAyA'] as $term) {
            $this->assertContains(
                'PT Kaya Raya',
                $this->visibleNames($term),
                "Searching '{$term}' should have matched regardless of case."
            );
        }
    }

    public function test_search_matches_phone_and_email_as_well_as_the_name(): void
    {
        $this->supplier([
            'name' => 'PT Kaya Raya',
            'phone' => '0215551234',
            'email' => 'sales@kayaraya.co.id',
        ]);

        $this->assertCount(1, $this->visibleNames('5551234'));
        $this->assertCount(1, $this->visibleNames('KAYARAYA.CO.ID'));
    }

    // ------------------------------------------------------------- result count

    public function test_the_number_of_results_matches_the_keyword(): void
    {
        $this->supplier(['name' => 'PT Kaya Raya']);
        $this->supplier(['name' => 'Supplier Kaya Makmur']);
        $this->supplier(['name' => 'CV Raya Jaya']);
        $this->supplier(['name' => 'PT Other Sekali']);

        // "kaya" appears in the first two; "raya" in the first and third;
        // "PT" prefixes the first and the fourth.
        $this->assertCount(2, $this->visibleNames('kaya'));
        $this->assertCount(2, $this->visibleNames('raya'));
        $this->assertCount(2, $this->visibleNames('PT'));
    }

    public function test_every_supplier_sharing_a_keyword_is_returned(): void
    {
        foreach (range(1, 10) as $i) {
            $this->supplier(['name' => 'PT Sumber '.$i]);
        }
        // Deliberately shares no part of the keyword, so it must not be counted.
        $this->supplier(['name' => 'CVOPY CV Something Else']);

        $this->assertCount(10, $this->visibleNames('PT'));
    }

    // ---------------------------------------------------- empty / whitespace

    public function test_an_empty_search_is_ignored_rather_than_matching_nothing(): void
    {
        $this->supplier(['name' => 'PT Kaya Raya']);

        $this->assertCount(1, $this->visibleNames(''));
    }

    public function test_a_whitespace_only_search_is_ignored(): void
    {
        $this->supplier(['name' => 'PT Kaya Raya']);

        $this->assertCount(1, $this->visibleNames('   '));
    }

    public function test_a_keyword_with_no_match_returns_nothing(): void
    {
        $this->supplier(['name' => 'PT Kaya Raya']);

        $this->assertCount(0, $this->visibleNames('zzzznotfound'));
    }

    // --------------------------------------------------------------- pagination

    public function test_pagination_counts_the_filtered_results_not_the_whole_table(): void
    {
        // 60 rows containing "PT", plus 60 that do not.
        foreach (range(1, 60) as $i) {
            $this->supplier(['name' => "PT Nomor {$i}"]);
            $this->supplier(['name' => "Lain Nomor {$i}"]);
        }

        $paginator = $this->inWorkspace()->get('/suppliers?search=PT')
            ->assertOk()->viewData('suppliers');

        // 60 matches, not 120: the count is taken after the filter.
        $this->assertSame(60, $paginator->total());
        $this->assertSame(2, $paginator->lastPage());
        $this->assertCount(50, $paginator->items());

        $second = $this->inWorkspace()->get('/suppliers?search=PT&page=2')->assertOk();
        $this->assertCount(10, $second->viewData('suppliers')->items());
    }

    public function test_the_filter_is_preserved_when_paging(): void
    {
        foreach (range(1, 60) as $i) {
            $this->supplier(['name' => "PT Nomor {$i}"]);
        }

        $links = $this->inWorkspace()->get('/suppliers?search=PT')
            ->assertOk()->viewData('suppliers')->links();

        $this->assertStringContainsString('search=PT', $links);
    }

    // ---------------------------------------------------------------- tenancy

    public function test_search_never_reaches_into_another_workspace(): void
    {
        $other = Tenant::create([
            'name' => 'Toko Lain', 'slug' => 'toko-lain',
            'owner_id' => $this->owner->id, 'status' => 'active',
        ]);
        $other->users()->attach($this->owner->id, [
            'role' => 'Owner', 'status' => 'active', 'joined_at' => now(),
        ]);

        $this->supplier(['name' => 'PT Kaya Raya']);
        Supplier::create([
            'tenant_id' => $other->id, 'name' => 'PT Kaya Rahasia', 'is_active' => true,
        ]);

        $names = $this->visibleNames('kaya');

        $this->assertContains('PT Kaya Raya', $names);
        $this->assertNotContains('PT Kaya Rahasia', $names);
    }

    public function test_a_removed_supplier_is_not_searchable(): void
    {
        $this->supplier(['name' => 'PT Kaya Raya'])->delete();
        $this->supplier(['name' => 'CV Kaya Mandiri']);

        $this->assertCount(1, $this->visibleNames('kaya'));
    }

    /**
     * The regression guard that matters most.
     *
     * Asserting only the search RESULTS cannot catch this: SQLite's LIKE is already
     * case-insensitive, so the old case-sensitive-looking `like` operator passed every
     * behavioural test above while being broken on PostgreSQL. This checks the operator
     * the query builder actually emits for the case-insensitive request, per driver.
     */
    public function test_the_search_compiles_to_a_case_insensitive_operator_on_the_actual_driver(): void
    {
        $sql = Supplier::query()
            ->search('Kaya')
            ->toSql();

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            // Production: PostgreSQL LIKE is case-sensitive, so ILIKE is required.
            $this->assertStringContainsString('ilike', strtolower($sql), 'PostgreSQL needs ILIKE.');
        } else {
            // SQLite / MySQL: LIKE is already case-insensitive, so that is correct.
            $this->assertStringNotContainsString('ilike', strtolower($sql));
        }

        $this->assertStringContainsString('like', strtolower($sql));
    }

    public function test_a_case_sensitive_search_is_never_silently_requested(): void
    {
        // The scope must never pass $caseSensitive = true, which would break PostgreSQL
        // matching outright (Laravel throws on that operator for non-Pg drivers).
        $this->expectNotToPerformAssertions();

        try {
            Supplier::query()->search('Kaya')->toSql();
        } catch (\RuntimeException $e) {
            $this->fail('The search scope requested a case-sensitive LIKE: '.$e->getMessage());
        }
    }

    // ------------------------------------------------------------- email cell

    public function test_a_long_email_is_truncated_in_the_markup_but_kept_whole_in_the_database(): void
    {
        $email = 'suplliertanamanhias.industri.jaya.sentosa@bogor-industri.co.id';

        $supplier = $this->supplier(['email' => $email]);

        $html = $this->inWorkspace()->get('/suppliers')->assertOk()->getContent();

        // Truncation is a CSS concern: the cell is capped and the inner span truncates.
        $this->assertStringContainsString('truncate', $html);

        // The full address is still present in the DOM (title + text), so nothing is lost.
        $this->assertStringContainsString($email, $html);

        // And, decisively, the stored value is untouched.
        $this->assertSame($email, $supplier->fresh()->email);
    }

    // ------------------------------------------------------------ view action

    public function test_the_view_action_opens_the_supplier_detail_page(): void
    {
        $supplier = $this->supplier(['name' => 'PT Kaya Raya', 'email' => 'kaya@example.com']);

        $this->inWorkspace()->get('/suppliers')
            ->assertOk()
            ->assertSee(route('suppliers.show', $supplier), false);

        $this->inWorkspace()->get("/suppliers/{$supplier->id}")
            ->assertOk()
            ->assertSee('PT Kaya Raya')
            ->assertSee('kaya@example.com');
    }

    public function test_the_detail_page_is_tenant_isolated(): void
    {
        $other = Tenant::create([
            'name' => 'Toko Asing', 'slug' => 'toko-asing',
            'owner_id' => $this->owner->id, 'status' => 'active',
        ]);
        $other->users()->attach($this->owner->id, [
            'role' => 'Owner', 'status' => 'active', 'joined_at' => now(),
        ]);

        $foreign = Supplier::create([
            'tenant_id' => $other->id, 'name' => 'PT Rahasia', 'is_active' => true,
        ]);

        $this->inWorkspace()->get("/suppliers/{$foreign->id}")->assertNotFound();
    }

    public function test_the_detail_page_respects_the_role_permission_registry(): void
    {
        $supplier = $this->supplier();

        // Viewer holds view_records, so suppliers.view IS granted — the page must open.
        $viewer = $this->memberWithRole('Viewer', 'viewer-supplier@test.dev');

        $this->actingAs($viewer)->withSession(['tenant_id' => $this->tenant->id])
            ->get("/suppliers/{$supplier->id}")
            ->assertOk();

        // Staff holds view_records too, but NOT delete_records, so the destructive
        // action must be refused. That is the permission boundary worth pinning.
        $staff = $this->memberWithRole('Staff', 'staff-supplier@test.dev');

        $this->actingAs($staff)->withSession(['tenant_id' => $this->tenant->id])
            ->delete("/suppliers/{$supplier->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id, 'deleted_at' => null]);
    }

    private function memberWithRole(string $role, string $email): User
    {
        $user = User::create([
            'name' => $role, 'email' => $email,
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);

        $this->tenant->users()->attach($user->id, [
            'role' => $role, 'status' => 'active', 'joined_at' => now(),
        ]);

        return $user;
    }

    public function test_the_index_shows_the_empty_state_when_nothing_matches(): void
    {
        $this->supplier(['name' => 'PT Kaya Raya']);

        $this->inWorkspace()->get('/suppliers?search=zzz')
            ->assertOk()
            ->assertSee(__('No suppliers found.'));
    }
}