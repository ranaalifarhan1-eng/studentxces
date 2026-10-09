<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordIsChanged
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password) {
            // Allow logout and password change routes to prevent redirect loops
            if (! $request->routeIs('password.first-change*') && ! $request->routeIs('logout')) {
                return redirect()->route('password.first-change');
            }
        }

        return $next($request);
    }
}
