<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\AuditLogger;
use App\Services\UsageService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends ApiController
{
    public function index(Request $request)
    {
        $this->auth->authorize('products.view');

        $query = Product::query()->with(['category:id,name', 'brand:id,name', 'stockBalances:id,product_id,quantity']);

        $this->applySearch($query, $request, ['name', 'sku', 'barcode']);

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        if ($request->filled('brand_id')) {
            $query->where('brand_id', $request->integer('brand_id'));
        }

        if (($active = $this->isActiveFilter($request)) !== null) {
            $query->where('is_active', $active);
        }

        if ($request->filled('min_price')) {
            $query->where('selling_price', '>=', $request->integer('min_price'));
        }

        if ($request->filled('max_price')) {
            $query->where('selling_price', '<=', $request->integer('max_price'));
        }

        if ($request->boolean('low_stock')) {
            $query->whereHas('stockBalances', fn ($q) => $q->whereRaw(
                'stock_balances.quantity <= products.minimum_stock'
            ));
        }

        $products = $query
            ->orderBy('name')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return $this->withMeta(ProductResource::collection($products), $request);
    }

    public function show(Request $request, int $product)
    {
        $this->auth->authorize('products.view');

        // Route-model binding is tenant-scoped, so a foreign id is a 404 here.
        $model = Product::with(['category:id,name', 'brand:id,name', 'stockBalances.warehouse:id,name,code'])
            ->findOrFail($product);

        return ProductResource::make($model)->additional(self::moneyContract());
    }

    /** Barcode / SKU lookup used by mobile scanners. */
    public function lookup(Request $request)
    {
        $this->auth->authorize('products.view');

        $code = $request->string('code')->toString();

        abort_if($code === '', 422, 'The "code" query parameter is required.');

        $model = Product::with(['category:id,name', 'brand:id,name', 'stockBalances:id,product_id,quantity'])
            ->where('barcode', $code)
            ->orWhere('sku', $code)
            ->first();

        if (! $model) {
            return response()->json([
                'message' => 'No product matches the supplied barcode or SKU.',
                'code' => 'product_not_found',
            ], 404);
        }

        return ProductResource::make($model)->additional(self::moneyContract());
    }

    private static function moneyContract(): array
    {
        return ['currency' => 'IDR', 'money_unit' => 'cents'];
    }

    /**
     * Create a product (§15): the SAME validation, the SAME per-tenant uniqueness and
     * the SAME metered `max_products` ceiling the web form enforces — no second,
     * laxer business path for API clients.
     *
     * tenant_id is never accepted from the payload; BelongsToTenant stamps it from the
     * token's workspace, so this endpoint cannot write into another workspace (§2).
     */
    public function store(Request $request)
    {
        $this->auth->authorize('products.create');

        $tenant = $this->tenant($request);
        $validated = $request->validate($this->writeRules($tenant));

        app(UsageService::class)->enforce($tenant, 'max_products');

        $product = Product::create($this->writeAttributes($validated));

        AuditLogger::log('product.created', $product, ['name' => $product->name, 'sku' => $product->sku]);

        return ProductResource::make($product)
            ->additional([...self::moneyContract(), 'message' => 'Product created.'])
            ->response()
            ->setStatusCode(201);
    }

    /** Update a product (PUT/PATCH). Foreign workspace id ⇒ 404 through tenant scope. */
    public function update(Request $request, int $product)
    {
        $this->auth->authorize('products.update');

        $tenant = $this->tenant($request);
        $model = Product::query()->findOrFail($product);

        $validated = $request->validate($this->writeRules($tenant, $model));

        $model->update($this->writeAttributes($validated));

        AuditLogger::log('product.updated', $model, ['name' => $model->name, 'sku' => $model->sku]);

        return ProductResource::make($model)
            ->additional([...self::moneyContract(), 'message' => 'Product updated.']);
    }

    /**
     * Web rules with ONE deliberate unit difference: this API's response meta declares
     * `money_unit: cents`, so money fields are accepted as integer cents (the web form
     * takes display amounts and converts through Money). Everything else — per-tenant
     * SKU/barcode uniqueness and tenant-owned category/brand existence — is the very
     * same rule object the web controller uses, not a copy that can drift from it.
     */
    private function writeRules($tenant, ?Product $product = null): array
    {
        $unique = fn (string $column) => Rule::unique('products', $column)
            ->where(fn ($query) => $query->where('tenant_id', $tenant->id))
            ->ignore($product?->id);

        $owned = fn (string $table) => Rule::exists($table, 'id')
            ->where(fn ($query) => $query->where('tenant_id', $tenant->id));

        return [
            'name' => 'required|string|max:255',
            'sku' => ['nullable', 'string', 'max:60', $unique('sku')],
            'barcode' => ['nullable', 'string', 'max:100', $unique('barcode')],
            'category_id' => ['nullable', 'integer', $owned('categories')],
            'brand_id' => ['nullable', 'integer', $owned('brands')],
            'description' => 'nullable|string|max:65535',
            'unit' => 'required|string|max:30',
            'purchase_price' => 'nullable|integer|min:0|max:10000000000',
            'selling_price' => 'nullable|integer|min:0|max:10000000000',
            'cost_price' => 'nullable|integer|min:0|max:10000000000',
            'minimum_stock' => 'nullable|integer|min:0|max:100000000',
            'max_stock' => 'nullable|integer|min:0|max:100000000',
            'track_inventory' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Map validated input onto storable attributes — same cost fallback as the web. */
    private function writeAttributes(array $validated): array
    {
        return [
            'name' => $validated['name'],
            'sku' => $validated['sku'] ?? null,
            'barcode' => $validated['barcode'] ?? null,
            'category_id' => $validated['category_id'] ?? null,
            'brand_id' => $validated['brand_id'] ?? null,
            'description' => $validated['description'] ?? null,
            'unit' => $validated['unit'] ?? 'pcs',
            'purchase_price' => (int) ($validated['purchase_price'] ?? 0),
            'selling_price' => (int) ($validated['selling_price'] ?? 0),
            // Cost falls back to the purchase price (reports/AVCO) — as on the web.
            'cost_price' => (int) ($validated['cost_price'] ?? $validated['purchase_price'] ?? 0),
            'minimum_stock' => (int) ($validated['minimum_stock'] ?? 0),
            'max_stock' => isset($validated['max_stock']) ? (int) $validated['max_stock'] : null,
            'track_inventory' => (bool) ($validated['track_inventory'] ?? false),
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ];
    }
}