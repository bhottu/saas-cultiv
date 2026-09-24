<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Models\Brand;
use App\Services\BusinessAuthorization;
use Illuminate\Http\Request;

class BrandsController extends Controller
{
    public function __construct(private readonly BusinessAuthorization $auth) {}

    public function index(Request $request)
    {
        $this->auth->authorize('brands.view');

        $tenant = $request->user()->currentTenant;

        $query = Brand::where('tenant_id', $tenant->id);

        if ($request->filled('search')) {
            $term = $request->getString('search');
            $query->where('name', 'like', "%{$term}%");
        }

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        if ($request->boolean('show_inactive')) {
            $query->where('is_active', false);
        }

        $brands = $query->orderBy('name')->paginate(50);

        return view('brands.index', [
            'brands' => $brands,
            'search' => $request->getString('search', ''),
        ]);
    }

    public function create()
    {
        $this->auth->authorize('brands.create');

        return view('brands.form', [
            'brand' => new Brand(),
            'pageTitle' => 'Add Brand',
        ]);
    }

    public function store(Request $request)
    {
        $this->auth->authorize('brands.create');

        $tenantId = $this->tenantId($request);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:brands,slug,tenant_id,' . $tenantId,
            'description' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        $brand = Brand::create(array_merge($validated, ['tenant_id' => $tenantId]));

        AuditLogger::log('brand.created', $brand, ['name' => $brand->name]);

        return redirect()->route('brands.index')->with('status', [
            'type' => 'success',
            'message' => 'Brand created.',
        ]);
    }

    public function edit(Brand $brand)
    {
        $this->auth->authorize('brands.update');
        $this->ensureOwned($brand);

        return view('brands.form', [
            'brand' => $brand,
            'pageTitle' => 'Edit Brand',
        ]);
    }

    public function update(Request $request, Brand $brand)
    {
        $this->auth->authorize('brands.update');
        $this->ensureOwned($brand);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:brands,slug,tenant_id,' . $this->tenantId($request) . ',id,' . $brand->id,
            'description' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        $brand->update($validated);
        AuditLogger::log('brand.updated', $brand, ['name' => $brand->name]);

        return redirect()->route('brands.index')->with('status', [
            'type' => 'success',
            'message' => 'Brand updated.',
        ]);
    }

    public function destroy(Request $request, Brand $brand)
    {
        $this->auth->authorize('brands.delete');
        $this->ensureOwned($brand);

        if ($brand->products()->exists()) {
            return back()->with('status', [
                'type' => 'error',
                'message' => 'Cannot delete a brand with products.',
            ]);
        }

        AuditLogger::log('brand.deleted', $brand, ['name' => $brand->name]);
        $brand->delete();

        return redirect()->route('brands.index')->with('status', [
            'type' => 'success',
            'message' => 'Brand deleted.',
        ]);
    }

    private function tenantId(Request $request): int
    {
        return $request->user()->currentTenant->id;
    }

    private function ensureOwned(Brand $brand): void
    {
        // Never trust the route id: compare against the *active* tenant context.
        if (! $brand->tenant_id || $brand->tenant_id !== app('tenant.context')->tenant()?->id) {
            abort(404);
        }
    }
}
