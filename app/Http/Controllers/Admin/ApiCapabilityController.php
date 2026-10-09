<?php

namespace App\Http\Controllers\Admin;

use App\Models\ApiCapability;
use App\Services\ApiAccessService;
use Illuminate\Http\Request;

/**
 * /admin/api — the GLOBAL API capability matrix (§5–§9).
 *
 * Separate from the workspace's /tokens screen on purpose (§7): this screen decides
 * platform-wide whether a resource is reachable through the API at all and in which
 * direction, while a workspace can only ever pick scopes from what is enabled here.
 *
 * The stored rows are enforced server-side by ApiGuard on every API request — hiding
 * a checkbox or an endpoint in the UI would never be enough (§9).
 */
class ApiCapabilityController extends AdminController
{
    public function index(ApiAccessService $access)
    {
        return view('admin.api.index', [
            'matrix' => $access->matrix(),
        ]);
    }

    public function update(Request $request, ApiAccessService $access)
    {
        $submitted = $request->validate([
            'capabilities' => ['required', 'array'],
            'capabilities.*.read' => ['nullable', 'boolean'],
            'capabilities.*.write' => ['nullable', 'boolean'],
        ])['capabilities'];

        $changes = [];

        // Only registry resources are ever written; a payload naming something else is
        // ignored rather than stored (unknown keys never reach the database).
        foreach ($access->registry() as $resource => $definition) {
            $read = (bool) ($submitted[$resource]['read'] ?? false);
            $write = (bool) ($submitted[$resource]['write'] ?? false);

            // Write implies Read (§6). Enforced here as well as in the UI, so a
            // hand-crafted payload cannot persist "Write on + Read off".
            if ($write) {
                $read = true;
            }

            $row = ApiCapability::query()->firstOrNew(['resource' => $resource]);

            $before = ['read' => (bool) $row->read_enabled, 'write' => (bool) $row->write_enabled];

            $row->fill([
                'read_enabled' => $read,
                // A resource without write endpoints can never be write-enabled.
                'write_enabled' => $write && (bool) ($definition['write'] ?? false),
            ])->save();

            if ($before !== ['read' => $read, 'write' => $row->write_enabled]) {
                $changes[$resource] = ['from' => $before, 'to' => ['read' => $read, 'write' => (bool) $row->write_enabled]];
            }
        }

        $access->flush();

        if ($changes !== []) {
            $this->audit($request, 'admin.api_capabilities_updated', null, ['changes' => $changes]);
        }

        return redirect()->route('admin.api.index')
            ->with('status', ['type' => 'success', 'message' => __('API capabilities saved.')]);
    }
}