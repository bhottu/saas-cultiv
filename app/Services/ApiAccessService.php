<?php

namespace App\Services;

use App\Models\ApiCapability;

/**
 * The single resolver of EFFECTIVE API permission.
 *
 *     effective = plan entitlement  ∩  admin capability  ∩  token scope  ∩  workspace
 *
 * Everything about "can this request touch this resource" flows through here so no
 * controller, middleware or view can re-invent a different rule:
 *
 *  - `allows()`        — admin capability layer (/admin/api), applied per request.
 *  - `availableScopes()` — the SAME capability layer projected into the scope list a
 *                          workspace may grant a token, so a scope the platform does
 *                          not offer can never be minted.
 *  - `supports()`      — whether the resource/operation exists at all in this build.
 *
 * Write always requires Read (§6): `allows(resource, 'write')` is false unless both
 * toggles are on, so the illegal "Read off + Write on" state cannot exist even if a
 * row were written out-of-band.
 */
class ApiAccessService
{
    /** @var array<string, ApiCapability> Request-local cache. */
    private array $loaded = [];

    /** The resource registry from config/api.php. */
    public function registry(): array
    {
        return (array) config('api.resources', []);
    }

    /** Whether an operation exists at all for a resource in this build. */
    public function supports(string $resource, string $operation): bool
    {
        if (! isset($this->registry()[$resource])) {
            return false;
        }

        return $operation === 'read'
            || (bool) ($this->registry()[$resource]['write'] ?? false);
    }

    /**
     * Current platform capability row for a resource.
     *
     * Falls back to the configured default when no row exists yet (a database migrated
     * before the registry grew a resource), so behaviour is defined without requiring
     * a seeder run — and identical to what the migration would have inserted.
     */
    public function capability(string $resource): ApiCapability
    {
        if (isset($this->loaded[$resource])) {
            return $this->loaded[$resource];
        }

        $row = ApiCapability::query()->where('resource', $resource)->first();

        if (! $row) {
            $row = new ApiCapability([
                'resource' => $resource,
                'read_enabled' => true,
                'write_enabled' => (bool) ($this->registry()[$resource]['write'] ?? false),
            ]);
        }

        return $this->loaded[$resource] = $row;
    }

    /**
     * Admin capability check for one operation. This is the server-side gate: hiding a
     * checkbox or an endpoint in the UI is never enough (§9).
     */
    public function allows(string $resource, string $operation): bool
    {
        if (! $this->supports($resource, $operation)) {
            return false;
        }

        $capability = $this->capability($resource);

        if ($operation === 'write') {
            // Write implies Read (§6).
            return $capability->write_enabled && $capability->read_enabled;
        }

        return (bool) $capability->read_enabled;
    }

    /**
     * Scopes a workspace is allowed to grant a token right now (§11).
     *
     * Derived from the live capability rows, never hard-coded: turning a capability off
     * on /admin/api immediately removes the matching scope from every token-creation
     * form, and `EnforceApiAccess` denies it on already-issued tokens.
     *
     * @return array<int, string>
     */
    public function availableScopes(): array
    {
        $scopes = [];

        foreach (array_keys($this->registry()) as $resource) {
            if ($this->allows($resource, 'read')) {
                $scopes[] = $resource.':read';
            }

            if ($this->allows($resource, 'write')) {
                $scopes[] = $resource.':write';
            }
        }

        return $scopes;
    }

    /** Every resource with its current state — for /admin/api and the token form. */
    public function matrix(): array
    {
        $rows = [];

        foreach ($this->registry() as $resource => $definition) {
            $capability = $this->capability($resource);

            $rows[] = [
                'resource' => $resource,
                'label' => (string) ($definition['label'] ?? $resource),
                'write_supported' => (bool) ($definition['write'] ?? false),
                'read_enabled' => (bool) $capability->read_enabled,
                'write_enabled' => (bool) $capability->write_enabled,
            ];
        }

        return $rows;
    }

    /** Forget request-local state (used after an admin saves new capabilities). */
    public function flush(): void
    {
        $this->loaded = [];
    }
}
