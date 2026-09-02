<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Coupon;
use App\Models\Package;
use App\Models\PackagePrice;
use App\Models\School;
use App\Models\SchoolDomain;
use App\Models\SchoolSubscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class SchoolOnboardingService
{
    public function __construct(
        protected DomainService $domainService
    ) {}

    /**
     * Atomically onboard a new commercial school with its admin, subscription, academic year, and optional domain.
     *
     * @param array $data Validated onboarding parameters
     * @param User|null $operator The Super Admin user executing the onboarding
     * @return array<string, mixed>
     * @throws ValidationException|\Exception
     */
    public function onboard(array $data, ?User $operator = null): array
    {
        return DB::transaction(function () use ($data, $operator) {
            // 1. Validate School Code Uniqueness (canonical settings JSON check)
            $code = strtoupper(trim($data['code'] ?? ''));
            if ($code === '') {
                throw ValidationException::withMessages([
                    'code' => 'School code is required.',
                ]);
            }

            if (School::where('settings->school_code', $code)->exists()) {
                throw ValidationException::withMessages([
                    'code' => "A school with code '{$code}' already exists.",
                ]);
            }

            // 2. Validate Commercial Package (Must be active and non-internal)
            $package = Package::where('id', $data['package_id'])
                ->where('is_active', true)
                ->where('is_internal', false)
                ->first();

            if (! $package) {
                throw ValidationException::withMessages([
                    'package_id' => 'The selected package is invalid, inactive, or not available for commercial assignment.',
                ]);
            }

            // 3. Validate Term Months & Active PackagePrice
            $termMonths = (int) ($data['billing_term_months'] ?? 0);
            if (! in_array($termMonths, [3, 6, 12], true)) {
                throw ValidationException::withMessages([
                    'billing_term_months' => 'Commercial subscriptions are available only in 3, 6, or 12 month commitments.',
                ]);
            }

            $priceRow = PackagePrice::where('package_id', $package->id)
                ->where('term_months', $termMonths)
                ->where('is_active', true)
                ->first();

            if (! $priceRow) {
                throw ValidationException::withMessages([
                    'billing_term_months' => 'No active commercial price found for selected package and billing term.',
                ]);
            }

            // 4. Calculate Server-Authoritative Billed Amount & Coupon
            $termPrice = (float) $priceRow->total_price;
            $couponDiscount = 0.00;
            $couponId = null;

            if (! empty($data['coupon_id'])) {
                $coupon = Coupon::where('id', $data['coupon_id'])->where('is_active', true)->first();
                if ($coupon) {
                    $couponId = $coupon->id;
                    if ($coupon->type === 'percent') {
                        $couponDiscount = round($termPrice * ((float) $coupon->value / 100), 2);
                    } else {
                        $couponDiscount = min($termPrice, round((float) $coupon->value, 2));
                    }
                }
            }

            $billedAmount = max(0.00, round($termPrice - $couponDiscount, 2));

            // 5. Validate Amount Received (0 <= amount_paid <= billed_amount)
            $amountPaid = isset($data['amount_paid']) ? (float) $data['amount_paid'] : 0.00;
            if ($amountPaid < 0) {
                throw ValidationException::withMessages([
                    'amount_paid' => 'Amount received cannot be negative.',
                ]);
            }

            if ($amountPaid > $billedAmount) {
                throw ValidationException::withMessages([
                    'amount_paid' => "Amount received ({$amountPaid}) cannot exceed the final billed amount ({$billedAmount}).",
                ]);
            }

            // 6. Explicit Subscription Access Status Resolution
            $requestedActivation = isset($data['activate_subscription']) ? (bool) $data['activate_subscription'] : false;
            $unpaidOverride = isset($data['activate_unpaid_override']) ? (bool) $data['activate_unpaid_override'] : false;

            if ($amountPaid == 0.00) {
                // Unpaid default is suspended unless explicitly overridden by operator
                $subStatus = ($requestedActivation && $unpaidOverride) ? 'active' : 'suspended';
            } else {
                // Paid (partial or full) uses explicit operator activation choice
                $subStatus = $requestedActivation ? 'active' : 'suspended';
            }

            // 7. Server-Authoritative End Date
            $startDate = Carbon::parse($data['start_date'])->toDateString();
            $endDate   = Carbon::parse($startDate)->addMonths($termMonths)->toDateString();

            // 8. Ensure Required Role Exists
            if (! Role::where('name', 'school-admin')->where('guard_name', 'web')->exists()) {
                throw new \RuntimeException("Safety violation: Required platform role 'school-admin' does not exist.");
            }

            // 9. Create School
            $school = School::create([
                'name'     => trim($data['name']),
                'slug'     => strtolower(trim($data['slug'])),
                'email'    => ! empty($data['email']) ? trim($data['email']) : null,
                'phone'    => ! empty($data['phone']) ? trim($data['phone']) : null,
                'address'  => ! empty($data['address']) ? trim($data['address']) : null,
                'city'     => ! empty($data['city']) ? trim($data['city']) : 'Lahore',
                'state'    => ! empty($data['state']) ? trim($data['state']) : 'Punjab',
                'country'  => ! empty($data['country']) ? strtoupper(trim($data['country'])) : 'PK',
                'timezone' => ! empty($data['timezone']) ? trim($data['timezone']) : 'Asia/Karachi',
                'currency' => ! empty($data['currency']) ? strtoupper(trim($data['currency'])) : 'PKR',
                'language' => ! empty($data['language']) ? strtolower(trim($data['language'])) : 'en',
                'status'   => ! empty($data['status']) ? strtolower(trim($data['status'])) : 'active',
                'settings' => [
                    'school_code' => $code,
                ],
            ]);

            // 10. Create School Admin User (Password securely hashed; NEVER stored raw)
            $adminUser = User::create([
                'school_id' => $school->id,
                'name'      => trim($data['admin_name']),
                'email'     => strtolower(trim($data['admin_email'])),
                'phone'     => ! empty($data['admin_phone']) ? trim($data['admin_phone']) : null,
                'password'  => Hash::make($data['admin_password']),
                'status'    => 'active',
            ]);
            $adminUser->assignRole('school-admin');

            // 11. Create Commercial Subscription with Snapshots & Explicit Status
            $subscription = SchoolSubscription::create([
                'school_id'           => $school->id,
                'package_id'          => $package->id,
                'package_price_id'    => $priceRow->id,
                'billing_term_months' => $termMonths,
                'base_monthly_price'  => $priceRow->base_monthly_price,
                'discount_percent'    => $priceRow->discount_percent,
                'billed_amount'       => $billedAmount,
                'currency'            => $priceRow->currency ?? 'PKR',
                'coupon_id'           => $couponId,
                'start_date'          => $startDate,
                'end_date'            => $endDate,
                'status'              => $subStatus,
                'is_trial'            => false,
                'amount_paid'         => $amountPaid,
                'payment_method'      => $data['payment_method'] ?? 'manual',
                'notes'               => $data['notes'] ?? 'Commercial guided onboarding',
            ]);

            // 12. Optional Academic Year
            $academicYear = null;
            if (! empty($data['academic_year_name'])) {
                $academicYear = AcademicYear::create([
                    'school_id'  => $school->id,
                    'name'       => trim($data['academic_year_name']),
                    'start_date' => $data['academic_start'],
                    'end_date'   => $data['academic_end'],
                    'is_current' => isset($data['set_academic_current']) ? (bool) $data['set_academic_current'] : true,
                ]);
            }

            // 13. Optional Custom Domain (Pending State)
            $domain = null;
            if (! empty($data['custom_domain'])) {
                $domain = $this->domainService->addCustomDomain($school, $data['custom_domain']);
            }

            // 14. Platform Activity Audit (Zero Sensitive Passwords or Tokens Logged)
            if (function_exists('activity')) {
                $log = activity()->performedOn($school);
                if ($operator) {
                    $log->causedBy($operator);
                }
                $log->log("Commercial school onboarded: School [{$school->name}] (#{$school->id}), Code [{$code}], Package [{$package->name}] ({$termMonths}mo), Status [{$subStatus}], Billed [{$billedAmount}], Paid [{$amountPaid}]");
            }

            return [
                'school'          => $school,
                'admin_user'      => $adminUser,
                'subscription'    => $subscription,
                'academic_year'   => $academicYear,
                'domain'          => $domain,
                'package'         => $package,
                'package_price'   => $priceRow,
                'term_months'     => $termMonths,
                'billed_amount'   => $billedAmount,
                'amount_paid'     => $amountPaid,
                'balance_due'     => max(0.00, round($billedAmount - $amountPaid, 2)),
                'coupon_discount' => $couponDiscount,
                'sub_status'      => $subStatus,
            ];
        });
    }
}
