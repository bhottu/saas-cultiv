<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * /customers — the export dropdown and its two formats.
 *
 * Covers the dropdown rendering, both file formats, and the two things that would make
 * the feature harmful: leaking another workspace's customers, and exporting only the
 * page the user happens to be looking at.
 */
class CustomerExportDropdownTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Tenant $workspace;

    private Tenant $otherWorkspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create([
            'name' => 'Owner', 'email' => 'export-dd@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);

        $this->workspace = $this->makeWorkspace('Toko Export');
        $this->otherWorkspace = $this->makeWorkspace('Toko Lain');
    }

    private function makeWorkspace(string $name): Tenant
    {
        $tenant = Tenant::create([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name),
            'owner_id' => $this->owner->id, 'status' => 'active',
        ]);

        $tenant->users()->attach($this->owner->id, [
            'role' => 'Owner', 'status' => 'active', 'joined_at' => now(),
        ]);

        return $tenant;
    }

    private function customer(Tenant $tenant, array $attributes = []): Customer
    {
        return Customer::create(array_merge([
            'tenant_id' => $tenant->id, 'name' => 'Budi', 'phone' => '081234567890',
            'is_active' => true,
        ], $attributes));
    }

    private function inWorkspace()
    {
        return $this->actingAs($this->owner)->withSession(['tenant_id' => $this->workspace->id]);
    }

    private function body(TestResponse $response): string
    {
        $response->assertOk();

        return $response->streamedContent();
    }

    /** The CSV body with the UTF-8 BOM Excel needs stripped off. */
    private function csv(TestResponse $response): string
    {
        return preg_replace('/^\xEF\xBB\xBF/', '', $this->body($response));
    }

    // -------------------------------------------------------- the dropdown control

    public function test_the_export_control_is_a_dropdown_with_both_formats(): void
    {
        $this->customer($this->workspace);

        $html = $this->inWorkspace()->get('/customers')
            ->assertOk()
            ->assertSee(__('Export Contacts'))
            ->getContent();

        // The shared dropdown component, reused rather than a bespoke menu.
        $this->assertStringContainsString('x-data="{ open: false }"', $html);

        // Click-outside closing is part of that component, and is what keeps the menu
        // usable on a phone.
        $this->assertStringContainsString('@click.outside="open = false"', $html);

        $this->assertStringContainsString(__('Export as VCF'), $html);
        $this->assertStringContainsString(__('Export as CSV'), $html);
    }

    public function test_both_dropdown_links_point_at_the_export_route(): void
    {
        $html = $this->inWorkspace()->get('/customers')->assertOk()->getContent();

        $this->assertStringContainsString(route('customers.export', ['format' => 'vcf']), $html);
        $this->assertStringContainsString(route('customers.export', ['format' => 'csv']), $html);
    }

    public function test_the_dropdown_carries_the_active_filter_into_both_links(): void
    {
        $this->customer($this->workspace);

        $html = $this->inWorkspace()->get('/customers?search=Budi')->assertOk()->getContent();

        // The dropdown is rebuilt from $exportFilters, so the on-screen filter travels
        // with it and the file cannot disagree with the list.
        $this->assertStringContainsString('search=Budi', $html);
        $this->assertStringContainsString('format=vcf', $html);
        $this->assertStringContainsString('format=csv', $html);
    }

    // ------------------------------------------------------------------ the CSV

    public function test_the_csv_option_downloads_a_csv(): void
    {
        $this->customer($this->workspace, ['name' => 'Budi Santoso']);

        $response = $this->inWorkspace()->get(route('customers.export', ['format' => 'csv']));

        $response->assertOk();
        $response->assertDownload('customers.csv');
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    public function test_the_csv_has_a_header_row_and_one_row_per_customer(): void
    {
        $this->customer($this->workspace, ['name' => 'Budi Santoso', 'email' => 'budi@example.com']);
        $this->customer($this->workspace, ['name' => 'Siti Aminah']);

        $rows = array_values(array_filter(explode("\n", trim(
            $this->csv($this->inWorkspace()->get(route('customers.export', ['format' => 'csv'])))
        ))));

        $this->assertCount(3, $rows, 'One header row plus two customers.');
        $this->assertStringContainsString('Name', $rows[0]);
        $this->assertStringContainsString('Email', $rows[0]);
        $this->assertStringContainsString('Budi Santoso', implode("\n", $rows));
    }

    public function test_the_csv_quotes_values_containing_commas_quotes_and_newlines(): void
    {
        $this->customer($this->workspace, [
            'name' => 'PT "Kaya Raya", Jakarta',
            'notes' => "Line one\nLine two",
        ]);

        $body = $this->csv($this->inWorkspace()->get(route('customers.export', ['format' => 'csv'])));

        // fputcsv must quote the field rather than letting the comma split the column.
        $this->assertStringContainsString('"PT ""Kaya Raya"", Jakarta"', $body);
        $this->assertStringContainsString('"Line one', $body);
    }

    public function test_the_csv_parses_back_into_the_original_number_of_columns(): void
    {
        $this->customer($this->workspace, [
            'name' => 'PT "Kaya Raya", Jakarta',
            'phone' => '0812', 'email' => 'kaya@example.com',
        ]);

        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $this->csv($this->inWorkspace()->get(route('customers.export', ['format' => 'csv']))));
        rewind($handle);

        $header = fgetcsv($handle);
        $row = fgetcsv($handle);
        fclose($handle);

        $this->assertCount(5, $header);
        $this->assertCount(count($header), $row, 'The data row must have one cell per column.');
        $this->assertSame('PT "Kaya Raya", Jakarta', $row[0]);
    }

    public function test_the_csv_never_contains_null_or_undefined(): void
    {
        $this->customer($this->workspace, [
            'name' => 'Tanpa Kontak', 'phone' => null, 'email' => null,
            'address' => null, 'notes' => null,
        ]);

        $body = $this->csv($this->inWorkspace()->get(route('customers.export', ['format' => 'csv'])));

        $this->assertStringNotContainsString('null', $body);
        $this->assertStringNotContainsString('undefined', $body);
    }

    public function test_a_customer_with_no_phone_or_email_is_still_in_the_csv(): void
    {
        $this->customer($this->workspace, ['name' => 'Tanpa Telepon', 'phone' => null, 'email' => null]);

        $body = $this->csv($this->inWorkspace()->get(route('customers.export', ['format' => 'csv'])));

        $this->assertStringContainsString('Tanpa Telepon', $body);
    }

    // ----------------------------------------------------------------- the VCF

    public function test_the_vcf_option_remains_the_default_and_valid(): void
    {
        $this->customer($this->workspace, ['name' => 'Budi Santoso']);

        $body = $this->body($this->inWorkspace()->get(route('customers.export', ['format' => 'vcf'])));

        $this->assertStringContainsString('BEGIN:VCARD', $body);
        $this->assertStringContainsString('VERSION:3.0', $body);
        $this->assertStringContainsString('FN:Budi Santoso', $body);
        $this->assertStringContainsString('END:VCARD', $body);
    }

    public function test_an_unknown_format_falls_back_to_the_vcard(): void
    {
        $this->customer($this->workspace, ['name' => 'Budi']);

        $body = $this->body($this->inWorkspace()->get(route('customers.export', ['format' => 'xlsx'])));

        // A hand-edited URL still downloads something an importer can read.
        $this->assertStringContainsString('BEGIN:VCARD', $body);
    }

    // -------------------------------------------- scope, filters and isolation

    public function test_both_formats_cover_the_whole_dataset_not_just_the_page(): void
    {
        // 60 customers: more than the page size of 50.
        foreach (range(1, 60) as $i) {
            $this->customer($this->workspace, ['name' => 'Pelanggan '.$i]);
        }

        $this->assertSame(60, $this->inWorkspace()->get('/customers')
            ->assertOk()->viewData('customers')->total());

        $vcf = $this->body($this->inWorkspace()->get(route('customers.export', ['format' => 'vcf'])));

        $this->assertSame(60, substr_count($vcf, 'BEGIN:VCARD'));
        $this->assertStringContainsString('Pelanggan 60', $vcf);
        $this->assertStringContainsString(
            'Pelanggan 60',
            $this->csv($this->inWorkspace()->get(route('customers.export', ['format' => 'csv'])))
        );
    }

    public function test_both_formats_honour_the_active_search_filter(): void
    {
        $this->customer($this->workspace, ['name' => 'Budi Santoso']);
        $this->customer($this->workspace, ['name' => 'Siti Aminah']);

        $vcf = $this->body($this->inWorkspace()->get(
            route('customers.export', ['format' => 'vcf', 'search' => 'Siti'])
        ));
        $csv = $this->csv($this->inWorkspace()->get(
            route('customers.export', ['format' => 'csv', 'search' => 'Siti'])
        ));

        foreach (['vcf' => $vcf, 'csv' => $csv] as $format => $body) {
            $this->assertStringContainsString('Siti Aminah', $body);
            $this->assertStringNotContainsString('Budi Santoso', $body, "The {$format} export ignored the search.");
        }
    }

    public function test_neither_format_reaches_into_another_workspace(): void
    {
        $this->customer($this->workspace, ['name' => 'Milik Kita']);
        $this->customer($this->otherWorkspace, ['name' => 'Rahasia Workspace Lain']);

        foreach (['vcf', 'csv'] as $format) {
            $body = $this->body($this->inWorkspace()->get(route('customers.export', ['format' => $format])));

            $this->assertStringContainsString('Milik Kita', $body);
            $this->assertStringNotContainsString(
                'Rahasia Workspace Lain',
                $body,
                "The {$format} export leaked another tenant's customer."
            );
        }
    }

    public function test_neither_format_exposes_internal_columns(): void
    {
        $this->customer($this->workspace, ['name' => 'Budi']);

        foreach (['vcf', 'csv'] as $format) {
            $body = $this->body($this->inWorkspace()->get(route('customers.export', ['format' => $format])));

            foreach (['tenant_id', 'credit_limit', 'deleted_at', 'is_active', 'password'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $body, "{$format} leaked {$forbidden}.");
            }
        }
    }

    public function test_an_empty_workspace_gets_a_notification_for_both_formats(): void
    {
        foreach (['vcf', 'csv'] as $format) {
            $response = $this->inWorkspace()->get(route('customers.export', ['format' => $format]));

            $response->assertRedirect(route('customers.index'));
            $response->assertSessionHas('status.message', __('No customers to export.'));

            // Not a 0-byte download that would fail silently in the importer.
            $this->assertFalse($response->headers->has('Content-Disposition'));
        }
    }

    public function test_an_anonymous_visitor_cannot_export_either_format(): void
    {
        $this->customer($this->workspace);

        foreach (['vcf', 'csv'] as $format) {
            $this->get(route('customers.export', ['format' => $format]))
                ->assertRedirect(route('login'));
        }
    }

    public function test_the_export_is_audited(): void
    {
        $this->customer($this->workspace);

        $this->inWorkspace()->get(route('customers.export', ['format' => 'csv']))->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.exported']);
    }
}