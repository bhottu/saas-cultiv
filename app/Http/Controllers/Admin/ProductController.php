<?php

namespace App\Http\Controllers\Admin;

use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Http\Request;

class ProductController extends AdminController
{
    public function index(Request $request)
    {
        $query = $this->acrossTenants(Product::class)
            ->with(['tenant:id,name', 'category:id,name', 'brand:id,name'])
            ->withSum('stockBalances', 'quantity');

        if (($term = $request->string('search')->toString()) !== '') {
            // Shared case-insensitive scope — the admin panel searches the same
            // name/sku/barcode fields the tenant product index does.
            $query->search($term);
        }

        foreach (['tenant_id' => 'tenant_id', 'category_id' => 'category_id', 'brand_id' => 'brand_id'] as $param => $column) {
            if ($this->idFilter($request, $param)) {
                $query->where($column, $this->idFilter($request, $param));
            }
        }

        if (($active = $request->boolean('active_only')) || $request->boolean('inactive_only')) {
            $query->where('is_active', $request->boolean('inactive_only') ? false : true);
        }

        return view('admin.products.index', [
            'products' => $this->paginate($query->orderBy('name')->orderBy('id'), $request),
            'filters' => $request->only(['search', 'tenant_id', 'category_id', 'brand_id', 'active_only', 'inactive_only']),
            'workspaces' => Tenant::orderBy('name')->get(['id', 'name']),
            'categories' => $this->acrossTenants(\App\Models\Category::class)->orderBy('name')->get(['id', 'name', 'tenant_id']),
            'brands' => $this->acrossTenants(\App\Models\Brand::class)->orderBy('name')->get(['id', 'name', 'tenant_id']),
        ]);
    }
}