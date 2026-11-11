<?php

namespace Cyberwizard\RuntimeManager\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the dashboard with the standalone password session (independent of the
 * application's own auth) and an optional IP allowlist.
 */
class DashboardAuth
{
    public const SESSION_KEY = 'runtime-manager.authed';

    public function handle(Request $request, Closure $next): Response
    {
        $allow = (array) config('runtime-manager.dashboard.ip_allowlist', []);
        if ($allow !== [] && ! in_array($request->ip(), $allow, true)) {
            abort(403, 'Runtime dashboard: IP not allowed.');
        }

        if ($request->session()->get(self::SESSION_KEY) !== true) {
            return redirect()->route('runtime.login');
        }

        return $next($request);
    }
}
