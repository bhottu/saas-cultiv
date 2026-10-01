<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Module;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockBalance;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\ModuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * POS: create-a-customer and editable line price.
 *
 * Both are deliberately built ON /sales/create rather than beside it — the same shared
 * modal partial, the same customers.store endpoint, the same validation rules and the
 * same server-side money path. These tests pin that reuse, because the moment the POS
 * grows its own customer form or its own total arithmetic the two screens drift apart.
 */
class PosCustomerAndPriceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Tenant $tenant;
    private Warehouse $warehouse;
    private Product $product;
    private Product $secondProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ModuleSeeder::class);

        $this->owner = User::create([
            'name' => 'POS Cashier', 'email' => 'pos-ux@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);

        $this->tenant = Tenant::create([
            'name' => 'POS UX Workspace', 'slug' => 'pos-ux-workspace',
            'owner_id' => $this->owner->id, 'status' => 'active',
        ]);
        $this->tenant->users()->attach($this->owner->id, [
            'role' => 'Owner', 'status' => 'active', 'joined_at' => now(),
        ]);

        TenantModule::create([
            'tenant_id' => $this->tenant->id,
            'module_id' => Module::where('key', 'pos')->firstOrFail()->id,
            'status' => TenantModule::STATUS_ACTIVE,
            'installed_at' => now(), 'activated_at' => now(),
        ]);

        $this->warehouse = Warehouse::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Gudang', 'code' => 'GD', 'is_active' => true,
        ]);

        $category = Category::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Umum', 'slug' => 'umum', 'is_active' => true,
        ]);
        $brand = Brand::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Merek', 'slug' => 'merek', 'is_active' => true,
        ]);

        // selling_price is stored in CENTS, like every price in this schema.
        $this->product = $this->makeProduct($category, $brand, 'PX-1', 'Produk X', 10_000_000);
        $this->secondProduct = $this->makeProduct($category, $brand, 'PX-2', 'Produk Y', 5_000_000);
    }

    // ------------------------------------------------------------------ customer

    /** The counter offers the same affordance the back-office sale screen does. */
    public function test_the_pos_offers_add_customer_using_the_shared_sales_modal(): void
    {
        $html = $this->asOwner()->get('/pos')->assertOk()->getContent();

        // Button, and the sentinel option that opens the modal from the select itself.
        $this->assertStringContainsString('id="pos-add-customer"', $html);
        $this->assertStringContainsString('value="__add_customer__"', $html);
        $this->assertStringContainsString('+ Add Customer', $html);

        // Reused verbatim, not reimplemented: the same field ids and title the sales
        // form renders.
        $this->assertStringContainsString('customer-modal-title', $html);
        $this->assertStringContainsString('id="sale_customer_name"', $html);
        $this->assertStringContainsString('id="sale_customer_phone"', $html);

        // Posted to the one shared endpoint, with the one shared Alpine component.
        $this->assertStringContainsString(route('customers.store'), $html);
        $this->assertStringContainsString('posCustomerModal', $html);
    }

    /**
     * The scope that defines customerModalOpen must WRAP the modal.
     *
     * If the modal renders outside its x-data wrapper, every binding in it throws at
     * boot and the dialog silently never opens.
     */
    public function test_the_customer_modal_sits_inside_its_alpine_scope(): void
    {
        $html = $this->asOwner()->get('/pos')->assertOk()->getContent();

        $scopeAt = strpos($html, 'x-data="posCustomerModal(');
        $modalAt = strpos($html, 'id="customer-modal-title"');

        $this->assertNotFalse($scopeAt, 'The POS must define the posCustomerModal scope.');
        $this->assertNotFalse($modalAt, 'The shared customer modal must be rendered.');
        $this->assertLessThan($modalAt, $scopeAt);
        $this->assertLessThan($modalAt, strpos($html, '>', $scopeAt));
    }

    /**
     * The x-data attribute must survive the HTML parser intact.
     *
     * This is the production defect that made the button do nothing. The config was
     * interpolated with @json(), which emits literal " characters; inside
     * x-data="..." that closes the attribute early, the browser hands Alpine a
     * truncated expression, the whole subtree fails to initialise, customerModalOpen
     * never exists, and the dialog never opens — with no error the page shows.
     *
     * @js([...]) escapes those quotes to \u0022 and is attribute-safe. This test fails
     * the moment anyone swaps it back for @json().
     */
    public function test_the_alpine_scope_attribute_is_html_safe(): void
    {
        $html = $this->asOwner()->get('/pos')->assertOk()->getContent();

        // Read the attribute the way a browser does: from the opening quote to the
        // next one. Anything after that point is not ours.
        $prefix = 'x-data="';
        $start = strpos($html, 'x-data="posCustomerModal(');
        $this->assertNotFalse($start);

        $from = $start + strlen($prefix);
        $value = substr($html, $from, strpos($html, '"', $from) - $from);

        $this->assertStringNotContainsString(
            '"',
            $value,
            'The x-data value was cut short by an unescaped quote, so Alpine never initialises the modal.'
        );
        $this->assertStringContainsString('posCustomerModal(JSON.parse(', $value);
        $this->assertStringContainsString('storeUrl', $value);
        $this->assertStringContainsString('canCreate', $value);

        // Decode the payload the way the browser will. The \u0022 sequences are literal in
        // the HTML; JavaScript turns them into real quotes inside the single-quoted
        // string, and only then does JSON.parse see a valid document.
        $open = strpos($value, "JSON.parse('") + 12;
        $json = substr($value, $open, strpos($value, "'", $open) - $open);
        $config = json_decode(str_replace('\u0022', '"', $json), true);

        $this->assertIsArray($config, 'The Alpine scope config must be valid JSON once parsed.');
        $this->assertSame(
            route('customers.store'),
            str_replace('\/', '/', (string) $config['storeUrl']),
            'The create endpoint must survive into the Alpine scope intact.'
        );
        $this->assertTrue($config['canCreate']);
    }

    /** The button must announce itself, and the scope must answer that announcement. */
    public function test_the_add_customer_button_asks_the_scope_to_open_the_modal(): void
    {
        $html = $this->asOwner()->get('/pos')->assertOk()->getContent();

        // The control exists and is wired to the cart engine.
        $this->assertStringContainsString('id="pos-add-customer"', $html);
        $this->assertStringContainsString("dom.addCustomer.addEventListener('click', openCustomerModal)", $html);

        // The scope listens for exactly that signal, with no reliance on Alpine's
        // private internals.
        $this->assertStringContainsString("addEventListener('pos-open-customer-modal'", $html);
        $this->assertStringNotContainsString('_x_dataStack[0]', $html);
    }

    /** Creating from the counter uses the sales form's validation, phone included. */
    public function test_pos_customer_creation_uses_the_sales_form_validation(): void
    {
        $this->asOwner()->postJson('/customers', ['form_context' => 'pos', 'name' => 'Tanpa Telepon'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        $this->asOwner()->postJson('/customers', ['form_context' => 'pos', 'phone' => '0812'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertSame(0, Customer::count());
    }

    /** The context allow-list is explicit; nothing else is accepted. */
    public function test_an_unknown_form_context_is_rejected(): void
    {
        $this->asOwner()->postJson('/customers', [
            'form_context' => 'something-else', 'name' => 'X', 'phone' => '0812',
        ])->assertStatus(422)->assertJsonValidationErrors('form_context');
    }

    /** The new customer is selectable and reaches the sale, with the cart untouched. */
    public function test_a_customer_created_at_the_counter_is_attached_to_the_sale(): void
    {
        $this->asOwner()->postJson('/customers', [
            'form_context' => 'pos', 'name' => 'Budi Santoso', 'phone' => '081234567890',
        ])->assertCreated()->assertJsonPath('customer.name', 'Budi Santoso');

        $customer = Customer::firstOrFail();
        $this->assertSame($this->tenant->id, $customer->tenant_id);

        $payload = $this->payload();
        $payload['customer_id'] = $customer->id;
        $payload['client_reference'] = 'pos-with-new-customer';

        $sale = $this->asOwner()->postJson('/pos/checkout', $payload)
            ->assertOk()->assertJsonPath('success', true)->json();

        $this->assertSame($customer->id, Sale::find($sale['sale_id'])->customer_id);
    }

    /** A customer belonging to another workspace cannot be attached to this sale. */
    public function test_a_customer_from_another_workspace_is_refused(): void
    {
        $other = Tenant::create([
            'name' => 'Other WS', 'slug' => 'other-ws', 'owner_id' => $this->owner->id, 'status' => 'active',
        ]);
        $foreign = Customer::create([
            'tenant_id' => $other->id, 'name' => 'Asing', 'phone' => '0899', 'is_active' => true,
        ]);

        $payload = $this->payload();
        $payload['customer_id'] = $foreign->id;

        $this->asOwner()->postJson('/pos/checkout', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('customer_id');
    }

    // ------------------------------------------------------------- editable price

    /** The price cell is a control, not decorative text. */
    public function test_the_cart_price_is_clickable_and_editable(): void
    {
        $html = $this->asOwner()->get('/pos')->assertOk()->getContent();

        $this->assertStringContainsString('data-price-edit', $html);
        $this->assertStringContainsString('data-price-input', $html);
        $this->assertStringContainsString('commitUnitPrice', $html);
        // A plain number field: the formatted "Rp 10.000" is display only.
        $this->assertStringContainsString('type="number"', $html);
    }

    /**
     * The price the cashier typed is what gets recorded — whole rupiah from the
     * browser, integer cents in the ledger.
     */
    public function test_an_edited_price_is_recorded_on_the_sale(): void
    {
        $payload = $this->payload();
        $payload['items'][0]['unit_price'] = 15_000;   // was 100.000
        $payload['payment_amount'] = 30_000;
        $payload['client_reference'] = 'pos-edited-price-1';

        $this->asOwner()->postJson('/pos/checkout', $payload)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('total_fmt', 'Rp 30.000');

        $item = SaleItem::firstOrFail();

        // 15.000 rupiah typed -> 1.500.000 cents stored. No float drift.
        $this->assertSame(1_500_000, (int) $item->selling_price);
        $this->assertSame(2, (int) $item->quantity);
        $this->assertSame(3_000_000, (int) Sale::firstOrFail()->total);
    }

    /** Editing one line must leave the others exactly as they were. */
    public function test_editing_one_line_leaves_the_others_untouched(): void
    {
        $payload = $this->payload();
        $payload['items'][] = [
            'product_id' => $this->secondProduct->id,
            'quantity' => 1,
            'unit_price' => 50_000,
            'discount_type' => 'fixed',
            'discount_value' => 0,
        ];

        $payload['items'][0]['unit_price'] = 15_000; // only the first line changes
        $payload['payment_amount'] = 80_000;    // 30.000 (2 x 15.000) + 50.000
        $payload['client_reference'] = 'pos-edited-price-2';

        $this->asOwner()->postJson('/pos/checkout', $payload)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('total_fmt', 'Rp 80.000');

        $items = SaleItem::orderBy('product_id')->get()->keyBy('product_id');

        $this->assertSame(1_500_000, (int) $items[$this->product->id]->selling_price);
        $this->assertSame(5_000_000, (int) $items[$this->secondProduct->id]->selling_price);
    }

    /**
     * Hostile price input.
     *
     * The browser already refuses these in a number field, but the endpoint is
     * reachable directly, so the server is what actually has to hold.
     */
    public function test_invalid_prices_are_refused_by_the_server(): void
    {
        foreach ([
            'negative' => -5_000,
            'formatted currency' => 'Rp15.000',
            'letters' => 'fifteen thousand',
            'empty' => '',
            'not a number' => 'NaN',
        ] as $label => $value) {
            $payload = $this->payload();
            $payload['items'][0]['unit_price'] = $value;
            $payload['client_reference'] = 'pos-bad-'.md5($label);

            $this->asOwner()->postJson('/pos/checkout', $payload)
                ->assertStatus(422, "Expected a rejection for: {$label}")
                ->assertJsonValidationErrors('items.0.unit_price');

            // Nothing half-written survives a rejected price.
            $this->assertSame(0, Sale::where('client_reference', $payload['client_reference'])->count());
        }
    }

    /** A very large but legal amount is stored exactly, not as infinity. */
    public function test_a_large_price_is_stored_exactly(): void
    {
        $payload = $this->payload();
        $payload['items'][0]['quantity'] = 1;
        $payload['items'][0]['unit_price'] = 999_999_999;
        $payload['payment_amount'] = 999_999_999;
        $payload['client_reference'] = 'pos-large-price';

        $this->asOwner()->postJson('/pos/checkout', $payload)
            ->assertOk()
            ->assertJsonPath('success', true);

        // Whole rupiah in, exact integer cents out.
        $this->assertSame(99_999_999_900, (int) SaleItem::firstOrFail()->selling_price);
    }

    /** Zero is legal (a complimentary line) and must not be treated as missing. */
    public function test_a_zero_price_is_allowed(): void
    {
        $payload = $this->payload();
        $payload['items'][0]['unit_price'] = 0;
        $payload['payment_amount'] = 0;
        $payload['client_reference'] = 'pos-zero-price';

        $this->asOwner()->postJson('/pos/checkout', $payload)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(0, (int) SaleItem::firstOrFail()->selling_price);
    }

    /** The client cannot sell a product that is not this workspace's. */
    public function test_a_product_from_another_workspace_is_refused(): void
    {
        $other = Tenant::create([
            'name' => 'Other WS2', 'slug' => 'other-ws-2', 'owner_id' => $this->owner->id, 'status' => 'active',
        ]);
        $foreign = $this->makeProduct(
            Category::create(['tenant_id' => $other->id, 'name' => 'C', 'slug' => 'c2', 'is_active' => true]),
            Brand::create(['tenant_id' => $other->id, 'name' => 'B', 'slug' => 'b2', 'is_active' => true]),
            'FOREIGN', 'Produk Asing', 1_000_000, $other->id,
        );

        $payload = $this->payload();
        $payload['items'][0]['product_id'] = $foreign->id;
        $payload['client_reference'] = 'pos-foreign-product';

        $this->asOwner()->postJson('/pos/checkout', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.product_id');
    }
/**
     * Discount must exist exactly once and must never be hidden.
     *
     * The reported defect was "Discount shows on mobile, disappears on desktop",
     * which is not a responsive-hiding problem: there are no breakpoint classes on
     * these controls at any width. It is a layout problem inside the fixed-height
     * cart column. These tests pin both halves of that conclusion.
     */
    public function test_discount_is_rendered_once_and_never_hidden_by_a_breakpoint(): void
    {
        $html = $this->asOwner()->get('/pos')->assertOk()->getContent();

        // One implementation, not a desktop copy and a mobile copy.
        $this->assertSame(1, substr_count($html, 'id="pos-discount-value"'));
        $this->assertSame(1, substr_count($html, 'id="pos-discount-type"'));
        $this->assertSame(1, substr_count($html, 'id="pos-discount-total"'));

        // And no width-based hiding anywhere in the cart panel.
        foreach (['hidden', 'md:hidden', 'lg:hidden', 'sm:hidden', 'xl:hidden'] as $breaker) {
            $this->assertStringNotContainsString(
                $breaker,
                $this->discountMarkup($html),
                "Discount must not carry \"{$breaker}\"."
            );
        }
    }

    /**
     * The cart column is a fixed-height flex container; every section below the line
     * list must keep its natural height, and only the list may absorb the leftover
     * space (it scrolls).
     */
    public function test_the_discount_row_is_pinned_and_the_cart_list_is_the_only_scroller(): void
    {
        $html = $this->asOwner()->get('/pos')->assertOk()->getContent();

        // min-h-0 lets the list actually shrink; without it a full cart pushes
        // everything below it out of the column instead of scrolling.
        $this->assertStringContainsString(
            'id="pos-cart-items" class="min-h-0 flex-1 divide-y divide-gray-100 overflow-y-auto',
            $html
        );

        $sections = [
            // discount + tax row
            'discount' => '<div class="shrink-0 border-t border-gray-100 pt-3">',
            // subtotal / discount / tax / total summary
            'totals' => '<dl class="shrink-0 space-y-1.5 border-t border-gray-200 pt-3 text-sm">',
            // payment, quick cash, change, checkout
            'payment' => '<div class="mt-4 shrink-0 space-y-3 border-t border-gray-200 pt-3">',
        ];

        foreach ($sections as $name => $markup) {
            $this->assertStringContainsString(
                $markup,
                $html,
                "The {$name} section must be shrink-0, or the fixed-height column squeezes it away."
            );
        }
    }

    /** The discount controls live in the panel, not inside the scrolling cart list. */
    public function test_discount_is_not_nested_inside_the_scrolling_cart_list(): void
    {
        $html = $this->asOwner()->get('/pos')->assertOk()->getContent();

        $listAt = strpos($html, 'id="pos-cart-items"');
        $discountAt = strpos($html, 'id="pos-discount-type"');

        $this->assertNotFalse($listAt);
        $this->assertNotFalse($discountAt);
        $this->assertGreaterThan(
            $listAt,
            $discountAt,
            'The discount row must sit below the cart list element, not inside it.'
        );
    }

    /** The substring of markup that wraps the discount controls, for focused assertions. */
    private function discountMarkup(string $html): string
    {
        $from = strpos($html, 'id="pos-discount-type"') - 400;

        return substr($html, max(0, $from), 1200);
    }

    /** The discount control's identity is unchanged; only its layout classes moved. */
    public function test_the_discount_control_is_unchanged(): void
    {
        $html = $this->asOwner()->get('/pos')->assertOk()->getContent();

        $this->assertStringContainsString(
            '<input type="number" id="pos-discount-value" min="0" step="any" value="0"',
            $html
        );
        $this->assertStringContainsString('id="pos-discount-type"', $html);
        $this->assertStringContainsString('<option value="fixed">', $html);
        $this->assertStringContainsString('<option value="percent">', $html);

        // Tax keeps its identity too — the fix was layout, not new fields.
        $this->assertStringContainsString(
            '<input type="number" id="pos-tax-percent" min="0" max="100" value="0"',
            $html
        );
    }

    /**
     * Discount must sit ABOVE Tax, in one column, at every width.
     *
     * The row used to be `grid grid-cols-1 sm:grid-cols-2`, which put the two side by
     * side from the sm breakpoint up. That is what made them collide inside the narrow
     * POS column, and it required min-w-0 / shrink-0 to keep them apart. A single
     * full-width column removes the collision and the workarounds with it.
     */
    public function test_discount_sits_above_tax_in_a_single_column(): void
    {
        $html = $this->asOwner()->get('/pos')->assertOk()->getContent();

        // No two-column grid anywhere in the discount/tax row.
        $this->assertStringNotContainsString('sm:grid-cols-2', $this->discountRowMarkup($html));

        // Discount comes first and Tax second, in document order.
        $discount = strpos($html, 'id="pos-discount-type"');
        $tax = strpos($html, 'id="pos-tax-percent"');

        $this->assertNotFalse($discount);
        $this->assertNotFalse($tax);
        $this->assertLessThan($tax, $discount, 'Discount must be rendered above Tax.');

        // Same row wrapper for both, stacked by space-y-2 rather than by columns.
        $row = $this->discountRowMarkup($html);
        $this->assertSame(
            2,
            substr_count($row, '<div class="flex items-center gap-2">'),
            'Both controls keep the same plain flex row wrapper.'
        );
        $this->assertStringContainsString('<div class="space-y-2">', $row);

        // Still one of each, and still in normal flow.
        $this->assertSame(1, substr_count($html, 'id="pos-discount-value"'));
        $this->assertSame(1, substr_count($html, 'id="pos-tax-percent"'));
        $this->assertStringNotContainsString('absolute', $row);
    }

    /**
     * The two-column workarounds are gone, because there is no second column left.
     *
     * min-w-0 and shrink-0 existed only to stop the discount cell spilling into the tax
     * track. Keeping them would be dead code that implies a constraint the layout no
     * longer has.
     */
    public function test_the_two_column_workarounds_are_no_longer_present(): void
    {
        $html = $this->asOwner()->get('/pos')->assertOk()->getContent();

        // The section wrapper keeps its own shrink-0: that one is the VERTICAL fix for
        // the fixed-height column, not the horizontal two-column workaround. Only what
        // sits inside it is under test here.
        $row = $this->discountRowMarkup($html);
        $inner = substr($row, strpos($row, '>') + 1);

        $this->assertStringNotContainsString('min-w-0', $inner);
        $this->assertStringNotContainsString('shrink-0', $inner);

        // The controls still fill the row, which is what replaced the shrinking.
        $this->assertStringContainsString('flex-1', $this->tagAround($html, 'id="pos-discount-type"'));
        $this->assertStringContainsString('w-20 flex-1', $this->tagAround($html, 'id="pos-discount-value"'));
        $this->assertStringContainsString('w-20 flex-1', $this->tagAround($html, 'id="pos-tax-percent"'));
    }

    /** The opening tag of the element carrying the given attribute. */
    private function tagAround(string $html, string $attribute): string
    {
        $at = strpos($html, $attribute);
        $start = strrpos(substr($html, 0, $at), '<');

        return substr($html, $start, strpos($html, '>', $at) - $start);
    }

    /**
     * The discount + tax row, and nothing else.
     *
     * Anchored on the section wrapper and stopped at the totals block that follows it,
     * so the window cannot reach the product search above (which legitimately uses
     * min-w-0) nor the totals below (which legitimately uses shrink-0 for the
     * fixed-height column — an unrelated concern).
     */
    private function discountRowMarkup(string $html): string
    {
        $start = strpos($html, '<div class="shrink-0 border-t border-gray-100 pt-3">');

        if ($start === false) {
            return '';
        }

        $end = strpos($html, '<dl class="shrink-0', $start);

        return substr($html, $start, ($end === false ? 1800 : $end - $start));
    }

    /**
     * Discount still reaches the server, and the totals still come back computed.
     *
     * This is the part that must NOT change: visibility was the defect, so the money
     * path is asserted unchanged to prove nothing moved.
     */
    public function test_discount_is_still_applied_by_the_backend(): void
    {
        $this->asOwner()->postJson('/pos/calculate', [
            'discount_type' => 'fixed',
            'discount_value' => 10_000,
            'tax_percent' => 0,
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price' => 100_000,
                'discount_type' => 'fixed',
                'discount_value' => 0,
            ]],
        ])->assertOk()
            ->assertJsonPath('totals.subtotal', 10_000_000)   // Rp 100.000 in cents
            ->assertJsonPath('totals.discount', 1_000_000)    // Rp 10.000 in cents
            ->assertJsonPath('totals.total', 9_000_000);      // Rp 90.000 in cents
    }

    // ------------------------------------------------------------------ helpers

    private function makeProduct($category, $brand, string $sku, string $name, int $priceInCents, ?int $tenantId = null)
    {
        $tenantId ??= $this->tenant->id;

        $product = Product::create([
            'tenant_id' => $tenantId, 'category_id' => $category->id, 'brand_id' => $brand->id,
            'sku' => $sku, 'name' => $name, 'unit' => 'pcs',
            'purchase_price' => $priceInCents, 'cost_price' => $priceInCents,
            'selling_price' => $priceInCents, 'minimum_stock' => 0,
            'track_inventory' => true, 'is_active' => true,
        ]);

        StockBalance::create([
            'tenant_id' => $tenantId, 'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 50, 'incoming' => 50, 'outgoing' => 0,
        ]);

        return $product;
    }

    private function asOwner()
    {
        return $this->actingAs($this->owner)->withSession(['tenant_id' => $this->tenant->id]);
    }

    private function payload(): array
    {
        return [
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => null,
            'discount_type' => 'fixed',
            'discount_value' => 0,
            'tax_percent' => 0,
            'payment_method' => 'cash',
            'payment_amount' => 200_000,
            'client_reference' => 'pos-ux-ref',
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 2,
                'unit_price' => 100_000, // whole rupiah, as the browser sends it
                'discount_type' => 'fixed',
                'discount_value' => 0,
            ]],
        ];
    }



}
