<?php

namespace App\Http\Middleware;

use App\Services\ModuleManager;
use Closure;
use Illuminate\Http\Request;

/**
 * Gate a route group behind an active workspace module (`module:<key>`).
 *
 * Enforces AVAILABILITY (workspace level):
 *  - Workspace has no tenant context -> redirect to workspace switcher.
 *  - Module is not installed, or installed but inactive -> 404 / redirect with flash.
 *    Using 404 prevents leaking that a module route even exists for tenants who haven't
 *    activated it, matching the plan.feature middleware pattern.
 *
 * User ACCESS (permission check) is handled by the controller via BusinessAuthorization,
 * keeping the two concerns independent.
 */
class EnsureModuleActive
{
    public function __construct(private readonly ModuleManager $modules) {}

    public function handle(Request $request, Closure $next, string $moduleKey): mixed
    {
        $tenant = app('tenant.context')->tenant();

        if (! $tenant) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Tenant context is required.'], 403);
            }

            return redirect()->route('tenants.index');
        }

        if (! $this->modules->active($moduleKey, $tenant)) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => "Module [{$moduleKey}] is not active for this workspace.",
                    'module'  => $moduleKey,
                ], 403);
            }

            // Redirect back to the modules directory with a friendly banner so the user
            // can activate it, or 403 if they don't have settings access.
            return redirect()->route('modules.index')->with('status', [
                'type'    => 'error',
                'message' => __("The ':module' module is not active for this workspace. Activate it here to continue.", [
                    'module' => $this->modules->find($moduleKey)?->name ?? ucfirst($moduleKey),
                ]),
            ]);
        }

        return $next($request);
    }
}
