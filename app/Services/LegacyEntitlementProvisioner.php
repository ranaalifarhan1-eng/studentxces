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

        // Phase 1: Initialize Two-Phase Manifest in PENDING state on disk
        $package = $this->getOrCreateLegacyPackage();
        $executionId = (string) Str::uuid();

        $journalData = [
            'execution_id'                => $executionId,
            'state'                       => 'PENDING',
            'created_at'                  => Carbon::now()->toIso8601String(),
            'committed_at'                => null,
            'school_id'                   => $schoolId,
            'school_name'                 => $school->name,
            'package_id'                  => $package->id,
            'package_name'                => $package->name,
            'created_subscription_id'     => null,
            'previous_subscription_state' => 'NO_ACTIVE_SUBSCRIPTION',
            'resulting_subscription_state'=> null,
            'start_date'                  => $startDate->toDateString(),
            'end_date'                    => $endDate->toDateString(),
            'action_result'               => 'PENDING',
        ];

        $journalPath = $this->writeJournal($journalData);

        // Phase 2: Execute Database Mutation
        try {
            $subscription = DB::transaction(function () use ($schoolId, $package, $startDate, $endDate, $status, $amountPaid) {
                return SchoolSubscription::create([
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
            });

            // Phase 3: Finalize Manifest to COMMITTED state on successful DB commit
            $journalData['state']                        = 'COMMITTED';
            $journalData['committed_at']                 = Carbon::now()->toIso8601String();
            $journalData['created_subscription_id']      = $subscription->id;
            $journalData['resulting_subscription_state'] = 'ALLOWED';
            $journalData['action_result']                = 'PROVISIONED';

            $this->writeJournal($journalData, $journalPath);

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
                'journal_id'      => $executionId,
                'message'         => "Successfully provisioned Legacy All Access subscription (#{$subscription->id}).",
            ];
        } catch (\Throwable $e) {
            // DB Transaction Failed / Rolled Back: Update manifest state to FAILED_ROLLED_BACK
            $journalData['state']         = 'FAILED_ROLLED_BACK';
            $journalData['action_result'] = 'FAILED';
            $journalData['failed_at']     = Carbon::now()->toIso8601String();
            $journalData['error_message'] = $e->getMessage();

            $this->writeJournal($journalData, $journalPath);

            throw $e;
        }
    }

    /**
     * Validate the consistency and authenticity of a journal manifest.
     */
    public function validateJournalManifest(string|array $manifest): array
    {
        $data = is_string($manifest) ? json_decode(File::get($manifest), true) : $manifest;

        if (! is_array($data)) {
            return ['is_valid' => false, 'reason' => 'MALFORMED_JSON', 'message' => 'Manifest is not valid JSON.'];
        }

        // Must be in COMMITTED state
        if (($data['state'] ?? '') !== 'COMMITTED') {
            return [
                'is_valid' => false,
                'reason'   => 'INCOMPLETE_OR_FAILED_STATE',
                'message'  => "Manifest state is '{$data['state']}', not COMMITTED.",
            ];
        }

        $schoolId = $data['school_id'] ?? null;
        $pkgId    = $data['package_id'] ?? null;
        $subId    = $data['created_subscription_id'] ?? null;

        $school = School::find($schoolId);
        if (! $school) {
            return ['is_valid' => false, 'reason' => 'SCHOOL_NOT_FOUND', 'message' => "School ID {$schoolId} does not exist."];
        }

        $package = Package::find($pkgId);
        if (! $package) {
            return ['is_valid' => false, 'reason' => 'PACKAGE_NOT_FOUND', 'message' => "Package ID {$pkgId} does not exist."];
        }

        $subscription = SchoolSubscription::find($subId);
        if (! $subscription) {
            return ['is_valid' => false, 'reason' => 'SUBSCRIPTION_NOT_FOUND', 'message' => "Subscription ID {$subId} does not exist in DB."];
        }

        if ($subscription->school_id !== (int) $schoolId) {
            return ['is_valid' => false, 'reason' => 'SCHOOL_MISMATCH', 'message' => "Subscription belongs to school {$subscription->school_id}, expected {$schoolId}."];
        }

        if ($subscription->package_id !== (int) $pkgId) {
            return ['is_valid' => false, 'reason' => 'PACKAGE_MISMATCH', 'message' => "Subscription references package {$subscription->package_id}, expected {$pkgId}."];
        }

        if ($subscription->start_date?->toDateString() !== $data['start_date'] || $subscription->end_date?->toDateString() !== $data['end_date']) {
            return ['is_valid' => false, 'reason' => 'DATE_MISMATCH', 'message' => 'Subscription dates do not match journal manifest dates.'];
        }

        return [
            'is_valid'     => true,
            'school'       => $school,
            'package'      => $package,
            'subscription' => $subscription,
            'message'      => 'Manifest is valid and consistent with database state.',
        ];
    }

    /**
     * Write or update an audit journal file in private storage.
     */
    protected function writeJournal(array $data, ?string $existingPath = null): string
    {
        $journalDir = storage_path('app/private/entitlement_manifests');
        if (! File::isDirectory($journalDir)) {
            File::makeDirectory($journalDir, 0755, true);
        }

        $filepath = $existingPath ?? ($journalDir . DIRECTORY_SEPARATOR . "legacy_provisioning_" . Carbon::now()->format('Ymd_His') . "_" . substr($data['execution_id'], 0, 8) . ".json");

        File::put($filepath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $filepath;
    }
}
