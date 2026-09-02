<?php

namespace App\Http\Middleware;

use App\Models\SchoolSubscription;
use App\Services\ActiveSchoolContext;
use Carbon\Carbon;
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
     * Enforces active school context and validates subscription access state before entering /school/* routes.
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
        // Super Admin has unrestricted platform management access and can inspect/manage suspended schools
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

            // Super Admin accessing notice route redirects to school operational dashboard
            if ($request->routeIs('school.subscription.notice')) {
                return redirect()->route('school.reports.dashboard');
            }

            return $next($request);
        }

        // For normal tenant users: Must possess a valid school_id
        if (! $user->school_id) {
            abort(403, 'User is not associated with any active school.');
        }

        $hostSchool = $this->context->getHostResolvedSchool();
        if ($hostSchool && (int) $user->school_id !== (int) $hostSchool->id) {
            abort(403, 'User is not authorized for this school domain.');
        }

        // Evaluate school subscription access state (independent of module entitlements)
        $schoolId = (int) $user->school_id;
        $today = Carbon::today()->toDateString();
        $subscription = SchoolSubscription::where('school_id', $schoolId)->latest('id')->first();

        $isSubscriptionActive = false;
        if ($subscription) {
            if ($subscription->status === 'active') {
                $startValid = empty($subscription->start_date) || $subscription->start_date->toDateString() <= $today;
                $endValid   = empty($subscription->end_date) || $subscription->end_date->toDateString() >= $today;
                $isSubscriptionActive = $startValid && $endValid;
            } elseif ($subscription->status === 'trial' || $subscription->is_trial) {
                $trialValid = ! empty($subscription->trial_ends_at) && $subscription->trial_ends_at->toDateString() >= $today;
                $isSubscriptionActive = $trialValid;
            }
        }

        // If the request is for the subscription notice page
        if ($request->routeIs('school.subscription.notice')) {
            if ($isSubscriptionActive) {
                return redirect()->route('school.reports.dashboard');
            }

            return $next($request);
        }

        // If subscription is suspended / expired / inactive, block operational access
        if (! $isSubscriptionActive) {
            if ($request->expectsJson() || $request->header('X-Inertia')) {
                if ($request->header('X-Inertia')) {
                    return redirect()->route('school.subscription.notice');
                }

                return response()->json([
                    'message'             => 'Subscription Access Suspended. Please contact your administrator.',
                    'subscription_status' => $subscription?->status ?? 'suspended',
                ], 403);
            }

            return redirect()->route('school.subscription.notice');
        }

        return $next($request);
    }
}
