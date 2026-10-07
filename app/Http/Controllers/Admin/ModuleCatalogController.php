<?php

namespace App\Http\Controllers\Admin;

use App\Models\Module;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ModuleCatalogController extends AdminController
{
    public function index()
    {
        return view('admin.modules.index', [
            'modules' => Module::query()->ordered()->get(),
            'statuses' => Module::AVAILABILITY_STATUSES,
        ]);
    }

    public function update(Request $request, Module $module)
    {
        $data = $request->validate([
            'availability_status' => ['required', Rule::in(Module::AVAILABILITY_STATUSES)],
        ]);

        $module->forceFill([
            'availability_status' => $data['availability_status'],
            'is_active' => $data['availability_status'] === Module::STATUS_ACTIVE,
        ])->save();

        $this->audit($request, 'module.catalog_status_updated', null, [
            'module_key' => $module->key,
            'module_id' => $module->id,
            'availability_status' => $module->availability_status,
        ]);

        return redirect()->route('admin.modules.index')
            ->with('status', ['type' => 'success', 'message' => __('Module status saved.')]);
    }
}
