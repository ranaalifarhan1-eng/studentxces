<?php

namespace App\Services;

use App\Models\Package;
use App\Models\PackageModule;
use App\Models\School;
use App\Models\SchoolSubscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class LegacyEntitlementProvisioner
{
    public const LEGACY_PACKAGE_NAME = 'Legacy All Access';
    public const LEGACY_PACKAGE_SLUG = 'legacy-all-access';

    public function __construct(
        protected SchoolEntitlementResolver $resolver
    ) {}

    /**
     * Get or create the canonical internal Legacy All Access package with all 14 modules.
     * Inactive by default (is_active = false) so it never appears in standard commercial plan dropdowns.
     */
    public function getOrCreateLegacyPackage(array $attributes = []): Package
    {
        $slug = $attributes['slug'] ?? self::LEGACY_PACKAGE_SLUG;
        $name = $attributes['name'] ?? self::LEGACY_PACKAGE_NAME;

        $package = Package::withTrashed()->where('slug', $slug)->first();

        if (! $package) {
            $package = Package::create([
                'name'          => $name,
                'slug'          => $slug,
                'description'   => $attributes['description'] ?? 'Internal legacy entitlement tier with all canonical modules.',
                'price_monthly' => $attributes['price_monthly'] ?? 0.00,
                'price_yearly'  => $attributes['price_yearly'] ?? 0.00,
                'max_students'  => $attributes['max_students'] ?? 0, // 0 = unlimited by schema definition
                'max_staff'     => $attributes['max_staff'] ?? 0,    // 0 = unlimited by schema definition
                'storage_gb'    => $attributes['storage_gb'] ?? 100,
                'is_active'     => $attributes['is_active'] ?? false, // Inactive by default to isolate from public dropdowns
            ]);
        } elseif ($package->trashed()) {
            $package->restore();
        }

        // Attach canonical modules idempotently
        $canonical = config('modules.canonical', [
            'students', 'staff', 'attendance', 'timetable', 'exams',
            'fees', 'library', 'transport', 'hostel', 'inventory',
            'homework', 'communication', 'reports', 'hr',
        ]);

        $existingModules = PackageModule::where('package_id', $package->id)->pluck('module_slug')->toArray();

        foreach ($canonical as $slug) {
            if (! in_array($slug, $existingModules, true)) {
                PackageModule::create([
                    'package_id'  => $package->id,
                    'module_slug' => $slug,
                ]);
            }
        }

        return $package->load('modules');
    }

    /**
     * Validates date parameters strictly.
     * Throws \InvalidArgumentException if dates or duration are invalid.
     */
    public function validateDates(array $options): array
    {
        $startDateStr = $options['start_date'] ?? null;
        $endDateStr   = $options['end_date'] ?? null;
        $durationDays = isset($options['duration_days']) ? (int) $options['duration_days'] : 365;

        if ($durationDays <= 0) {
            throw new \InvalidArgumentException("Duration days must be a positive integer greater than zero.");
        }

        if ($startDateStr) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDateStr) || ! Carbon::hasFormat($startDateStr, 'Y-m-d')) {
                throw new \InvalidArgumentException("Invalid start-date format '{$startDateStr}'. Expected YYYY-MM-DD.");
            }
            $startDate = Carbon::createFromFormat('Y-m-d', $startDateStr)->startOfDay();
        } else {
            $startDate = Carbon::today();
        }

        if ($endDateStr) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDateStr) || ! Carbon::hasFormat($endDateStr, 'Y-m-d')) {
                throw new \InvalidArgumentException("Invalid end-date format '{$endDateStr}'. Expected YYYY-MM-DD.");
            }
            $endDate = Carbon::createFromFormat('Y-m-d', $endDateStr)->startOfDay();

            if ($endDate->lessThanOrEqualTo($startDate)) {
                throw new \InvalidArgumentException("End date '{$endDateStr}' must be strictly greater than start date '{$startDate->toDateString()}'.");
            }
        } else {
            $endDate = (clone $startDate)->addDays($durationDays);
        }

        return [$startDate, $endDate];
    }

    /**
     * Provision a legacy subscription for a specific school.
     */
    public function provisionSchool(School $school, array $options = []): array
    {
        $dryRun     = $options['dry_run'] ?? false;
        $status     = $options['status'] ?? 'active';
        $amountPaid = $options['amount_paid'] ?? 0.00;

        [$startDate, $endDate] = $this->validateDates($options);

        $schoolId = $school->id;

        // 1. Conflict Inspection: Check existing subscriptions
        $allSubs = SchoolSubscription::where('school_id', $schoolId)->latest('id')->get();
        $today   = Carbon::today()->toDateString();

        // Check for valid active subscription candidates
        $validCandidates = $allSubs->filter(function ($sub) use ($today) {
            if ($sub->status === 'active' && $sub->start_date && $sub->end_date) {
                return $sub->start_date->toDateString() <= $today && $sub->end_date->toDateString() >= $today;
            }
            if ($sub->status === 'trial' && $sub->is_trial && $sub->start_date && $sub->trial_ends_at) {
                return $sub->start_date->toDateString() <= $today && $sub->trial_ends_at->toDateString() >= $today;
            }
            return false;
        });

        // Case D: Multiple valid subscriptions -> AMBIGUOUS hard stop
        if ($validCandidates->count() > 1) {
            return [
                'status'          => 'AMBIGUOUS_ACTIVE_SUBSCRIPTIONS',
                'school_id'       => $schoolId,
                'school_name'     => $school->name,
                'package_id'      => null,
                'subscription_id' => null,
                'message'         => "School has {$validCandidates->count()} conflicting active subscriptions. Manual resolution required.",
            ];
        }

        // Case B: Exactly 1 valid subscription -> SKIP
        if ($validCandidates->count() === 1) {
            $existing = $validCandidates->first();
            return [
                'status'          => 'SKIP_EXISTING_VALID_SUBSCRIPTION',
                'school_id'       => $schoolId,
                'school_name'     => $school->name,
                'package_id'      => $existing->package_id,
                'subscription_id' => $existing->id,
                'message'         => "School already has a valid active subscription (#{$existing->id}).",
            ];
        }

        // Case C: Existing suspended/expired rows -> DO NOT silently overwrite
        if ($allSubs->isNotEmpty()) {
            $latest = $allSubs->first();
            if ($latest->status === 'suspended') {
                return [
                    'status'          => 'SKIP_EXISTING_SUSPENDED_SUBSCRIPTION',
                    'school_id'       => $schoolId,
                    'school_name'     => $school->name,
                    'package_id'      => $latest->package_id,
                    'subscription_id' => $latest->id,
                    'message'         => "School has a suspended subscription (#{$latest->id}). Manual review required.",
                ];
            }

            if (($latest->end_date && $latest->end_date->toDateString() < $today) || ($latest->is_trial && $latest->trial_ends_at && $latest->trial_ends_at->toDateString() < $today)) {
                return [
                    'status'          => 'SKIP_EXISTING_EXPIRED_SUBSCRIPTION',
                    'school_id'       => $schoolId,
                    'school_name'     => $school->name,
                    'package_id'      => $latest->package_id,
                    'subscription_id' => $latest->id,
                    'message'         => "School has an expired subscription (#{$latest->id}). Manual migration policy required.",
                ];
            }
        }

        // Case A: Clean candidate (0 valid, 0 conflict)
        if ($dryRun) {
            return [
                'status'          => 'WOULD_PROVISION',
                'school_id'       => $schoolId,
                'school_name'     => $school->name,
                'package_slug'    => self::LEGACY_PACKAGE_SLUG,
                'package_id'      => null,
                'subscription_id' => null,
                'start_date'      => $startDate->toDateString(),
                'end_date'        => $endDate->toDateString(),
                'modules_count'   => count(config('modules.canonical', [])),
                'message'         => "Would create Legacy All Access subscription from {$startDate->toDateString()} to {$endDate->toDateString()}.",
            ];
        }

        // Execute Provisioning atomically inside a DB transaction
        return DB::transaction(function () use ($school, $schoolId, $startDate, $endDate, $status, $amountPaid) {
            $package = $this->getOrCreateLegacyPackage();

            $subscription = SchoolSubscription::create([
                'school_id'      => $schoolId,
                'package_id'     => $package->id,
                'coupon_id'      => null,
                'start_date'     => $startDate->toDateString(),
                'end_date'       => $endDate->toDateString(),
                'status'         => $status,
                'is_trial'       => $status === 'trial',
                'trial_ends_at'  => $status === 'trial' ? $endDate->toDateString() : null,
                'amount_paid'    => $amountPaid,
                'payment_method' => 'internal_legacy',
                'notes'          => 'Provisioned by legacy entitlement tooling.',
            ]);

            // Write provisioning journal record
            $journalData = [
                'execution_id'                => (string) Str::uuid(),
                'timestamp'                   => Carbon::now()->toIso8601String(),
                'school_id'                   => $schoolId,
                'school_name'                 => $school->name,
                'package_id'                  => $package->id,
                'package_name'                => $package->name,
                'created_subscription_id'     => $subscription->id,
                'previous_subscription_state' => 'NO_ACTIVE_SUBSCRIPTION',
                'resulting_subscription_state'=> 'ALLOWED',
                'start_date'                  => $startDate->toDateString(),
                'end_date'                    => $endDate->toDateString(),
                'action_result'               => 'PROVISIONED',
            ];

            $this->writeJournal($journalData);

            return [
                'status'          => 'PROVISIONED',
                'school_id'       => $schoolId,
                'school_name'     => $school->name,
                'package_id'      => $package->id,
                'package_name'    => $package->name,
                'subscription_id' => $subscription->id,
                'start_date'      => $startDate->toDateString(),
                'end_date'        => $endDate->toDateString(),
                'modules_count'   => $package->modules->count(),
                'journal_id'      => $journalData['execution_id'],
                'message'         => "Successfully provisioned Legacy All Access subscription (#{$subscription->id}).",
            ];
        });
    }

    /**
     * Write an audit journal file to private storage.
     */
    protected function writeJournal(array $data): void
    {
        $journalDir = storage_path('app/private/entitlement_manifests');
        if (! File::isDirectory($journalDir)) {
            File::makeDirectory($journalDir, 0755, true);
        }

        $filename = "legacy_provisioning_" . Carbon::now()->format('Ymd_His') . "_" . substr($data['execution_id'], 0, 8) . ".json";
        $filepath = $journalDir . DIRECTORY_SEPARATOR . $filename;

        File::put($filepath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
