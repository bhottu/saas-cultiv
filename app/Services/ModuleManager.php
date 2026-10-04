<?php

namespace App\Services;

use App\Models\Module;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantModule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * The ONE place that answers "does this workspace have this module?".
 *
 * Responsibilities:
 *  - read the platform catalogue (`modules`)
 *  - read/flip the workspace install state (`tenant_modules`) for the ACTIVE tenant only
 *  - expose the workspace-side plan gate (`min_plan`) and the user-side permission gate
 *    (the existing BusinessAuthorization registry — no second RBAC)
 *  - build the dynamic sidebar entries so Blade never hard-codes a module link
 *
 * Deliberately distinct concepts:
 *  - AVAILABILITY (workspace): is the module installed/active for this tenant?
 *  - ACCESS (user): may this user use the module? (module permission + plan)
 *  Both are checked independently; neither implies the other.
 *
 * Tenant isolation: every read/write goes through TenantModule (BelongsToTenant) so the
 * existing global scope restricts rows to the active tenant. `$tenant` defaults to the
 * tenant context; operating on a foreign tenant is intentionally not supported.
 */
class ModuleManager
{
    public function __construct(private readonly TenantContext $ctx) {}

    // ------------------------------------------------------------------ catalogue

    /** The whole catalogue, ordered. Inactive modules are included for completeness. */
    public function catalog(): Collection
    {
        return Module::query()->ordered()->get();
    }

    /** Modules the marketplace may offer right now. */
    public function offered(): Collection
    {
        return Module::query()->available()->ordered()->get();
    }

    public function find(string $key): ?Module
    {
        return Module::query()->where('key', $key)->first();
    }

    public function findOrFail(string $key): Module
    {
        return Module::query()->where('key', $key)->firstOrFail();
    }

    /**
     * Code-side manifest for a module (config/modules.php).
     *
     * The manifest is UI/routing metadata only: state always comes from the database, so
     * a catalogue row added from the platform side works with an empty manifest.
     *
     * @return array<string, mixed>
     */
    public function manifest(string $key): array
    {
        return (array) config("modules.manifests.{$key}", []);
    }

    // ------------------------------------------------------------------ workspace state

    /**
     * Every install of the active workspace keyed by module key.
     *
     * Deliberately NOT memoised. An earlier version cached this per tenant, which was
     * wrong in two ways: the cached list went stale the moment a module was activated
     * by any path that did not run through this service (another process, a seeder, a
     * console command, a queued job), and a request-scoped container is not reliably
     * rebuilt between calls in every runtime. A silently stale list is far more
     * expensive than the one indexed query it saved: it would hide an activated
     * module and keep sending the user to the Module Center.
     *
     * @return Collection<string, TenantModule>
     */
    public function installs(?Tenant $tenant = null): Collection
    {
        $tenant ??= $this->ctx->tenant();

        if (! $tenant) {
            return collect();
        }

        return TenantModule::query()
            ->where('tenant_id', $tenant->id)
            ->with('module')
            ->get()
            ->filter(fn (TenantModule $row) => $row->module !== null)
            ->keyBy(fn (TenantModule $row) => $row->module->key);
    }

    public function installed(string $key, ?Tenant $tenant = null): bool
    {
        return $this->installs($tenant)->get($key)?->isInstalled() ?? false;
    }

    /** Active = installed AND switched on. This is what the route middleware enforces. */
    public function active(string $key, ?Tenant $tenant = null): bool
    {
        return $this->installs($tenant)->get($key)?->isActive() ?? false;
    }

    public function status(string $key, ?Tenant $tenant = null): ?string
    {
        return $this->installs($tenant)->get($key)?->status;
    }

    // ------------------------------------------------------------------ mutations

    /**
     * Add the module to the workspace (status "installed" — added, not yet usable).
     *
     * Idempotent by design: firstOrCreate plus the unique(tenant_id, module_id) index
     * means a double-clicked Install can never create a second row.
     */
    public function record(Module $module, ?Tenant $tenant = null): TenantModule
    {
        $tenant = $this->requireTenant($tenant);

        $install = TenantModule::query()
            ->where('tenant_id', $tenant->id)
            ->where('module_id', $module->id)
            ->first();

        if ($install) {
            return $install;
        }

        return TenantModule::create([
            'tenant_id' => $tenant->id,
            'module_id' => $module->id,
            'status' => TenantModule::STATUS_INSTALLED,
            'installed_at' => now(),
        ]);
    }

    /** Turn the module on (installing it first when it is not installed yet). */
    public function activate(Module $module, ?Tenant $tenant = null): TenantModule
    {
        $tenant = $this->requireTenant($tenant);
        $install = $this->record($module, $tenant);

        if (! $install->isActive()) {
            $install->forceFill([
                'status'         => TenantModule::STATUS_ACTIVE,
                'activated_at'   => now(),
                'deactivated_at' => null,
            ])->save();
        }


        return $install->refresh();
    }

    /** Turn the module off without deleting the install. Core modules cannot be switched off. */
    public function deactivate(Module $module, ?Tenant $tenant = null): TenantModule
    {
        $tenant = $this->requireTenant($tenant);

        if ($module->is_core) {
            throw new \RuntimeException(__('Core modules cannot be deactivated.'));
        }

        $install = $this->record($module, $tenant);

        $install->forceFill([
            'status'         => TenantModule::STATUS_INACTIVE,
            'deactivated_at' => now(),
        ])->save();


        return $install->refresh();
    }

    /**
     * Remove the workspace install entirely.
     *
     * Only the install row is deleted: the module definition stays in the platform
     * catalogue and NO business data (sales, stock movements) is ever removed.
     */
    public function uninstall(Module $module, ?Tenant $tenant = null): void
    {
        $tenant = $this->requireTenant($tenant);

        if ($module->is_core) {
            throw new \RuntimeException(__('Core modules cannot be uninstalled.'));
        }

        TenantModule::query()
            ->where('tenant_id', $tenant->id)
            ->where('module_id', $module->id)
            ->delete();

    }

    private function requireTenant(?Tenant $tenant): Tenant
    {
        $tenant ??= $this->ctx->tenant();

        if (! $tenant) {
            throw new \RuntimeException('A module action requires an active workspace context.');
        }

        return $tenant;
    }

    // ------------------------------------------------------------------ gates

    /**
     * Plan gate: null = allowed, string = why the workspace's plan blocks the module.
     *
     * `modules.min_plan` is a plan slug; the comparison uses plans.sort_order (higher =
     * higher tier, as seeded) so the rule is data-driven rather than a hard-coded list.
     */
    public function planGate(Module $module, ?Tenant $tenant = null): ?string
    {
        $minPlanSlug = $module->min_plan;

        if (! $minPlanSlug) {
            return null;
        }

        $tenant ??= $this->ctx->tenant();
        $required = Plan::query()->where('slug', $minPlanSlug)->first();

        if (! $required) {
            return __('This module is not available.');
        }

        $plan = $this->currentPlan($tenant);

        if ($plan && ($plan->slug === $required->slug
            || (int) $plan->sort_order >= (int) $required->sort_order)) {
            return null;
        }

        return __('This module requires the :plan plan or higher.', ['plan' => $required->name]);
    }

    /**
     * Access gate for one user: the module must be ACTIVE for the workspace AND the user
     * must hold the module's business permission.
     *
     * Availability (workspace) and access (user) stay separate checks; this method is the
     * only place they are combined and it never decides whether a module may be installed.
     */
    public function allows(string $key, ?Tenant $tenant = null): bool
    {
        if (! $this->active($key, $tenant)) {
            return false;
        }

        $permission = $this->manifest($key)['permission'] ?? $this->find($key)?->permission;

        return ! $permission || app(BusinessAuthorization::class)->can($permission);
    }

    private function currentPlan(?Tenant $tenant): ?Plan
    {
        if (! $tenant) {
            return null;
        }

        return $tenant->effectivePlan()
            ?? Plan::query()->where('is_free_tier', true)->orderBy('sort_order')->first();
    }

    // ------------------------------------------------------------------ navigation

    /**
     * Dynamic sidebar entries contributed by the active workspace's modules.
     *
     * A module is linked only when ALL of these hold:
     *  1. its install is ACTIVE for this workspace,
     *  2. its entry route actually exists in this build (never produces a dead link),
     *  3. the current user holds the module's business permission,
     *  4. the workspace's plan allows it.
     *
     * @return array<int, array{label:string, icon:string, href:string, active:bool, group:string}>
     */
    public function sidebarItems(?Tenant $tenant = null): array
    {
        $tenant ??= $this->ctx->tenant();

        if (! $tenant) {
            return [];
        }

        $business = app(BusinessAuthorization::class);
        $items = [];

        foreach ($this->installs($tenant) as $key => $install) {
            if (! $install->isActive()) {
                continue;
            }

            $manifest = $this->manifest($key);
            $definition = $install->module;

            $routeName = $manifest['route'] ?? $definition->route;

            if (! $routeName || ! Route::has($routeName)) {
                continue;
            }

            if ($this->planGate($definition, $tenant) !== null) {
                continue;
            }

            $permission = $manifest['permission'] ?? $definition->permission;

            if ($permission && ! $business->can($permission)) {
                continue;
            }

            $items[] = [
                'label'  => __('labels.module.'.($manifest['name'] ?? $definition->name)),
                'icon'   => $manifest['icon'] ?? $definition->icon ?? 'cube',
                'href'   => route($routeName),
                'active' => request()->routeIs($manifest['active'] ?? $this->activePattern($routeName)),
                'group'  => $manifest['sidebar_group'] ?? $definition->sidebar_group ?? 'Modules',
            ];
        }

        return $items;
    }

    private function activePattern(string $routeName): string
    {
        $prefix = str_contains($routeName, '.') ? substr($routeName, 0, (int) strrpos($routeName, '.')) : $routeName;

        return $prefix.'.*';
    }

}
