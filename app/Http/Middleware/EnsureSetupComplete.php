<?php

namespace App\Http\Middleware;

use App\Support\Setup;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * First-run gate. Until the installation wizard is finished (no admin exists
 * and the `setup` flag is unset), every request is funnelled to the wizard so a
 * fresh install can't be used half-configured. Once complete this is a no-op.
 *
 * Prepended to the web group, so it runs before auth — the wizard is reachable
 * by an unauthenticated operator. The wizard's own routes (named `setup.*`) are
 * exempt to avoid a redirect loop.
 */
class EnsureSetupComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Setup::isComplete() || $request->routeIs('setup.*')) {
            return $next($request);
        }

        return redirect()->route('setup.show');
    }
}
