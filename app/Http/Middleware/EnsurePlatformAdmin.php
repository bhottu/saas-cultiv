<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Platform-level admin gate (distinct from tenant roles). */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->is_platform_admin, 403, 'Platform admin only.');

        return $next($request);
    }
}
