<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Services\AuditLogger;
use App\Services\BusinessAuthorization;
use App\Services\ModuleManager;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ModulesController extends Controller
{
    public function __construct(
        private readonly ModuleManager $manager,
        private readonly TenantContext $ctx,
        private readonly BusinessAuthorization $auth,
    ) {}

    public function index(Request $request): View
    {
        $this->auth->authorize('modules.view');
        $tenant = $this->ctx->tenant();

        $modules = $this->manager->catalog();
        $installs = $this->manager->installs($tenant);

        $planGates = [];
        foreach ($modules as $module) {
            $planGates[$module->key] = $this->manager->planGate($module, $tenant);
        }

        return view('modules.index', [
            'modules'   => $modules,
            'installs'  => $installs,
            'planGates' => $planGates,
            'canManage' => $this->auth->can('modules.manage'),
            'tenant'    => $tenant,
        ]);
    }

    public function show(Module $module): View
    {
        $this->auth->authorize('modules.view');
        abort_if($module->availability_status === Module::STATUS_HIDDEN, 404);
        $tenant = $this->ctx->tenant();

        return view('modules.show', [
            'module'    => $module,
            'install'   => $this->manager->installs($tenant)->get($module->key),
            'planGate'  => $this->manager->planGate($module, $tenant),
            'canManage' => $this->auth->can('modules.manage'),
            'tenant'    => $tenant,
        ]);
    }

    public function install(Module $module): RedirectResponse
    {
        $this->auth->authorize('modules.manage');
        $tenant = $this->ctx->tenant();

        if ($reason = $this->manager->planGate($module, $tenant)) {
            return back()->with('status', ['type' => 'error', 'message' => $reason]);
        }

        if ($module->isPaid()) {
            return back()->with('status', [
                'type'    => 'error',
                'message' => __('Paid modules require checkout. Direct installation is disabled.'),
            ]);
        }

        try {
            $install = $this->manager->record($module, $tenant);
        } catch (\RuntimeException $e) {
            return back()->with('status', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        AuditLogger::log('module.installed', $install, [
            'module_key'  => $module->key,
            'module_name' => $module->name,
        ]);

        return back()->with('status', [
            'type'    => 'success',
            'message' => __("Module ':name' installed.", ['name' => $module->name]),
        ]);
    }

    public function activate(Module $module): RedirectResponse
    {
        $this->auth->authorize('modules.manage');
        $tenant = $this->ctx->tenant();

        if ($reason = $this->manager->planGate($module, $tenant)) {
            return back()->with('status', ['type' => 'error', 'message' => $reason]);
        }

        if ($module->isPaid()) {
            return back()->with('status', [
                'type'    => 'error',
                'message' => __('Paid modules require an active subscription before activation.'),
            ]);
        }

        try {
            $install = $this->manager->activate($module, $tenant);
        } catch (\RuntimeException $e) {
            return back()->with('status', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        AuditLogger::log('module.activated', $install, [
            'module_key'  => $module->key,
            'module_name' => $module->name,
        ]);

        return back()->with('status', [
            'type'    => 'success',
            'message' => __("Module ':name' is now active.", ['name' => $module->name]),
        ]);
    }

    public function deactivate(Module $module): RedirectResponse
    {
        $this->auth->authorize('modules.manage');
        $tenant = $this->ctx->tenant();

        if ($module->is_core) {
            return back()->with('status', [
                'type'    => 'error',
                'message' => __('Core modules cannot be deactivated.'),
            ]);
        }

        try {
            $install = $this->manager->deactivate($module, $tenant);
        } catch (\RuntimeException $e) {
            return back()->with('status', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        AuditLogger::log('module.deactivated', $install, [
            'module_key'  => $module->key,
            'module_name' => $module->name,
        ]);

        return back()->with('status', [
            'type'    => 'success',
            'message' => __("Module ':name' has been deactivated.", ['name' => $module->name]),
        ]);
    }

    public function uninstall(Module $module): RedirectResponse
    {
        $this->auth->authorize('modules.manage');
        $tenant = $this->ctx->tenant();

        if ($module->is_core) {
            return back()->with('status', [
                'type'    => 'error',
                'message' => __('Core modules cannot be uninstalled.'),
            ]);
        }

        try {
            $this->manager->uninstall($module, $tenant);
        } catch (\RuntimeException $e) {
            return back()->with('status', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        AuditLogger::log('module.uninstalled', $module, [
            'module_key'  => $module->key,
            'module_name' => $module->name,
        ]);

        return back()->with('status', [
            'type'    => 'success',
            'message' => __("Module ':name' uninstalled from this workspace.", ['name' => $module->name]),
        ]);
    }
}
