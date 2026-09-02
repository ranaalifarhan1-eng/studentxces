<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Package;
use App\Models\PackagePrice;
use App\Models\School;
use App\Models\SchoolSubscription;
use App\Services\CommercialPricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    public function __construct(
        protected CommercialPricingService $pricingService
    ) {}

    public function index(Request $request): Response
    {
        $subscriptions = SchoolSubscription::with(['school', 'package.prices', 'packagePrice', 'coupon'])
            ->when($request->school_id, fn ($q) => $q->where('school_id', $request->school_id))
            ->when($request->status,    fn ($q) => $q->where('status', $request->status))
            ->withTrashed(false)
            ->latest()
            ->paginate(20)
            ->withQueryString();

        // KPI
        $kpi = [
            'total'    => SchoolSubscription::count(),
            'active'   => SchoolSubscription::where('status', 'active')->count(),
            'trial'    => SchoolSubscription::where('status', 'trial')->count(),
            'expired'  => SchoolSubscription::where('status', 'expired')->count(),
        ];

        return Inertia::render('SuperAdmin/Subscriptions/Index', [
            'subscriptions' => [
                'data' => $subscriptions->items(),
                'meta' => [
                    'total'        => $subscriptions->total(),
                    'per_page'     => $subscriptions->perPage(),
                    'current_page' => $subscriptions->currentPage(),
                    'last_page'    => $subscriptions->lastPage(),
                ],
            ],
            'schools'  => School::select('id', 'name')->orderBy('name')->get(),
            'packages' => Package::where('is_active', true)->with(['prices' => fn ($q) => $q->orderBy('term_months', 'asc')])->get(),
            'coupons'  => Coupon::where('is_active', true)->select('id', 'code', 'type', 'value')->get(),
            'kpi'      => $kpi,
            'filters'  => $request->only(['school_id', 'status']),
            'canonicalTerms' => CommercialPricingService::CANONICAL_TERMS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'school_id'           => 'required|integer|exists:schools,id',
            'package_id'          => 'required|integer|exists:packages,id',
            'billing_term_months' => 'nullable|integer|in:3,6,12',
            'coupon_id'           => 'nullable|integer|exists:coupons,id',
            'start_date'          => 'required|date',
            'end_date'            => 'required|date|after:start_date',
            'status'              => 'required|in:trial,active,expired,suspended',
            'is_trial'            => 'boolean',
            'trial_ends_at'       => 'nullable|date',
            'amount_paid'         => 'required|numeric|min:0',
            'payment_method'      => 'nullable|string|max:50',
            'notes'               => 'nullable|string|max:500',
        ]);

        // Capture commercial pricing snapshot if term is provided
        if (! empty($data['billing_term_months'])) {
            $months = (int) $data['billing_term_months'];
            $priceRow = PackagePrice::where('package_id', $data['package_id'])
                ->where('term_months', $months)
                ->first();

            if ($priceRow) {
                $data['package_price_id']    = $priceRow->id;
                $data['base_monthly_price']  = $priceRow->base_monthly_price;
                $data['discount_percent']    = $priceRow->discount_percent;
                $data['billed_amount']       = $priceRow->total_price;
                $data['currency']            = $priceRow->currency;
            }
        }

        SchoolSubscription::create($data);

        return back()->with('success', 'Subscription assigned.');
    }

    public function update(Request $request, SchoolSubscription $subscription): RedirectResponse
    {
        $data = $request->validate([
            'package_id'          => 'required|integer|exists:packages,id',
            'billing_term_months' => 'nullable|integer|in:3,6,12',
            'coupon_id'           => 'nullable|integer|exists:coupons,id',
            'start_date'          => 'required|date',
            'end_date'            => 'required|date|after:start_date',
            'status'              => 'required|in:trial,active,expired,suspended',
            'is_trial'            => 'boolean',
            'trial_ends_at'       => 'nullable|date',
            'amount_paid'         => 'required|numeric|min:0',
            'payment_method'      => 'nullable|string|max:50',
            'notes'               => 'nullable|string|max:500',
        ]);

        if (! empty($data['billing_term_months'])) {
            $months = (int) $data['billing_term_months'];
            $priceRow = PackagePrice::where('package_id', $data['package_id'])
                ->where('term_months', $months)
                ->first();

            if ($priceRow) {
                $data['package_price_id']    = $priceRow->id;
                $data['base_monthly_price']  = $priceRow->base_monthly_price;
                $data['discount_percent']    = $priceRow->discount_percent;
                $data['billed_amount']       = $priceRow->total_price;
                $data['currency']            = $priceRow->currency;
            }
        }

        $subscription->update($data);

        return back()->with('success', 'Subscription updated.');
    }

    public function destroy(SchoolSubscription $subscription): RedirectResponse
    {
        $subscription->delete();
        return back()->with('success', 'Subscription removed.');
    }
}
