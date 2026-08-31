<?php

namespace App\Http\Middleware;

use App\Services\SchoolEntitlementResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureSchoolModule
{
    public function __construct(
        protected SchoolEntitlementResolver $resolver
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $mode = strtolower(config('entitlement.mode', 'off'));

        // 1. OFF Mode: Transparent pass-through
        if ($mode === 'off') {
            return $next($request);
        }

        $user = $request->user();

        // Resolve school ID from user or session or route
        $schoolId = $user?->school_id;

        // Resolve entitlement
        $result = $this->resolver->canAccessModule($user, $schoolId, $module);

        // If entitled, continue immediately
        if ($result->isEntitled) {
            return $next($request);
        }

        // 2. OBSERVE Mode: Log would-be denial and permit passage
        if ($mode === 'observe') {
            $logChannel = config('entitlement.log_channel');
            $logger = $logChannel ? Log::channel($logChannel) : Log::getLogger();

            Log::warning("[ENTITLEMENT OBSERVE] Would-be module denial observed", [
                'school_id'       => $result->schoolId ?? $schoolId,
                'user_id'         => $user?->id,
                'module'          => $module,
                'route_name'      => $request->route()?->getName(),
                'method'          => $request->method(),
                'uri'             => $request->path(),
                'reason_code'     => $result->reason,
                'subscription_id' => $result->subscriptionId,
                'package_id'      => $result->packageId,
                'diagnosis'       => $result->message,
            ]);

            return $next($request);
        }

        // 3. ENFORCE Mode: Fail-closed with HTTP 403
        if ($mode === 'enforce') {
            if ($request->expectsJson() || $request->header('X-Inertia')) {
                abort(403, "Access to module '{$module}' is not active on your school plan ({$result->reason}).");
            }

            abort(403, "Access to module '{$module}' is not active on your school plan.");
        }

        // Default fallback: pass through
        return $next($request);
    }
}
