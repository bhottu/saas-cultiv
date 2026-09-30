<?php

namespace App\Http\Controllers\Admin;

use App\Models\StockBalance;
use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Http\Request;

/**
 * Cross-workspace inventory explorer. It reuses the existing warehouse → product →
 * balance relationship; no second inventory model or query path is introduced.
 */
class InventoryController extends AdminController
{
    public function index(Request $request)
    {
        $query = $this->acrossTenants(StockBalance::class)
            ->with(['warehouse:id,name,code,tenant_id', 'product:id,name,sku,unit,tenant_id']);

        if (($term = $request->string('search')->toString()) !== '') {
            $query->whereHas('product', fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('sku', 'like', "%{$term}%"));
        }

        if ($this->idFilter($request, 'tenant_id')) {
            $query->where('stock_balances.tenant_id', $this->idFilter($request, 'tenant_id'));
        }

        if ($this->idFilter($request, 'warehouse_id')) {
            $query->where('warehouse_id', $this->idFilter($request, 'warehouse_id'));
        }

        if ($request->boolean('in_stock')) {
            $query->where('quantity', '>', 0);
        }

        if ($request->boolean('low_stock')) {
            // Compared against each product's own threshold, not a fixed number.
            $query->whereHas('product', fn ($q) => $q->whereColumn(
                'stock_balances.quantity', '<=', 'products.minimum_stock'
            ));
        }

        return view('admin.inventory.index', [
            'balances' => $this->paginate($query->orderBy('warehouse_id')->orderBy('product_id'), $request),
            'filters' => $request->only(['search', 'tenant_id', 'warehouse_id', 'in_stock', 'low_stock']),
            'workspaces' => Tenant::orderBy('name')->get(['id', 'name']),
            'warehouses' => $this->acrossTenants(Warehouse::class)->orderBy('name')->get(['id', 'name', 'tenant_id', 'code']),
        ]);
    }
}