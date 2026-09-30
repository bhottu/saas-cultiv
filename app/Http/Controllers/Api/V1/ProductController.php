<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;

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
}