<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\AuditLogger;
use App\Services\BusinessAuthorization;
use App\Services\InventoryService;
use App\Services\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Product CRUD (tenant-scoped).
 *
 * Rows are scoped by the BelongsToTenant global scope (tenant context comes from the
 * tenant middleware) and every action is gated through BusinessAuthorization.
 *
 * Money: stored as cents (unsigned integers) using the single app-wide convention in
 * App\Services\Money; the controller converts user-facing amounts on the way in.
 */
class ProductsController extends Controller
{
    public function __construct(
        private readonly BusinessAuthorization $auth,
        private readonly InventoryService $inventory,
    ) {}

    public function index(Request $request)
    {
        $this->auth->authorize('products.view');

        $tenant = $request->user()->currentTenant;

        $query = Product::with(['category', 'brand'])->where('tenant_id', $tenant->id);

        if ($request->filled('search')) {
            $term = $request->string('search')->toString();
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('sku', 'like', "%{$term}%")
                    ->orWhere('barcode', 'like', "%{$term}%");
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        if ($request->filled('brand_id')) {
            $query->where('brand_id', $request->integer('brand_id'));
        }

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        if ($request->boolean('show_inactive')) {
            $query->where('is_active', false);
        }

        return view('products.index', [
            'products' => $query->orderBy('name')->paginate(50)->withQueryString(),
            'categories' => Category::where('tenant_id', $tenant->id)->orderBy('name')->get(),
            'brands' => Brand::where('tenant_id', $tenant->id)->orderBy('name')->get(),
            'filters' => $request->only(['search', 'category_id', 'brand_id', 'active_only', 'show_inactive']),
        ]);
    }

    public function create(Request $request)
    {
        $this->auth->authorize('products.create');

        $tenant = $request->user()->currentTenant;

        return view('products.form', [
            'product' => new Product(['unit' => 'pcs', 'track_inventory' => true, 'is_active' => true]),
            'categories' => Category::where('tenant_id', $tenant->id)->orderBy('name')->get(),
            'brands' => Brand::where('tenant_id', $tenant->id)->orderBy('name')->get(),
            'pageTitle' => 'Add Product',
            'submitUrl' => route('products.store'),
        ]);
    }

    public function store(Request $request)
    {
        $this->auth->authorize('products.create');

        $tenantId = $this->tenantId($request);

        $validated = $request->validate($this->rules($tenantId));

        $product = DB::transaction(function () use ($request, $validated, $tenantId) {
            $product = Product::create(array_merge($this->attributes($validated), ['tenant_id' => $tenantId]));

            if ($product->track_inventory) {
                $warehouse = $request->user()->currentTenant->warehouses()->active()->first();
                if ($warehouse) {
                    $this->inventory->ensureBalance($request->user()->currentTenant, $product, $warehouse->id);
                }
            }

            return $product;
        });

        AuditLogger::log('product.created', $product, ['name' => $product->name, 'sku' => $product->sku]);

        return redirect()->route('products.show', $product)->with('status', [
            'type' => 'success',
            'message' => 'Product created.',
        ]);
    }

    public function show(Product $product)
    {
        $this->auth->authorize('products.view');
        $this->ensureOwned($product);

        return view('products.show', [
            'product' => $product->load(['category', 'brand']),
            'stockBalances' => $product->stockBalances()->with('warehouse')->orderBy('warehouse_id')->get(),
            'recentMovements' => $product->stockMovements()->latest('id')->limit(20)->get(),
        ]);
    }

    public function edit(Product $product)
    {
        $this->auth->authorize('products.update');
        $this->ensureOwned($product);

        $tenant = request()->user()->currentTenant;

        return view('products.form', [
            'product' => $product,
            'categories' => Category::where('tenant_id', $tenant->id)->orderBy('name')->get(),
            'brands' => Brand::where('tenant_id', $tenant->id)->orderBy('name')->get(),
            'pageTitle' => 'Edit Product',
            'submitUrl' => route('products.update', $product),
        ]);
    }

    public function update(Request $request, Product $product)
    {
        $this->auth->authorize('products.update');
        $this->ensureOwned($product);

        $validated = $request->validate($this->rules($this->tenantId($request), $product));

        DB::transaction(function () use ($request, $product, $validated) {
            $product->update($this->attributes($validated));

            if ($product->track_inventory) {
                $warehouse = $request->user()->currentTenant->warehouses()->active()->first();
                if ($warehouse) {
                    $this->inventory->ensureBalance($request->user()->currentTenant, $product, $warehouse->id);
                }
            }
        });

        AuditLogger::log('product.updated', $product, ['name' => $product->name, 'sku' => $product->sku]);

        return redirect()->route('products.show', $product)->with('status', [
            'type' => 'success',
            'message' => 'Product updated.',
        ]);
    }

    public function destroy(Request $request, Product $product)
    {
        $this->auth->authorize('products.delete');
        $this->ensureOwned($product);

        $onHand = (int) $product->stockBalances()->where('quantity', '!=', 0)->sum('quantity');

        if ($onHand !== 0) {
            return back()->with('status', [
                'type' => 'error',
                'message' => 'Cannot delete a product that still has stock on hand.',
            ]);
        }

        AuditLogger::log('product.deleted', $product, ['name' => $product->name, 'sku' => $product->sku]);
        $product->delete();

        return redirect()->route('products.index')->with('status', [
            'type' => 'success',
            'message' => 'Product deleted.',
        ]);
    }

    /** Validation rules scoped to the tenant (SKU/barcode are unique per tenant). */
    private function rules(int $tenantId, ?Product $product = null): array
    {
        $unique = fn (string $column) => Rule::unique('products', $column)
            ->where(fn ($query) => $query->where('tenant_id', $tenantId))
            ->ignore($product?->id);

        $owned = fn (string $table) => Rule::exists($table, 'id')
            ->where(fn ($query) => $query->where('tenant_id', $tenantId));

        return [
            'name' => 'required|string|max:255',
            'sku' => ['nullable', 'string', 'max:60', $unique('sku')],
            'barcode' => ['nullable', 'string', 'max:100', $unique('barcode')],
            'category_id' => ['nullable', 'integer', $owned('categories')],
            'brand_id' => ['nullable', 'integer', $owned('brands')],
            'description' => 'nullable|string|max:65535',
            'unit' => 'required|string|max:30',
            'purchase_price' => 'nullable|numeric|min:0|max:100000000',
            'selling_price' => 'nullable|numeric|min:0|max:100000000',
            'cost_price' => 'nullable|numeric|min:0|max:100000000',
            'minimum_stock' => 'nullable|integer|min:0|max:100000000',
            'max_stock' => 'nullable|integer|min:0|max:100000000',
            'track_inventory' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Map validated (user-facing) input onto storable attributes (cents for money). */
    private function attributes(array $validated): array
    {
        return [
            'name' => $validated['name'],
            'sku' => $validated['sku'] ?? null,
            'barcode' => $validated['barcode'] ?? null,
            'category_id' => $validated['category_id'] ?? null,
            'brand_id' => $validated['brand_id'] ?? null,
            'description' => $validated['description'] ?? null,
            'unit' => $validated['unit'] ?? 'pcs',
            'purchase_price' => Money::centsFromDisplay($validated['purchase_price'] ?? 0),
            'selling_price' => Money::centsFromDisplay($validated['selling_price'] ?? 0),
            // Cost falls back to the purchase price (used by reports/AVCO).
            'cost_price' => Money::centsFromDisplay($validated['cost_price'] ?? $validated['purchase_price'] ?? 0),
            'minimum_stock' => (int) ($validated['minimum_stock'] ?? 0),
            'max_stock' => isset($validated['max_stock']) ? (int) $validated['max_stock'] : null,
            'track_inventory' => (bool) ($validated['track_inventory'] ?? false),
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ];
    }

    private function tenantId(Request $request): int
    {
        return $request->user()->currentTenant->id;
    }

    private function ensureOwned(Product $product): void
    {
        // Never trust the route id: compare against the *active* tenant context.
        if (! $product->tenant_id || $product->tenant_id !== app('tenant.context')->tenant()?->id) {
            abort(404);
        }
    }
}