<?php

namespace App\Http\Middleware;

use App\Services\ActiveSchoolContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveSchoolContext
{
    public function __construct(
        protected ActiveSchoolContext $context
    ) {}

    /**
     * Handle an incoming request.
     * Enforces that an active school context is established before entering /school/* routes.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        // Enable tenant operational scope for the duration of this request
        $this->context->setTenantOperationalScope(true);

        // For Super Admin: Fail-closed if no valid active school context is selected
        if ($user->hasRole('super-admin')) {
            if (! $this->context->hasActiveSchool()) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'No active school context selected. Please select a school to manage.',
                    ], 403);
                }

                return redirect()
                    ->route('super-admin.schools.index')
                    ->with('warning', 'Please select an active school context before accessing school operations.');
            }
        } else {
            // For normal tenant users: Must possess a valid school_id
            if (! $user->school_id) {
                abort(403, 'User is not associated with any active school.');
            }

            $hostSchool = $this->context->getHostResolvedSchool();
            if ($hostSchool && (int) $user->school_id !== (int) $hostSchool->id) {
                abort(403, 'User is not authorized for this school domain.');
            }
        }

        return $next($request);
    }
}
