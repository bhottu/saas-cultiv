<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Brand;
use App\Models\StockMovement;
use App\Models\Product;
use App\Services\BusinessAuthorization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockController extends Controller
{
    public function __construct(private readonly BusinessAuthorization $auth) {}

    public function index(Request $request)
    {
        $this->auth->authorize('inventory.view');

        $tenant = $request->user()->currentTenant;

        $query = Product::with(['category', 'brand'])
            ->where('track_inventory', true)
            ->where('is_active', true);

        if ($request->filled('low_stock')) {
            $query->where(function ($q) {
                $q->where('min_stock', '<=', 'min_stock');
            });
        }

        $products = $query->paginate(50);

        $warehouse = $tenant->warehouses()->active()->first();

        return view('stock.index', [
            'products' => $products,
            'warehouse' => $warehouse,
            'lowStockCount' => Product::withoutGlobalScopes()
                ->whereIn('id', $products->pluck('id'))
                ->where('min_stock', '<=', 'min_stock')
                ->count(),
        ]);
    }
}
