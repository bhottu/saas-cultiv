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
 * Operations are exactly `read`, `write` and `delete`, matching config/api.php.
 *
 * Dependencies between operations (§6): a stricter operation implies the weaker ones,
 * so `allows(resource, 'delete')` is false unless Read and Write are both on as well.
 * The illegal "Read off + Delete on" state therefore cannot exist even if a row were
 * written out-of-band.
 */
class ApiAccessService
{
    /** Every operation this build understands, weakest first. */
    public const OPERATIONS = ['read', 'write', 'delete'];

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
        $definition = $this->registry()[$resource] ?? null;

        if (! is_array($definition)) {
            return false;
        }

        // Read is inherent to being registered; write/delete must be declared, and an
        // undeclared operation defaults to false rather than to "allowed".
        return match ($operation) {
            'read' => true,
            'write' => (bool) ($definition['write'] ?? false),
            'delete' => (bool) ($definition['delete'] ?? false),
            default => false,
        };
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
                'delete_enabled' => (bool) ($this->registry()[$resource]['delete'] ?? false),
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

        // An operation is only ever granted when every weaker operation is on too.
        foreach (self::OPERATIONS as $candidate) {
            $required = $this->supports($resource, $candidate);

            if ($candidate === 'read') {
                $enabled = (bool) $capability->read_enabled;
            } elseif ($candidate === 'write') {
                $enabled = (bool) $capability->write_enabled;
            } else {
                $enabled = (bool) $capability->delete_enabled;
            }

            if ($required && ! $enabled) {
                return false;
            }

            // Operations weaker than the one being asked about are irrelevant.
            if ($candidate === $operation) {
                break;
            }
        }

        return true;
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
            foreach (self::OPERATIONS as $operation) {
                if ($this->allows($resource, $operation)) {
                    $scopes[] = "{$resource}:{$operation}";
                }
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

            $writeSupported = (bool) ($definition['write'] ?? false);
            $deleteSupported = (bool) ($definition['delete'] ?? false);

            $rows[] = [
                'resource' => $resource,
                'label' => (string) ($definition['label'] ?? $resource),
                'write_supported' => $writeSupported,
                'delete_supported' => $deleteSupported,
                'read_enabled' => (bool) $capability->read_enabled,
                'write_enabled' => (bool) $capability->write_enabled && (bool) $capability->read_enabled,
                'delete_enabled' => (bool) $capability->delete_enabled
                    && (bool) $capability->write_enabled
                    && (bool) $capability->read_enabled,
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
