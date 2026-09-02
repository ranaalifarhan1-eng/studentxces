<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\SchoolOnboardingRequest;
use App\Models\Coupon;
use App\Models\Package;
use App\Services\CommercialPricingService;
use App\Services\SchoolOnboardingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SchoolOnboardingController extends Controller
{
    public function __construct(
        protected SchoolOnboardingService $onboardingService
    ) {}

    /**
     * Show the guided onboarding wizard.
     */
    public function create(Request $request): Response
    {
        $packages = Package::where('is_active', true)
            ->where('is_internal', false)
            ->with([
                'prices' => fn ($q) => $q->where('is_active', true)->orderBy('term_months', 'asc'),
                'modules',
            ])
            ->get();

        $coupons = Coupon::where('is_active', true)
            ->select('id', 'code', 'type', 'value', 'description')
            ->get();

        $defaults = [
            'country'            => 'PK',
            'city'               => 'Lahore',
            'state'              => 'Punjab',
            'timezone'           => 'Asia/Karachi',
            'currency'           => 'PKR',
            'language'           => 'en',
            'status'             => 'active',
            'start_date'         => now()->toDateString(),
            'academic_year_name' => 'Academic Year ' . now()->format('Y') . '-' . now()->addYear()->format('y'),
            'academic_start'     => now()->startOfYear()->addMonths(7)->toDateString(), // e.g. Aug 1
            'academic_end'       => now()->startOfYear()->addMonths(18)->toDateString(), // e.g. Jun 30
        ];

        return Inertia::render('SuperAdmin/Schools/Onboard', [
            'packages'       => $packages,
            'coupons'        => $coupons,
            'canonicalTerms' => CommercialPricingService::CANONICAL_TERMS,
            'defaults'       => $defaults,
            'onboardingSuccess' => session('onboarding_result'),
        ]);
    }

    /**
     * Handle the submission of the guided onboarding form.
     */
    public function store(SchoolOnboardingRequest $request): RedirectResponse
    {
        $result = $this->onboardingService->onboard($request->validated(), $request->user());

        $summary = [
            'school_id'       => $result['school']->id,
            'school_name'     => $result['school']->name,
            'school_code'     => $result['school']->code,
            'school_slug'     => $result['school']->slug,
            'admin_name'      => $result['admin_user']->name,
            'admin_email'     => $result['admin_user']->email,
            'subscription_id'     => $result['subscription']->id,
            'subscription_status' => $result['subscription']->status,
            'package_name'    => $result['package']->name,
            'term_months'     => $result['term_months'],
            'billed_amount'   => $result['billed_amount'],
            'amount_paid'     => $result['amount_paid'],
            'balance_due'     => $result['balance_due'],
            'currency'        => $result['package_price']->currency ?? 'PKR',
            'start_date'      => $result['subscription']->start_date->format('Y-m-d'),
            'end_date'        => $result['subscription']->end_date->format('Y-m-d'),
            'academic_year'   => $result['academic_year']?->name,
            'domain_hostname' => $result['domain']?->hostname,
            'domain_status'   => $result['domain']?->status,
        ];

        return redirect()
            ->route('super-admin.schools.onboard')
            ->with('onboarding_result', $summary)
            ->with('success', "School \"{$result['school']->name}\" and commercial subscription successfully created.");
    }
}
