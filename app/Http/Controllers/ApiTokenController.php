<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use Illuminate\Http\Request;

/** Sanctum personal access tokens — hashed at rest by Sanctum, revocable, scoped (§25). */
class ApiTokenController extends Controller
{
    public function index(Request $request)
    {
        $tenant = app('tenant.context')->tenant();

        if (! $tenant) {
            return redirect()->route('tenants.index');
        }

        $usage = app(\App\Services\UsageService::class);

        return view('tokens.index', [
            'tokens' => $request->user()->tokens()->get(['id', 'name', 'last_used_at', 'created_at']),
            'apiEnabled' => $usage->allows($tenant, 'api_access'),
        ]);
    }

    public function store(Request $request)
    {
        $tenant = app('tenant.context')->tenant();

        if (! $tenant) {
            return redirect()->route('tenants.index');
        }

        app(\App\Services\UsageService::class)->enforceFeature($tenant, 'api_access');

        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);

        $token = $request->user()->createToken($data['name']); // abilities: ['*'] by default

        AuditLogger::log('api_token.created', null, ['name' => $data['name']]);

        // Plain text shown exactly once — only the SHA-256 hash is stored.
        return back()->with('plainTextToken', $token->plainTextToken)
            ->with('success', 'Token created — copy it now, it will not be shown again.');
    }

    public function destroy(Request $request, $tokenId)
    {
        if (! app('tenant.context')->tenant()) {
            return redirect()->route('tenants.index');
        }

        $request->user()->tokens()->where('id', $tokenId)->firstOrFail()->delete();

        AuditLogger::log('api_token.revoked', null, ['token_id' => (int) $tokenId]);

        return back()->with('success', 'Token revoked.');
    }
}
