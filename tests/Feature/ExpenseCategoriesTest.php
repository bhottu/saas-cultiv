<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Expense categories are a first-class, user-managed resource.
 *
 * The `expense_categories` table, the model and the FK on `expenses` all shipped with the
 * business-finance migration, but nothing ever let a user create a row — so the Category
 * dropdown on /expenses/create was structurally incapable of holding anything and no
 * route anywhere could change that.
 *
 * These tests pin the management surface, and one important boundary: expense categories
 * are NOT product categories. `categories` classifies the catalogue, `expense_categories`
 * classifies spend, and the two are never merged or cross-populated.
 */
class ExpenseCategoriesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create([
            'name' => 'Owner', 'email' => 'expcat-owner@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);

        $this->tenant = Tenant::create([
            'name' => 'Toko Sederhana', 'slug' => 'toko-sederhana', 'owner_id' => $this->owner->id,
        ]);

        $this->tenant->users()->attach($this->owner->id, [
            'role' => 'Owner', 'status' => TenantUser::STATUS_ACTIVE, 'joined_at' => now(),
        ]);
    }

    private function member(?Tenant $tenant = null, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->owner)
            ->withSession(['tenant_id' => ($tenant ?? $this->tenant)->id]);
    }

    private function viewer(): User
    {
        $user = User::create([
            'name' => 'Viewer', 'email' => 'expcat-viewer@test.dev',
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);

        $this->tenant->users()->attach($user->id, [
            'role' => 'Viewer', 'status' => TenantUser::STATUS_ACTIVE, 'joined_at' => now(),
        ]);

        return $user;
    }

    private function otherWorkspace(string $email): array
    {
        $user = User::create([
            'name' => 'Other', 'email' => $email,
            'password' => Hash::make('password'), 'email_verified_at' => now(),
        ]);

        $tenant = Tenant::create([
            'name' => 'Toko '.Str($email)->before('@')->replace('-', ' ')->title()->toString(),
            'slug' => str($email)->before('@')->toString(),
            'owner_id' => $user->id,
        ]);

        $tenant->users()->attach($user->id, [
            'role' => 'Owner', 'status' => TenantUser::STATUS_ACTIVE, 'joined_at' => now(),
        ]);

        return [$tenant, $user];
    }

    // ------------------------------------------------------------ the entry point

    public function test_the_management_pages_exist_and_open(): void
    {
        foreach (['/expense-categories', '/expense-categories/create'] as $url) {
            $this->member()->get($url)->assertOk();
        }
    }

    public function test_the_expense_form_offers_a_path_to_manage_categories(): void
    {
        // The dropdown alone never explained where its options come from. This link closes
        // that gap, and it must not be dead.
        $html = $this->member()->get('/expenses/create')->assertOk()->getContent();

        $this->assertStringContainsString('href="'.url('/expense-categories').'"', $html);
        $this->assertStringContainsString(__('Manage categories'), $html);
    }

    public function test_the_expense_index_links_to_the_categories(): void
    {
        $html = $this->member()->get('/expenses')->assertOk()->getContent();

        $this->assertStringContainsString('href="'.url('/expense-categories').'"', $html);
    }

    public function test_the_empty_state_says_so_instead_of_looking_broken(): void
    {
        $html = $this->member()->get('/expense-categories')->assertOk()->getContent();

        $this->assertStringContainsString('No expense categories yet.', $html);
        $this->assertStringContainsString('href="'.url('/expense-categories/create').'"', $html);
    }

    public function test_the_navigation_reaches_the_categories(): void
    {
        $html = $this->member()->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('href="'.url('/expense-categories').'"', $html);
    }

    // --------------------------------------------------------------------- crud

    public function test_a_category_can_be_created_and_then_used_on_an_expense(): void
    {
        $this->member()
            ->from('/expense-categories/create')
            ->post('/expense-categories', ['name' => 'Utilities', 'is_active' => '1'])
            ->assertRedirect('/expense-categories')
            ->assertSessionHas('status');

        $category = ExpenseCategory::firstOrFail();
        $this->assertSame($this->tenant->id, $category->tenant_id);

        // The whole point: it now shows up where it is needed.
        $html = $this->member()->get('/expenses/create')->assertOk()->getContent();
        $this->assertStringContainsString('Utilities', $html);

        $this->member()
            ->from('/expenses/create')
            ->post('/expenses', [
                'description' => 'Listrik Oktober',
                'amount' => '450000',
                'expense_date' => now()->toDateString(),
                'category_id' => $category->id,
            ])
            ->assertRedirect('/expenses');

        $expense = Expense::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($category->id, $expense->category_id);
        $this->assertSame($this->tenant->id, $expense->tenant_id);
    }

    public function test_a_category_can_be_renamed_and_deactivated(): void
    {
        $category = ExpenseCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Old Name', 'is_active' => true,
        ]);

        $this->member()
            ->put("/expense-categories/{$category->id}", ['name' => 'New Name', 'is_active' => '0'])
            ->assertRedirect('/expense-categories');

        $category->refresh();
        $this->assertSame('New Name', $category->name);
        $this->assertFalse($category->is_active);

        // A deactivated category drops out of the picker.
        $this->assertStringNotContainsString(
            'New Name',
            $this->member()->get('/expenses/create')->assertOk()->getContent()
        );
    }

    public function test_a_used_category_is_not_deleted_out_from_under_its_expenses(): void
    {
        $category = ExpenseCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Rent', 'is_active' => true,
        ]);

        Expense::create([
            'tenant_id' => $this->tenant->id, 'category_id' => $category->id,
            'description' => 'Sewa bulan ini', 'amount' => 1_500_000,
            'expense_date' => now()->toDateString(), 'payment_method' => 'cash',
            'created_by' => $this->owner->id,
        ]);

        $this->member()
            ->from('/expense-categories')
            ->delete("/expense-categories/{$category->id}")
            ->assertRedirect();

        // The FK is nullOnDelete, so allowing this would quietly strip the category off
        // historic expenses. Refused instead.
        $this->assertNotNull(ExpenseCategory::find($category->id));
        $this->assertNotNull(Expense::withoutGlobalScopes()->first()?->category_id);
    }

    public function test_an_unused_category_can_be_deleted(): void
    {
        $category = ExpenseCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Spare', 'is_active' => true,
        ]);

        $this->member()->delete("/expense-categories/{$category->id}")->assertRedirect();

        $this->assertNull(ExpenseCategory::find($category->id));
    }

    // ------------------------------------------------------------- validation

    public function test_a_category_name_is_required(): void
    {
        $this->member()
            ->from('/expense-categories/create')
            ->post('/expense-categories', ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->member()->get('/expense-categories/create')->assertOk();
        $this->assertSame(0, ExpenseCategory::count());
    }

    public function test_duplicate_names_within_one_workspace_are_refused(): void
    {
        ExpenseCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Utilities', 'is_active' => true]);

        $this->member()
            ->from('/expense-categories/create')
            ->post('/expense-categories', ['name' => 'Utilities'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, ExpenseCategory::count());
    }

    public function test_the_same_name_is_allowed_in_a_different_workspace(): void
    {
        ExpenseCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Utilities', 'is_active' => true]);

        [$other, $stranger] = $this->otherWorkspace('expcat-unique@test.dev');

        // The unique index is (tenant_id, name), so the composite scope must match it.
        $this->member($other, $stranger)
            ->post('/expense-categories', ['name' => 'Utilities', 'is_active' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, ExpenseCategory::withoutGlobalScopes()->count());
    }

    // ------------------------------------------------------------- isolation

    public function test_another_workspaces_category_is_not_reachable_or_editable(): void
    {
        [$other, $stranger] = $this->otherWorkspace('expcat-stranger@test.dev');

        $foreign = ExpenseCategory::create([
            'tenant_id' => $other->id, 'name' => 'Their Category', 'is_active' => true,
        ]);

        // Hidden from this workspace's list and picker...
        $this->assertStringNotContainsString(
            'Their Category',
            $this->member()->get('/expense-categories')->assertOk()->getContent()
        );
        $this->assertStringNotContainsString(
            'Their Category',
            $this->member()->get('/expenses/create')->assertOk()->getContent()
        );

        // ...and not addressable by id.
        $this->member()->get("/expense-categories/{$foreign->id}/edit")->assertNotFound();
        $this->member()->put("/expense-categories/{$foreign->id}", ['name' => 'Hijacked'])->assertNotFound();
        $this->member()->delete("/expense-categories/{$foreign->id}")->assertNotFound();

        $this->assertSame('Their Category', $foreign->fresh()->name);
    }

    public function test_an_expense_cannot_be_filed_under_a_foreign_category(): void
    {
        [$other, $stranger] = $this->otherWorkspace('expcat-guard@test.dev');

        $foreign = ExpenseCategory::create([
            'tenant_id' => $other->id, 'name' => 'Theirs', 'is_active' => true,
        ]);

        $this->member()
            ->from('/expenses/create')
            ->post('/expenses', [
                'description' => 'Sneaky', 'amount' => '1000', 'expense_date' => now()->toDateString(),
                'category_id' => $foreign->id,
            ])
            ->assertSessionHasErrors('category_id');

        $this->assertSame(0, Expense::withoutGlobalScopes()->count());
    }

    // ------------------------------ never confused with the product categories

    public function test_product_categories_are_not_offered_as_expense_categories(): void
    {
        Category::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Beverages',
            'slug' => 'beverages',
            'is_active' => true,
        ]);

        // The catalogue category must not leak into the expense picker...
        $this->assertStringNotContainsString(
            'Beverages',
            $this->member()->get('/expenses/create')->assertOk()->getContent()
        );

        // ...nor into the expense-category manager.
        $this->assertStringNotContainsString(
            'Beverages',
            $this->member()->get('/expense-categories')->assertOk()->getContent()
        );

        // And creating one does not create the other.
        $this->member()->post('/expense-categories', ['name' => 'Utilities', 'is_active' => '1']);
        $this->assertSame(1, ExpenseCategory::count());
        $this->assertSame(1, Category::count());
    }

    public function test_a_product_category_can_still_be_managed_on_its_own_page(): void
    {
        // Adding the expense resource must not have disturbed the catalogue one.
        $this->member()->get('/categories')->assertOk();
        $this->member()->get('/categories/create')->assertOk();
    }

    // ---------------------------------------------------------- authorization

    public function test_a_viewer_can_read_categories_but_not_create_them(): void
    {
        $viewer = $this->viewer();

        $this->member(null, $viewer)->get('/expense-categories')->assertOk();
        $this->member(null, $viewer)->post('/expense-categories', ['name' => 'Sneaky'])->assertForbidden();
        $this->assertSame(0, ExpenseCategory::count());
    }

    public function test_guests_are_redirected_to_login(): void
    {
        foreach (['/expense-categories', '/expense-categories/create'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }
}