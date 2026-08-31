<?php

namespace App\Http\Middleware;

use App\Models\PlatformSetting;
use App\Services\ActiveSchoolContext;
use App\Services\SchoolEntitlementResolver;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user() ? [
                    'id'        => $request->user()->id,
                    'name'      => $request->user()->name,
                    'email'     => $request->user()->email,
                    'avatar'    => $request->user()->avatar ?? null,
                    'role'      => $request->user()->roles()->first()?->name ?? null,
                    'school_id' => $request->user()->school_id ?? null,
                ] : null,
            ],
            'active_school' => fn () => once(function () {
                $context = app(ActiveSchoolContext::class);
                $school = $context->getSelectedSchool();
                return $school ? [
                    'id'     => $school->id,
                    'name'   => $school->name,
                    'slug'   => $school->slug,
                    'status' => $school->status,
                ] : null;
            }),
            'flash' => [
                'success' => fn () => session('success'),
                'error'   => fn () => session('error'),
                'warning' => fn () => session('warning'),
            ],
            'faviconUrl' => fn () => once(function () {
                $path = PlatformSetting::get('platform_favicon');
                return $path ? asset('storage/' . $path) : null;
            }),
            'entitlement' => fn () => once(function () {
                $context = app(ActiveSchoolContext::class);
                $schoolId = $context->getSelectedSchoolId();
                if (! $schoolId) {
                    return null;
                }
                return [
                    'mode'                => config('entitlement.mode', 'off'),
                    'subscription_active' => app(SchoolEntitlementResolver::class)->hasActiveSubscription($schoolId),
                    'effective_modules'   => app(SchoolEntitlementResolver::class)->getEffectiveModules($schoolId),
                ];
            }),
        ];
    }
}
