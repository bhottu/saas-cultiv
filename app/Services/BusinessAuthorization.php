<?php

namespace App\Services;

/**
 * Business-domain authorization helper.
 *
 * Reuses the existing RBAC registry (config/permissions.php) via the existing Gate
 * infrastructure + TenantContext::userCan(). Business controllers call:
 *
 *     $ctx->authorize('business.can(\'products.create\')');
 *
 * Owner/Admin are allowed all business actions (handled by the existing Gate::before
 * owner bypass and the registry role definitions). Cashier/Staff/Viewer roles are
 * defined in config/business.php 'roles'.
 */
class BusinessAuthorization
{
    public function __construct(private readonly TenantContext $ctx) {}

    /**
     * True when the current user may perform a fine-grained business action.
     *
     * Resolution order:
     *  1. Registry gate for the mapped verb (view_records/create_records/etc.)
     *     — this is the same gate the existing app defines for those permissions.
     *  2. Owner/Admin bypass is handled upstream by Gate::before + registry roles.
     *  3. If no registry gate matches and the user is not owner/admin, fall back to
     *     the fine-grained role list in config/business.php (Cashier/Staff/Viewer).
     */
    public function can(string $action): bool
    {
        $role = $this->ctx->role();

        // 1. Registry verb gate (reuses existing Gate infrastructure).
        if (isset(config('business.action_map')[$action])) {
            $verb = config('business.action_map')[$action];

            // Delegate to the existing registry gate semantics without reconstructing it:
            // the registry gate checks userCan($verb) which covers role entries AND owner '*'.
            if ($verb && $this->ctx->userCan($verb)) {
                return true;
            }
        }

        // 2. Direct role list for fine-grained business actions (Cashier/Staff/Viewer).
        $roleActions = $role ? config("business.roles.{$role}") : null;

        if (is_array($roleActions) && in_array($action, $roleActions, true)) {
            return true;
        }

        return false;
    }

    /**
     * Convenience: abort unless the current user can perform $action.
     * Uses the existing 403 messaging style.
     */
    public function authorize(string $action): void
    {
        abort_unless($this->can($action), 403, "Missing permission: {$action}.");
    }
}