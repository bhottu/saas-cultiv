<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Form consistency across the pages exposed in the navigation.
 *
 * Part of auditing the newly linked pages: a form that renders is not the same as a form
 * that behaves. Two things are pinned here.
 *
 *  - Every required field is actually validated server-side, and a rejected submit shows a
 *    visible reason instead of quietly returning the page unchanged.
 *  - The primary submit button is the SAME shared control everywhere. <x-primary-button>
 *    is the design system; the expense page is asserted against the sales create page so
 *    no page can drift into its own idea of what "Save" looks like.
 */
class FormConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create([
            'name' => 'Owner', 'email' => 'consistency@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);

        $this->tenant = Tenant::create([
            'name' => 'Toko Konsisten', 'slug' => 'toko-konsisten', 'owner_id' => $this->owner->id,
        ]);

        $this->tenant->users()->attach($this->owner->id, [
            'role' => 'Owner', 'status' => TenantUser::STATUS_ACTIVE, 'joined_at' => now(),
        ]);

        Warehouse::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Gudang Utama', 'is_active' => true,
        ]);

        Product::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Kopi', 'sku' => 'K-1', 'unit' => 'pcs',
            'purchase_price' => 10000, 'selling_price' => 15000, 'cost_price' => 10000,
            'track_inventory' => true, 'is_active' => true,
        ]);
    }

    private function member()
    {
        return $this->actingAs($this->owner)->withSession(['tenant_id' => $this->tenant->id]);
    }

    /** The class attribute of the page's primary submit button. */
    private function submitButtonClasses(string $url): ?string
    {
        $html = $this->member()->get($url)->assertOk()->getContent();

        // Every create/edit form here renders its action through <x-primary-button>,
        // which merges onto the button element.
        preg_match('/<button[^>]*class="([^"]*bg-gray-800[^"]*)"/', $html, $matches);

        return $matches[1] ?? null;
    }

    public function test_the_expense_submit_button_is_the_same_control_as_the_sales_one(): void
    {
        $sales = $this->submitButtonClasses('/sales/create');
        $expenses = $this->submitButtonClasses('/expenses/create');

        $this->assertNotNull($sales, 'The sales create page must render the shared primary button.');
        $this->assertNotNull($expenses, 'The expense create page must render the shared primary button.');

        // Identical class strings means identical height, padding, font-size, radius and
        // colour — the button is not merely similar, it is the same component output.
        $this->assertSame($sales, $expenses);

        // The hierarchy markers the design system relies on, spelled out so a future
        // restyle of the component has to be a deliberate decision.
        foreach (['px-4', 'py-2', 'rounded-md', 'font-semibold', 'text-xs', 'uppercase', 'tracking-widest'] as $token) {
            $this->assertStringContainsString($token, $expenses, "The submit button lost `{$token}`.");
        }
    }

    public function test_the_expense_submit_button_sits_beside_an_explicit_cancel(): void
    {
        // Matches the sales create and purchase form layout: the primary action in a flex
        // row with a way out that is not the browser's back button.
        $html = $this->member()->get('/expenses/create')->assertOk()->getContent();

        $this->assertStringContainsString('href="'.url('/expenses').'"', $html);
        $this->assertStringContainsString(__('Cancel'), $html);
    }

    public function test_an_empty_expense_form_explains_itself(): void
    {
        $this->member()
            ->from('/expenses/create')
            ->post('/expenses')
            ->assertSessionHasErrors(['description', 'amount', 'expense_date']);

        $html = $this->member()->get('/expenses/create')->assertOk()->getContent();

        $this->assertStringContainsString('Please complete the required fields.', $html);
        $this->assertStringContainsString('role="alert"', $html);

        $this->assertStringNotContainsString(__('Expense recorded.'), $html);
    }

    public function test_an_empty_supplier_form_explains_itself(): void
    {
        $this->member()
            ->from('/suppliers/create')
            ->post('/suppliers')
            ->assertSessionHasErrors('name');

        // The rejected save must show the reason on the page it lands on, not just put it
        // in the session.
        $message = (string) $this->app['session.store']->get('errors')?->first('name');

        $this->assertNotSame('', $message);

        $this->member()->get('/suppliers/create')
            ->assertOk()
            ->assertSee($message);
    }

    public function test_a_saved_expense_reports_success(): void
    {
        $this->member()
            ->from('/expenses/create')
            ->post('/expenses', [
                'description' => 'Listrik', 'amount' => '450000',
                'expense_date' => now()->toDateString(),
            ])
            ->assertRedirect('/expenses');

        $this->member()->get('/expenses')
            ->assertOk()
            ->assertSee(__('Expense recorded.'));
    }

    public function test_a_saved_supplier_appears_on_the_list(): void
    {
        $this->member()
            ->from('/suppliers/create')
            ->post('/suppliers', ['name' => 'PT Kopi Nusantara', 'is_active' => 1])
            ->assertRedirect('/suppliers');

        $this->assertSame(
            1,
            \App\Models\Supplier::withoutGlobalScopes()
                ->where('name', 'PT Kopi Nusantara')
                ->where('tenant_id', $this->tenant->id)
                ->count(),
            'The supplier must be written into the active workspace.'
        );

        $this->member()->get('/suppliers')
            ->assertOk()
            ->assertSee('PT Kopi Nusantara');
    }

    // ------------------------------------------------------- structural layout

    /**
     * The rendered DOM for $url, parsed.
     *
     * Structure is asserted on the real tree rather than on the source string: these
     * regressions all had markup that read plausibly while the BROWSER nested things
     * differently than intended.
     *
     * @return array{0: \DOMXPath, 1: \DOMElement[]}
     */
    private function dom(string $url): array
    {
        $html = $this->member()->get($url)->assertOk()->getContent();

        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        return [new \DOMXPath($document), iterator_to_array($document->getElementsByTagName('*'))];
    }

    /** Index of $node in the document-order element list. */
    private function position(array $order, \DOMNode $node): int
    {
        return (int) array_search($node, $order, true);
    }

    /**
     * True when $node is a block-level container element (DIV).
     *
     * DOMDocument::loadHTML lower-cases element names, so the comparison is normalised
     * rather than hard-coding 'DIV'.
     */
    private function isBlockContainer(\DOMNode $node): bool
    {
        return strtoupper((string) $node->nodeName) === 'DIV';
    }

    /** Class-bearing ancestor chain, outermost first. */
    private function ancestorClasses(\DOMNode $node): string
    {
        $classes = '';

        for ($p = $node->parentNode; $p instanceof \DOMElement; $p = $p->parentNode) {
            $classes .= ' '.$p->getAttribute('class').' ';
        }

        return $classes;
    }

    private function containsNode(\DOMNode $ancestor, \DOMNode $needle): bool
    {
        for ($n = $needle->parentNode; $n instanceof \DOMNode; $n = $n->parentNode) {
            if ($n === $ancestor) {
                return true;
            }
        }

        return false;
    }

    public function test_the_expense_submit_button_is_not_nested_inside_a_grid(): void
    {
        // The regression: an unclosed `<div class="grid gap-4 sm:grid-cols-2">` swallowed
        // the notes block and the action row, making "Save expense" a grid cell sitting
        // level with "Notes" from the sm breakpoint up.
        [$xpath] = $this->dom('/expenses/create');

        $buttons = $xpath->query('//button[contains(@class,"bg-gray-800")]');
        $this->assertSame(1, $buttons->length, 'The expense form must render exactly one primary button.');

        $this->assertStringNotContainsString(
            'grid',
            $this->ancestorClasses($buttons->item(0)),
            'The submit button is inside a grid, so it lays out as a grid cell beside a field.'
        );
    }

    public function test_the_expense_submit_button_comes_after_every_field(): void
    {
        [$xpath, $order] = $this->dom('/expenses/create');

        $button = $xpath->query('//button[contains(@class,"bg-gray-800")]')->item(0);
        $notes = $xpath->query('//textarea[@id="notes"]')->item(0);

        $this->assertNotNull($notes, 'The expense form must still render its notes field.');

        $this->assertGreaterThan(
            $this->position($order, $notes),
            $this->position($order, $button),
            'The submit action must come after the notes field in document order.'
        );
    }

    public function test_the_expense_action_row_sits_outside_the_field_card(): void
    {
        // sales/create and purchases/form both put the action row outside the field card.
        [$xpath] = $this->dom('/expenses/create');

        $actionRow = $xpath->query('//button[contains(@class,"bg-gray-800")]')->item(0)->parentNode;
        $card = $xpath->query('//div[contains(@class,"shadow")]')->item(0);

        $this->assertTrue($this->isBlockContainer($actionRow), 'The action row must be its own block container.');
        $this->assertNotNull($card);
        $this->assertFalse(
            $this->containsNode($card, $actionRow),
            'The action row is still inside the field card instead of below it.'
        );
    }

    public function test_the_supplier_checkbox_is_stacked_above_its_submit_button(): void
    {
        [$xpath, $order] = $this->dom('/suppliers/create');

        $checkbox = $xpath->query('//input[@name="is_active"][@type="checkbox"]')->item(0);
        $button = $xpath->query('//button[contains(@class,"bg-gray-800")]')->item(0);

        $this->assertNotNull($checkbox, 'The supplier form must render the Active checkbox.');
        $this->assertNotNull($button, 'The supplier form must render a primary submit button.');

        // The checkbox wrapper must be a BLOCK-level box. As a bare inline-flex <label> it
        // shared a line with the inline-flex submit button instead of stacking above it.
        $wrapper = $checkbox->parentNode->parentNode;

        $this->assertTrue(
            $this->isBlockContainer($wrapper),
            'The checkbox needs a block-level wrapper so it cannot share a line with the button.'
        );
        $this->assertStringContainsString('flex', $wrapper->getAttribute('class'));

        $actionRow = $button->parentNode;
        $this->assertTrue($this->isBlockContainer($actionRow), 'The action row must be its own block container.');
        $this->assertStringContainsString('flex', $actionRow->getAttribute('class'));

        $this->assertNotSame(
            $wrapper,
            $actionRow,
            'The submit button must not share the checkbox row.'
        );

        $this->assertGreaterThan(
            $this->position($order, $checkbox),
            $this->position($order, $button),
            'The submit button must come after the Active checkbox in document order.'
        );
    }

    public function test_the_supplier_edit_page_uses_the_same_structure(): void
    {
        $supplier = \App\Models\Supplier::create([
            'tenant_id' => $this->tenant->id, 'name' => 'PT Diff', 'is_active' => true,
        ]);

        [$xpath, $order] = $this->dom('/suppliers/'.$supplier->id.'/edit');

        $checkbox = $xpath->query('//input[@name="is_active"][@type="checkbox"]')->item(0);
        $button = $xpath->query('//button[contains(@class,"bg-gray-800")]')->item(0);

        $this->assertNotNull($checkbox, 'The supplier edit page must render the Active checkbox.');
        $this->assertNotNull($button, 'The supplier edit page must render a primary button.');

        $this->assertTrue($this->isBlockContainer($checkbox->parentNode->parentNode));

        $this->assertGreaterThan(
            $this->position($order, $checkbox),
            $this->position($order, $button),
            'On /suppliers/{id}/edit the button must sit below the Active checkbox.'
        );
    }

    public function test_the_supplier_form_stacks_on_small_screens(): void
    {
        [$xpath] = $this->dom('/suppliers/create');

        $checkbox = $xpath->query('//input[@name="is_active"][@type="checkbox"]')->item(0);
        $button = $xpath->query('//button[contains(@class,"bg-gray-800")]')->item(0);

        // Neither may sit inside a responsive grid that would put them side by side from
        // the sm breakpoint up. Both wrappers are plain block/flex containers.
        $this->assertStringNotContainsString('grid', $this->ancestorClasses($button));

        // The action row wraps rather than overflowing on narrow screens.
        $this->assertStringContainsString('flex', $button->parentNode->getAttribute('class'));
    }

    // ------------------------------------------------------- listing consistency

    /**
     * The exact markup /sales uses for a listing table.
     *
     * Read from sales/index itself rather than hard-coded, so the reference cannot drift
     * away from the page it describes.
     */
    private function salesTableTokens(): array
    {
        $sales = $this->member()->get('/sales')->assertOk()->getContent();

        // The markup is matched across whitespace so a re-indent of /sales cannot break the
        // reference the other listings are compared against.
        preg_match('/<div class="(overflow-x-auto[^"]*)">\s*<table class="([^"]*)">/', $sales, $wrapper);
        preg_match('/<thead class="([^"]*)">\s*<tr class="([^"]*)">/', $sales, $head);
        preg_match('/<tbody class="([^"]*)">/', $sales, $body);

        $this->assertNotEmpty($wrapper, '/sales no longer matches the expected table markup.');
        $this->assertNotEmpty($head, '/sales no longer matches the expected header markup.');

        return [
            'wrapper' => $wrapper[1],
            'table'   => $wrapper[2],
            'thead'   => $head[1],
            'headrow' => $head[2],
            'tbody'   => $body[1],
        ];
    }

    private function assertUsesSalesTableStyling(string $url, string $label): void
    {
        $html = $this->member()->get($url)->assertOk()->getContent();
        $tokens = $this->salesTableTokens();

        foreach ($tokens as $what => $classes) {
            $this->assertStringContainsString(
                $classes,
                $html,
                "{$label} does not use the /sales {$what} classes, so the listing will not match."
            );
        }
    }

    public function test_the_three_listings_share_the_sales_table_look(): void
    {
        $this->assertUsesSalesTableStyling('/purchases', '/purchases');
        $this->assertUsesSalesTableStyling('/expenses', '/expenses');
        $this->assertUsesSalesTableStyling('/expense-categories', '/expense-categories');
    }

    public function test_every_listing_header_cell_is_padded(): void
    {
        // /sales pads each <th>; the purchases table had bare <th> cells for most columns,
        // so its header text sat flush against the cell edge.
        foreach (['/purchases', '/expenses', '/expense-categories'] as $url) {
            [$xpath] = $this->dom($url);
            $cells = $xpath->query('//table//thead//th');

            $this->assertGreaterThan(0, $cells->length, "{$url} rendered no table headers.");

            foreach ($cells as $cell) {
                $this->assertStringContainsString(
                    'px-4 py-3',
                    $cell->getAttribute('class'),
                    "{$url}: a header cell lost its padding."
                );
            }
        }
    }

    public function test_the_purchase_status_column_is_a_badge_like_sales(): void
    {
        $supplier = \App\Models\Supplier::create([
            'tenant_id' => $this->tenant->id, 'name' => 'PT Badge', 'is_active' => true,
        ]);

        $warehouse = \App\Models\Warehouse::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Gudang Badge', 'is_active' => true,
        ]);

        $purchase = \App\Models\Purchase::create([
            'tenant_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'invoice_number' => 'PO-1', 'status' => 'ordered',
            'subtotal' => 0, 'discount' => 0, 'tax' => 0, 'shipping' => 0, 'total' => 0,
            'ordered_at' => now(),
        ]);

        [$xpath] = $this->dom('/purchases');

        $this->assertGreaterThan(
            0,
            $xpath->query('//table//tbody//span[contains(@class,"rounded-full")]')->length,
            'The purchase status must render as a badge, the way /sales renders its statuses.'
        );

        $this->assertStringContainsString(
            $purchase->invoice_number,
            $this->member()->get('/purchases')->getContent()
        );
    }

    // ------------------------------------------------------------------- filters

    public function test_listing_filters_are_labelled(): void
    {
        $expected = [
            '/purchases' => ['status', 'supplier_id', 'from', 'to'],
            '/expenses' => ['from', 'to'],
        ];

        foreach ($expected as $url => $ids) {
            [$xpath] = $this->dom($url);

            foreach ($ids as $id) {
                $control = $xpath->query('//*[@id="'.$id.'"]')->item(0);
                $this->assertNotNull($control, "{$url} has no control with id={$id}.");

                // A label bound by `for`, which wires the field to its input for assistive
                // technology as well as for sighted users.
                $label = $xpath->query('//label[@for="'.$id.'"]')->item(0);

                $this->assertNotNull($label, "{$url}: no <label for=\"{$id}\"> on the filter field.");
                $this->assertNotSame('', trim($label->textContent));
            }
        }
    }

    public function test_the_expense_filter_button_is_left_aligned_and_content_sized(): void
    {
        [$xpath] = $this->dom('/expenses');

        $button = $xpath->query('//form[@method="GET"]//button')->item(0);
        $this->assertNotNull($button, 'The expenses filter form has no submit button.');

        $this->assertSame(__('Filter'), trim($button->textContent));

        // It lives in its own flex row rather than as a bare grid item, which is what
        // stretched it across a whole column before.
        $rowClasses = $button->parentNode->getAttribute('class');
        $this->assertStringContainsString('flex', $rowClasses);
        $this->assertStringContainsString('items-center', $rowClasses);

        // Not full width, and not centred.
        $buttonClasses = $button->getAttribute('class');
        $this->assertStringContainsString('px-4', $buttonClasses);
        $this->assertStringContainsString('py-2', $buttonClasses);
        $this->assertStringNotContainsString('w-full', $buttonClasses);
        $this->assertStringNotContainsString('justify-center', $buttonClasses);

        // Byte-for-byte identical to the /sales reference button.
        $sales = $this->member()->get('/sales')->assertOk()->getContent();
        preg_match('/<button class="([^"]*)">'.preg_quote(__('Filter'), '/').'<\/button>/', $sales, $m);

        $this->assertNotEmpty($m, '/sales no longer has the reference Filter button.');
        $this->assertSame($m[1], $buttonClasses);
    }

    public function test_expenses_and_purchases_offer_a_reset_action(): void
    {
        foreach (['/expenses', '/purchases'] as $url) {
            [$xpath] = $this->dom($url);

            $reset = $xpath->query('//form[@method="GET"]//a')->item(0);

            $this->assertNotNull($reset, "{$url} has no reset link.");
            $this->assertSame(__('Reset'), trim($reset->textContent));

            // Reset drops the filter query string by linking back to the bare index.
            $this->assertSame(url($url), $reset->getAttribute('href'));
        }
    }

    public function test_resetting_the_expense_filter_returns_the_full_list(): void
    {
        \App\Models\Expense::create([
            'tenant_id' => $this->tenant->id, 'description' => 'Listrik',
            'amount' => 1000, 'expense_date' => now()->toDateString(),
            'payment_method' => 'cash', 'created_by' => $this->owner->id,
        ]);

        // A window in the past excludes everything created now.
        $this->member()
            ->get('/expenses?from=2000-01-01&to=2000-01-02')
            ->assertOk()
            ->assertSee(__('No expenses found.'));

        // Following Reset — the bare index URL — restores the unfiltered listing.
        [$xpath] = $this->dom('/expenses');
        $reset = $xpath->query('//form[@method="GET"]//a')->item(0);

        $this->member()
            ->get($reset->getAttribute('href'))
            ->assertOk()
            ->assertSee('Listrik');
    }
}
