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
            'end_date'            => 'nullable|date',
            'status'              => 'required|in:trial,active,expired,suspended',
            'is_trial'            => 'boolean',
            'trial_ends_at'       => 'nullable|date',
            'amount_paid'         => 'nullable|numeric|min:0',
            'payment_method'      => 'nullable|string|max:50',
            'notes'               => 'nullable|string|max:500',
        ]);

        $package = Package::findOrFail($data['package_id']);
        if ($package->is_internal) {
            return back()->withErrors(['package_id' => 'Cannot commercially assign internal grandfathered package.']);
        }

        // Amount paid default 0 if not explicitly entered
        $data['amount_paid'] = isset($data['amount_paid']) ? (float) $data['amount_paid'] : 0.00;

        // Capture commercial pricing snapshot & server-authoritative end date if term is provided
        if (! empty($data['billing_term_months'])) {
            $months = (int) $data['billing_term_months'];
            $data['end_date'] = \Carbon\Carbon::parse($data['start_date'])->addMonths($months)->toDateString();

            $priceRow = PackagePrice::where('package_id', $package->id)
                ->where('term_months', $months)
                ->where('is_active', true)
                ->first();

            if (! $priceRow) {
                return back()->withErrors(['billing_term_months' => 'No active commercial price found for selected package and billing term.']);
            }

            $termPrice = (float) $priceRow->total_price;
            $billedAmount = $termPrice;

            if (! empty($data['coupon_id'])) {
                $coupon = Coupon::where('id', $data['coupon_id'])->where('is_active', true)->first();
                if ($coupon) {
                    if ($coupon->type === 'percent') {
                        $billedAmount = round($termPrice * (1 - ((float) $coupon->value / 100)), 2);
                    } else {
                        $billedAmount = max(0.00, round($termPrice - (float) $coupon->value, 2));
                    }
                }
            }

            $data['package_price_id']    = $priceRow->id;
            $data['base_monthly_price']  = $priceRow->base_monthly_price;
            $data['discount_percent']    = $priceRow->discount_percent;
            $data['billed_amount']       = $billedAmount;
            $data['currency']            = $priceRow->currency ?? 'PKR';
        } else {
            if (empty($data['end_date']) || $data['end_date'] <= $data['start_date']) {
                return back()->withErrors(['end_date' => 'End date must be after start date.']);
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
            'end_date'            => 'nullable|date',
            'status'              => 'required|in:trial,active,expired,suspended',
            'is_trial'            => 'boolean',
            'trial_ends_at'       => 'nullable|date',
            'amount_paid'         => 'nullable|numeric|min:0',
            'payment_method'      => 'nullable|string|max:50',
            'notes'               => 'nullable|string|max:500',
        ]);

        $package = Package::findOrFail($data['package_id']);
        if ($package->is_internal && $subscription->package_id !== $package->id) {
            return back()->withErrors(['package_id' => 'Cannot switch to internal grandfathered package.']);
        }

        $data['amount_paid'] = isset($data['amount_paid']) ? (float) $data['amount_paid'] : (float) $subscription->amount_paid;

        if (! empty($data['billing_term_months'])) {
            $months = (int) $data['billing_term_months'];
            $data['end_date'] = \Carbon\Carbon::parse($data['start_date'])->addMonths($months)->toDateString();

            $priceRow = PackagePrice::where('package_id', $package->id)
                ->where('term_months', $months)
                ->where('is_active', true)
                ->first();

            if (! $priceRow) {
                return back()->withErrors(['billing_term_months' => 'No active commercial price found for selected package and billing term.']);
            }

            $termPrice = (float) $priceRow->total_price;
            $billedAmount = $termPrice;

            if (! empty($data['coupon_id'])) {
                $coupon = Coupon::where('id', $data['coupon_id'])->where('is_active', true)->first();
                if ($coupon) {
                    if ($coupon->type === 'percent') {
                        $billedAmount = round($termPrice * (1 - ((float) $coupon->value / 100)), 2);
                    } else {
                        $billedAmount = max(0.00, round($termPrice - (float) $coupon->value, 2));
                    }
                }
            }

            $data['package_price_id']    = $priceRow->id;
            $data['base_monthly_price']  = $priceRow->base_monthly_price;
            $data['discount_percent']    = $priceRow->discount_percent;
            $data['billed_amount']       = $billedAmount;
            $data['currency']            = $priceRow->currency ?? 'PKR';
        } else {
            if (empty($data['end_date']) || $data['end_date'] <= $data['start_date']) {
                return back()->withErrors(['end_date' => 'End date must be after start date.']);
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
