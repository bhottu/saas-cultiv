<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\VCardExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * /customers — vCard contact export.
 *
 * The two things that would make this feature actively harmful are covered explicitly:
 * a file containing another workspace's customers, and a file full of `null` / empty
 * properties that a phone imports as a sheet of blank entries.
 */
class CustomerContactExportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Tenant $workspace;

    private Tenant $otherWorkspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create([
            'name' => 'Owner', 'email' => 'export-owner@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);

        $this->workspace = $this->makeWorkspace('Toko Ekspor', $this->owner);
        $this->otherWorkspace = $this->makeWorkspace('Toko Lain', $this->owner);
    }

    private function makeWorkspace(string $name, User $owner): Tenant
    {
        $tenant = Tenant::create([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name),
            'owner_id' => $owner->id, 'status' => 'active',
        ]);

        $tenant->users()->attach($owner->id, [
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

    // ------------------------------------------------------- download & file shape

    public function test_the_export_downloads_a_vcf_file_with_the_right_headers(): void
    {
        $this->customer($this->workspace);

        $response = $this->inWorkspace()->get(route('customers.export'));

        $response->assertOk();
        $response->assertDownload('customers.vcf');

        // text/vcard is what makes iOS and Android open the Contacts app rather than
        // previewing the file as plain text.
        $this->assertStringContainsString('text/vcard', $response->headers->get('Content-Type'));
    }

    public function test_the_index_offers_the_export_button(): void
    {
        $this->inWorkspace()->get('/customers')
            ->assertOk()
            ->assertSee(route('customers.export'), false)
            ->assertSee(__('Export Contacts'));
    }

    public function test_multiple_customers_land_in_one_file_as_separate_vcards(): void
    {
        $this->customer($this->workspace, ['name' => 'Budi Santoso']);
        $this->customer($this->workspace, ['name' => 'Siti Aminah']);
        $this->customer($this->workspace, ['name' => 'Andi Wijaya']);

        $body = $this->body($this->inWorkspace()->get(route('customers.export')));

        $this->assertSame(3, substr_count($body, 'BEGIN:VCARD'));
        $this->assertSame(3, substr_count($body, 'END:VCARD'));

        foreach (['Budi Santoso', 'Siti Aminah', 'Andi Wijaya'] as $name) {
            $this->assertStringContainsString("FN:{$name}", $body);
        }
    }

    public function test_a_single_card_is_a_well_formed_vcard(): void
    {
        $this->customer($this->workspace, [
            'name' => 'Budi Santoso',
            'phone' => '081234567890',
            'email' => 'budi@example.com',
            'address' => 'Jl. Merdeka No. 1, Jakarta',
            'notes' => 'Prefers WhatsApp',
        ]);

        $body = $this->body($this->inWorkspace()->get(route('customers.export')));

        $this->assertStringContainsString('BEGIN:VCARD', $body);
        $this->assertStringContainsString('VERSION:3.0', $body);
        $this->assertStringContainsString('FN:Budi Santoso', $body);
        // N is the structured form: family;given;;;
        $this->assertStringContainsString('N:Santoso;Budi;;;', $body);
        $this->assertStringContainsString('TEL;TYPE=CELL:081234567890', $body);
        $this->assertStringContainsString('EMAIL;TYPE=INTERNET:budi@example.com', $body);
        // A comma inside an address is escaped, so the literal value is not byte-identical.
        $this->assertStringContainsString('ADR;TYPE=HOME:;;Jl. Merdeka No. 1\\, Jakarta;;;;', $body);
        $this->assertStringContainsString('NOTE:Prefers WhatsApp', $body);
        $this->assertStringEndsWith("END:VCARD\r\n", $body);
    }

    public function test_the_file_uses_crlf_line_endings(): void
    {
        $this->customer($this->workspace, ['name' => 'Budi']);

        $body = $this->body($this->inWorkspace()->get(route('customers.export')));

        // RFC 6350 requires CRLF; a Unix-newline .vcf imports inconsistently on iOS.
        $this->assertStringContainsString("BEGIN:VCARD\r\n", $body);
        $this->assertDoesNotMatchRegularExpression('/(?<!\r)\n/', $body);
    }

    // -------------------------------------------------------------- tenancy

    public function test_the_export_never_contains_another_workspace_customers(): void
    {
        $this->customer($this->workspace, ['name' => 'Budi Milik Kita']);
        $this->customer($this->otherWorkspace, ['name' => 'Rahasia Workspace Lain']);

        $body = $this->body($this->inWorkspace()->get(route('customers.export')));

        $this->assertStringContainsString('Budi Milik Kita', $body);
        $this->assertStringNotContainsString(
            'Rahasia Workspace Lain',
            $body,
            'The export leaked a customer from another tenant.'
        );
    }

    public function test_a_workspace_with_no_customers_gets_a_notification_not_an_empty_file(): void
    {
        $response = $this->inWorkspace()->get(route('customers.export'));

        $response->assertRedirect(route('customers.index'));
        $response->assertSessionHas('status.message', __('No customers to export.'));

        // Crucially, not a 0-byte download that would fail silently at the importer.
        $this->assertFalse($response->headers->has('Content-Disposition'));
    }

    public function test_an_anonymous_visitor_cannot_export(): void
    {
        $this->customer($this->workspace);

        $this->get(route('customers.export'))->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------ scope & filters

    public function test_the_export_covers_customers_beyond_the_visible_page(): void
    {
        // More rows than the page size of 50: exporting "what is on screen" would
        // silently drop the rest.
        $total = 60;
        for ($i = 1; $i <= $total; $i++) {
            $this->customer($this->workspace, ['name' => 'Pelanggan '.$i]);
        }

        $this->assertSame($total, $this->inWorkspace()->get('/customers')
            ->assertOk()->viewData('customers')->total());

        $body = $this->body($this->inWorkspace()->get(route('customers.export')));

        $this->assertSame($total, substr_count($body, 'BEGIN:VCARD'));
        $this->assertStringContainsString('FN:Pelanggan 60', $body);
    }

    public function test_the_export_honours_the_active_search_filter(): void
    {
        $this->customer($this->workspace, ['name' => 'Budi Santoso']);
        $this->customer($this->workspace, ['name' => 'Siti Aminah']);

        $body = $this->body(
            $this->inWorkspace()->get(route('customers.export', ['search' => 'Siti']))
        );

        $this->assertStringContainsString('Siti Aminah', $body);
        $this->assertStringNotContainsString('Budi Santoso', $body);
        $this->assertSame(1, substr_count($body, 'BEGIN:VCARD'));
    }

    public function test_the_export_honours_the_active_only_filter(): void
    {
        $this->customer($this->workspace, ['name' => 'Aktif Sekali', 'is_active' => true]);
        $this->customer($this->workspace, ['name' => 'Nonaktif Sekali', 'is_active' => false]);

        $body = $this->body(
            $this->inWorkspace()->get(route('customers.export', ['active_only' => 1]))
        );

        $this->assertStringContainsString('Aktif Sekali', $body);
        $this->assertStringNotContainsString('Nonaktif Sekali', $body);
    }

    // ------------------------------------------------------ content correctness

    public function test_the_file_never_contains_null_or_undefined(): void
    {
        // A sparse but valid row: the columns that are nullable are genuinely empty.
        $this->customer($this->workspace, [
            'name' => 'Budi', 'phone' => null, 'notes' => null, 'address' => null,
        ]);
        $this->customer($this->workspace, ['name' => 'Siti', 'email' => 'siti@example.com']);

        $body = $this->body($this->inWorkspace()->get(route('customers.export')));

        foreach (['null', 'undefined', 'NULL'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
    }

    public function test_missing_properties_are_omitted_rather_than_written_empty(): void
    {
        // Name and phone only: no email, no address, no notes.
        $this->customer($this->workspace, [
            'name' => 'Budi', 'phone' => '0812',
            'email' => null, 'address' => null, 'notes' => null,
        ]);

        $body = $this->body($this->inWorkspace()->get(route('customers.export')));

        $this->assertStringContainsString('TEL;TYPE=CELL:0812', $body);

        // An importer that meets an empty EMAIL or ADR creates a blank row; the
        // property is simply not written instead.
        $this->assertStringNotContainsString('EMAIL', $body);
        $this->assertStringNotContainsString('ADR', $body);
        $this->assertStringNotContainsString('NOTE', $body);
    }

    public function test_a_customer_with_only_an_email_is_still_exported(): void
    {
        $this->customer($this->workspace, [
            'name' => 'Tanpa Telepon', 'phone' => null, 'email' => 'kontak@example.com',
        ]);

        $body = $this->body($this->inWorkspace()->get(route('customers.export')));

        $this->assertSame(1, substr_count($body, 'BEGIN:VCARD'));
        $this->assertStringContainsString('EMAIL;TYPE=INTERNET:kontak@example.com', $body);
        // No phone recorded, so TEL is simply absent — still a valid contact.
        $this->assertStringNotContainsString('TEL', $body);
    }

    public function test_the_exporter_falls_back_to_the_phone_when_a_row_has_no_name(): void
    {
        // customers.name is NOT NULL in the schema, so this state is unreachable
        // through the application today. The exporter still handles it, because a
        // future import or an API write could produce it, and a nameless card is far
        // better than a card whose FN interpolates a null.
        $body = (new VCardExporter())->render([
            new Customer(['phone' => '08120000']),
            new Customer(['email' => 'anon@example.com']),
        ]);

        $this->assertStringContainsString('FN:08120000', $body);
        $this->assertStringContainsString('FN:anon@example.com', $body);
    }

    public function test_a_row_with_no_contact_details_at_all_is_skipped(): void
    {
        $body = (new VCardExporter())->render([
            new Customer(['name' => null, 'phone' => null, 'email' => null]),
            new Customer(['name' => 'Budi Bertemu']),
        ]);

        // A blank entry in somebody's address book is worse than a missing one.
        $this->assertSame(1, substr_count($body, 'BEGIN:VCARD'));
        $this->assertStringContainsString('FN:Budi Bertemu', $body);
    }

    public function test_special_characters_are_escaped_so_the_file_stays_parseable(): void
    {
        $this->customer($this->workspace, [
            'name' => 'Budi; Santoso, Jr.',
            'notes' => "Line one\nLine two",
        ]);

        $body = $this->body($this->inWorkspace()->get(route('customers.export')));

        // A raw semicolon or comma would inject an extra component; a raw newline would
        // split the property across lines and corrupt the whole file.
        $this->assertStringContainsString('FN:Budi\\; Santoso\\, Jr.', $body);
        $this->assertStringContainsString('NOTE:Line one\\nLine two', $body);
    }

    public function test_the_export_contains_no_internal_fields(): void
    {
        $this->customer($this->workspace, ['name' => 'Budi', 'notes' => 'Catatan']);

        $body = $this->body($this->inWorkspace()->get(route('customers.export')));

        // Nothing about the row's tenancy, money or bookkeeping may leak into a file
        // that is about to be shared off-platform. (A bare numeric id is not asserted
        // against: any small integer can appear by coincidence in a phone number.)
        foreach (['tenant_id', 'credit_limit', 'deleted_at', 'is_active', 'CUSTOMER'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }

        // Only the recognised contact properties are written — no internal column ever
        // reaches a file that is about to be shared off-platform.
        $this->assertSame(['VERSION', 'N', 'FN', 'TEL', 'NOTE'], $this->propertiesIn($body));
    }

    /**
     * The bare property names present in the first card, in order.
     *
     * "TEL;TYPE=CELL" and "EMAIL;TYPE=INTERNET" collapse to TEL and EMAIL, so this sees
     * the properties themselves rather than the parameters attached to them.
     *
     * @return array<int, string>
     */
    private function propertiesIn(string $body): array
    {
        $first = explode('END:VCARD', $body)[0];

        $properties = [];
        foreach (explode("\r\n", $first) as $line) {
            if ($line === '' || $line === 'BEGIN:VCARD') {
                continue;
            }

            $name = explode(':', $line, 2)[0];
            $properties[] = explode(';', $name)[0];
        }

        return array_values(array_unique($properties));
    }

    public function test_the_exporter_skips_soft_deleted_customers(): void
    {
        $gone = $this->customer($this->workspace, ['name' => 'Budi Dihapus']);
        $gone->delete();

        $this->customer($this->workspace, ['name' => 'Budi Tetap']);

        $body = $this->body($this->inWorkspace()->get(route('customers.export')));

        $this->assertStringNotContainsString('Budi Dihapus', $body);
        $this->assertStringContainsString('Budi Tetap', $body);
    }

    // ------------------------------------------------------------- exporter unit

    public function test_the_exporter_reports_whether_a_customer_is_exportable(): void
    {
        $exporter = new VCardExporter();

        $this->assertTrue($exporter->isExportable(new Customer(['name' => 'Budi'])));
        $this->assertTrue($exporter->isExportable(new Customer(['phone' => '0812'])));
        $this->assertTrue($exporter->isExportable(new Customer(['email' => 'a@b.com'])));

        $this->assertFalse($exporter->isExportable(new Customer([
            'name' => null, 'phone' => null, 'email' => null,
        ])));
    }

    public function test_an_empty_collection_renders_an_empty_string_not_a_broken_card(): void
    {
        $this->assertSame('', (new VCardExporter())->render([]));
    }
}