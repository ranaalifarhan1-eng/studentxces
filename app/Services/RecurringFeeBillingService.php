<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\FeeBillingRun;
use App\Models\FeeChallan;
use App\Models\School;
use App\Models\SchoolSetting;
use App\Models\Student;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecurringFeeBillingService
{
    /**
     * Resolve a deterministic recurring due date for a target billing month.
     * Guaranteed valid calendar date across all months by clamping day to 1..28.
     */
    public static function resolveRecurringDueDate(Carbon $targetMonth, int $dueDay = 10): Carbon
    {
        $day = max(1, min(28, $dueDay));
        return $targetMonth->copy()->startOfMonth()->day($day);
    }

    /**
     * Get auto-billing configuration for a school.
     */
    public static function getSchoolSettings(int $schoolId): array
    {
        $enabled = SchoolSetting::get($schoolId, 'auto_billing_enabled', '0');
        $billingDay = (int) SchoolSetting::get($schoolId, 'auto_billing_day', '1');
        $dueDay = (int) SchoolSetting::get($schoolId, 'recurring_fee_due_day', '10');

        return [
            'auto_billing_enabled'  => $enabled === '1' || $enabled === true || $enabled === 1,
            'auto_billing_day'      => max(1, min(28, $billingDay ?: 1)),
            'recurring_fee_due_day' => max(1, min(28, $dueDay ?: 10)),
        ];
    }

    /**
     * Save auto-billing configuration for a school.
     */
    public static function saveSchoolSettings(int $schoolId, array $data): void
    {
        $enabled = ! empty($data['auto_billing_enabled']) ? '1' : '0';
        $billingDay = max(1, min(28, (int) ($data['auto_billing_day'] ?? 1)));
        $dueDay = max(1, min(28, (int) ($data['recurring_fee_due_day'] ?? 10)));

        SchoolSetting::set($schoolId, 'auto_billing_enabled', $enabled, 'fees');
        SchoolSetting::set($schoolId, 'auto_billing_day', (string) $billingDay, 'fees');
        SchoolSetting::set($schoolId, 'recurring_fee_due_day', (string) $dueDay, 'fees');
    }

    /**
     * Check if there are active students with active assignments for the target month
     * who have unbilled eligible charges for this billing period.
     */
    public static function hasUnbilledEligibleStudents(School $school, AcademicYear $academicYear, Carbon $targetMonth): bool
    {
        $monthStart = $targetMonth->copy()->startOfMonth()->toDateString();
        $monthEnd   = $targetMonth->copy()->endOfMonth()->toDateString();

        // 1. Query active students who have candidate active recurring fee assignments valid for this target month
        $students = Student::where('school_id', $school->id)
            ->where('status', 'active')
            ->whereHas('feeAssignments', function ($q) use ($academicYear, $monthStart, $monthEnd) {
                $q->where('academic_year_id', $academicYear->id)
                    ->where('is_active', true)
                    ->where('starts_on', '<=', $monthEnd)
                    ->where(function ($sub) use ($monthStart) {
                        $sub->whereNull('ends_on')
                            ->orWhere('ends_on', '>=', $monthStart);
                    })
                    ->whereHas('feeStructure', function ($st) {
                        $st->where('is_active', true)
                           ->whereNull('deleted_at')
                           ->where('frequency', '!=', 'one_time');
                    });
            })
            ->with([
                'feeAssignments' => function ($q) use ($academicYear, $monthStart, $monthEnd) {
                    $q->where('academic_year_id', $academicYear->id)
                        ->where('is_active', true)
                        ->where('starts_on', '<=', $monthEnd)
                        ->where(function ($sub) use ($monthStart) {
                            $sub->whereNull('ends_on')
                                ->orWhere('ends_on', '>=', $monthStart);
                        })
                        ->with(['feeStructure.feeCategory:id,name']);
                }
            ])
            ->get();

        if ($students->isEmpty()) {
            return false;
        }

        // 2. Evaluate charge-level eligibility
        foreach ($students as $student) {
            foreach ($student->feeAssignments as $assignment) {
                $structure = $assignment->feeStructure;
                if (! $structure || ! $structure->is_active || $structure->trashed()) {
                    continue;
                }
                if ($structure->frequency === 'one_time') {
                    continue;
                }
                if ($structure->school_id !== $school->id || $structure->class_id !== $student->class_id) {
                    continue;
                }

                if (FeeBillingService::isStructureEligible($structure, $student, $academicYear, $targetMonth)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Determine whether a school is currently eligible for automated billing execution.
     * Respects school timezone, configured billing day, past completion runs, and catch-up unbilled assignments.
     */
    public static function isSchoolEligibleForCycle(School $school, ?Carbon $now = null): bool
    {
        $tz = $school->timezone ?: config('app.timezone', 'Asia/Karachi');
        $localNow = $now ? $now->copy()->setTimezone($tz) : Carbon::now($tz);

        $settings = self::getSchoolSettings($school->id);
        if (! $settings['auto_billing_enabled']) {
            return false;
        }

        // Must be on or after the configured billing day of month
        if ($localNow->day < $settings['auto_billing_day']) {
            return false;
        }

        $targetMonthStr = $localNow->format('Y-m');

        // Check if currently running or queued (concurrency guard)
        $inFlight = FeeBillingRun::where('school_id', $school->id)
            ->where('target_month', $targetMonthStr)
            ->where(function ($q) {
                $q->where('status', 'queued')
                  ->orWhere(function ($sub) {
                      $sub->where('status', 'running')
                          ->where('started_at', '>', now()->subMinutes(15));
                  });
            })
            ->exists();

        if ($inFlight) {
            return false;
        }

        // Check if completed run already exists
        $hasCompleted = FeeBillingRun::where('school_id', $school->id)
            ->where('target_month', $targetMonthStr)
            ->where('status', 'completed')
            ->exists();

        // If never completed this month, it is eligible!
        if (! $hasCompleted) {
            return true;
        }

        // If already completed, check if unbilled eligible students exist for catch-up
        $academicYear = AcademicYear::where('school_id', $school->id)->where('is_current', true)->first()
            ?? AcademicYear::where('school_id', $school->id)->orderByDesc('start_date')->first();

        if (! $academicYear) {
            return false;
        }

        return self::hasUnbilledEligibleStudents($school, $academicYear, $localNow->copy()->startOfMonth());
    }

    /**
     * Preview a billing cycle for a school and target month with ZERO database mutations.
     */
    public static function previewBillingCycle(int $schoolId, Carbon $targetMonth): array
    {
        $school = School::findOrFail($schoolId);
        $academicYear = AcademicYear::where('school_id', $schoolId)->where('is_current', true)->first()
            ?? AcademicYear::where('school_id', $schoolId)->orderByDesc('start_date')->first();

        $targetMonthStr = $targetMonth->format('Y-m');
        $settings = self::getSchoolSettings($schoolId);
        $dueDate = self::resolveRecurringDueDate($targetMonth, $settings['recurring_fee_due_day']);

        if (! $academicYear) {
            return [
                'school_id'               => $schoolId,
                'target_month'            => $targetMonthStr,
                'academic_year_name'      => null,
                'due_date'                => $dueDate->toDateString(),
                'eligible_students_count' => 0,
                'expected_challans_count' => 0,
                'skipped_duplicates_count'=> 0,
                'expected_gross_cents'    => 0,
                'expected_gross_amount'   => '0.00',
                'error'                   => 'No active academic year found for this school.',
            ];
        }

        $monthStart = $targetMonth->copy()->startOfMonth();
        $monthEnd   = $targetMonth->copy()->endOfMonth();

        $students = Student::where('school_id', $schoolId)
            ->where('status', 'active')
            ->whereHas('feeAssignments', function ($q) use ($academicYear, $monthStart, $monthEnd) {
                $q->where('academic_year_id', $academicYear->id)
                    ->where('is_active', true)
                    ->where('starts_on', '<=', $monthEnd->toDateString())
                    ->where(function ($sub) use ($monthStart) {
                        $sub->whereNull('ends_on')
                            ->orWhere('ends_on', '>=', $monthStart->toDateString());
                    });
            })
            ->with(['schoolClass', 'section'])
            ->get();

        $billingPeriodKey = "AY{$academicYear->id}-{$targetMonthStr}";
        $expectedChallans = 0;
        $skippedDuplicates = 0;
        $expectedGrossCents = 0;

        foreach ($students as $student) {
            $structures = FeeBillingService::resolveBillableStructures($student, $academicYear, $targetMonth);
            if ($structures->isEmpty()) {
                $skippedDuplicates++;
                continue;
            }

            // Check if any structures are eligible under frequency & charge-level rules
            $eligibleStructures = collect();
            foreach ($structures as $st) {
                if (FeeBillingService::isStructureEligible($st, $student, $academicYear, $targetMonth)) {
                    $eligibleStructures->push($st);
                }
            }

            if ($eligibleStructures->isEmpty()) {
                $skippedDuplicates++;
                continue;
            }

            $activePeriodKey = "{$student->id}_{$billingPeriodKey}";
            $existingChallan = FeeChallan::where('school_id', $schoolId)
                ->where('active_period_key', $activePeriodKey)
                ->where('status', '!=', 'void')
                ->first();

            $alreadyDiscountCents = $existingChallan ? Money::toCents($existingChallan->discount_amount) : 0;
            $prepared = FeeBillingService::prepareChallanLines($eligibleStructures, $student, $academicYear, $targetMonth, null, $alreadyDiscountCents);

            if (! empty($prepared['lines']) && $prepared['gross_cents'] > 0) {
                $expectedChallans++;
                $expectedGrossCents += $prepared['total_payable_cents'];
            } else {
                $skippedDuplicates++;
            }
        }

        return [
            'school_id'               => $schoolId,
            'school_name'             => $school->name,
            'target_month'            => $targetMonthStr,
            'academic_year_name'      => $academicYear->name,
            'due_date'                => $dueDate->toDateString(),
            'eligible_students_count' => $students->count(),
            'expected_challans_count' => $expectedChallans,
            'skipped_duplicates_count'=> $skippedDuplicates,
            'expected_gross_cents'    => $expectedGrossCents,
            'expected_gross_amount'   => Money::toDecimal($expectedGrossCents),
        ];
    }

    /**
     * Execute a billing cycle for a school and target month atomically.
     * Reuses FeeBillingService authoritatively with duplicate & concurrency safety.
     *
     * @throws Throwable
     */
    public static function executeBillingCycle(
        int $schoolId,
        Carbon $targetMonth,
        string $triggeredBy = 'scheduler',
        bool $dryRun = false
    ): array {
        if ($dryRun) {
            return self::previewBillingCycle($schoolId, $targetMonth);
        }

        $targetMonthStr = $targetMonth->format('Y-m');

        // Concurrency lock / claim billing run record
        $claim = DB::transaction(function () use ($schoolId, $targetMonthStr, $triggeredBy) {
            $existing = FeeBillingRun::where('school_id', $schoolId)
                ->where('target_month', $targetMonthStr)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                // If in-flight running in another active worker within last 15 mins, wait/return
                if ($existing->status === 'running' && $existing->started_at && $existing->started_at->gt(now()->subMinutes(15))) {
                    return ['in_flight' => true, 'run' => $existing];
                }

                // Reopen for this execution/catch-up
                $existing->update([
                    'status'        => 'running',
                    'triggered_by'  => $triggeredBy,
                    'started_at'    => now(),
                    'error_summary' => null,
                ]);
                return ['in_flight' => false, 'run' => $existing];
            }

            $newRun = FeeBillingRun::create([
                'school_id'             => $schoolId,
                'target_month'          => $targetMonthStr,
                'billing_period_key'    => $targetMonthStr,
                'status'                => 'running',
                'triggered_by'          => $triggeredBy,
                'started_at'            => now(),
                'execution_count'       => 1,
                'students_scanned'      => 0,
                'challans_created'      => 0,
                'last_challans_created' => 0,
                'challans_skipped'      => 0,
                'total_amount'          => '0.00',
            ]);

            return ['in_flight' => false, 'run' => $newRun];
        });

        $run = $claim['run'];

        if ($claim['in_flight']) {
            return [
                'status'                      => 'in_progress',
                'run_id'                      => $run->id,
                'target_month'                => $targetMonthStr,
                'students_scanned'            => $run->students_scanned,
                'challans_created'            => 0,
                'new_challans_created'        => 0,
                'cumulative_challans'         => $run->challans_created,
                'vouchers_updated'            => 0,
                'last_vouchers_updated'       => $run->last_vouchers_updated ?? 0,
                'cumulative_vouchers_updated' => $run->vouchers_updated ?? 0,
                'charges_appended'            => 0,
                'last_charges_appended'       => $run->last_charges_appended ?? 0,
                'cumulative_charges_appended' => $run->charges_appended ?? 0,
                'challans_skipped'            => 0,
                'total_amount'                => '0.00',
                'cumulative_total_amount'     => $run->total_amount,
                'execution_count'             => $run->execution_count,
            ];
        }

        try {
            $academicYear = AcademicYear::where('school_id', $schoolId)->where('is_current', true)->first()
                ?? AcademicYear::where('school_id', $schoolId)->orderByDesc('start_date')->first();

            if (! $academicYear) {
                throw new \RuntimeException("No active academic year configured for school #{$schoolId}.");
            }

            $settings = self::getSchoolSettings($schoolId);
            $dueDate = self::resolveRecurringDueDate($targetMonth, $settings['recurring_fee_due_day']);
            $billingPeriodKey = "AY{$academicYear->id}-{$targetMonthStr}";

            $run->update([
                'academic_year_id'   => $academicYear->id,
                'billing_period_key' => $billingPeriodKey,
            ]);

            $monthStart = $targetMonth->copy()->startOfMonth();
            $monthEnd   = $targetMonth->copy()->endOfMonth();

            // Load active students with active assignments valid for this target month
            $students = Student::where('school_id', $schoolId)
                ->where('status', 'active')
                ->whereHas('feeAssignments', function ($q) use ($academicYear, $monthStart, $monthEnd) {
                    $q->where('academic_year_id', $academicYear->id)
                        ->where('is_active', true)
                        ->where('starts_on', '<=', $monthEnd->toDateString())
                        ->where(function ($sub) use ($monthStart) {
                            $sub->whereNull('ends_on')
                                ->orWhere('ends_on', '>=', $monthStart->toDateString());
                        });
                })
                ->with(['schoolClass', 'section'])
                ->orderBy('id')
                ->get();

            $createdCount = 0;       // Genuinely new vouchers created in THIS execution
            $vouchersUpdated = 0;    // Existing vouchers updated in THIS execution
            $chargesAppended = 0;    // Charges appended in THIS execution
            $skippedCount = 0;
            $totalPayableCents = 0;

            foreach ($students as $student) {
                $challan = FeeBillingService::createChallan($student, $academicYear, $targetMonth, $dueDate);
                if ($challan) {
                    if (! empty($challan->was_appended)) {
                        $vouchersUpdated++;
                        $chargesAppended += (int) ($challan->appended_items_count ?? 1);
                        $totalPayableCents += (int) ($challan->appended_payable_cents ?? 0);
                    } else {
                        $createdCount++;
                        $totalPayableCents += Money::toCents($challan->total_payable);
                    }
                } else {
                    $skippedCount++;
                }
            }

            $prevCreated = (int) $run->challans_created;
            $prevVouchersUpdated = (int) ($run->vouchers_updated ?? 0);
            $prevChargesAppended = (int) ($run->charges_appended ?? 0);
            $prevTotalCents = Money::toCents($run->total_amount ?? '0.00');

            $cumulativeCreated = $prevCreated + $createdCount;
            $cumulativeVouchersUpdated = $prevVouchersUpdated + $vouchersUpdated;
            $cumulativeChargesAppended = $prevChargesAppended + $chargesAppended;
            $cumulativeTotalCents = $prevTotalCents + $totalPayableCents;
            $execCount = max(1, (int) $run->execution_count + ($run->completed_at ? 1 : 0));

            $run->update([
                'status'                => 'completed',
                'completed_at'          => now(),
                'last_executed_at'      => now(),
                'execution_count'       => $execCount,
                'students_scanned'      => $students->count(),
                'challans_created'      => $cumulativeCreated,
                'last_challans_created' => $createdCount,
                'vouchers_updated'      => $cumulativeVouchersUpdated,
                'last_vouchers_updated' => $vouchersUpdated,
                'charges_appended'      => $cumulativeChargesAppended,
                'last_charges_appended' => $chargesAppended,
                'challans_skipped'      => $skippedCount,
                'total_amount'          => Money::toDecimal($cumulativeTotalCents),
                'error_summary'         => null,
            ]);

            $isAlreadyCompleted = ($createdCount === 0 && $vouchersUpdated === 0 && ($prevCreated > 0 || $prevVouchersUpdated > 0));

            return [
                'status'                      => $isAlreadyCompleted ? 'already_completed' : 'completed',
                'run_id'                      => $run->id,
                'target_month'                => $targetMonthStr,
                'students_scanned'            => $students->count(),
                'challans_created'            => $createdCount, // New vouchers generated in THIS execution
                'new_challans_created'        => $createdCount,
                'cumulative_challans'         => $cumulativeCreated,
                'vouchers_updated'            => $vouchersUpdated,
                'last_vouchers_updated'       => $vouchersUpdated,
                'cumulative_vouchers_updated' => $cumulativeVouchersUpdated,
                'charges_appended'            => $chargesAppended,
                'last_charges_appended'       => $chargesAppended,
                'cumulative_charges_appended' => $cumulativeChargesAppended,
                'challans_skipped'            => $skippedCount,
                'total_amount'                => Money::toDecimal($totalPayableCents), // Amount generated in THIS execution
                'cumulative_total_amount'     => Money::toDecimal($cumulativeTotalCents),
                'execution_count'             => $execCount,
            ];
        } catch (Throwable $e) {
            $safeError = substr($e->getMessage(), 0, 500);
            $run->update([
                'status'        => 'failed',
                'completed_at'  => now(),
                'error_summary' => $safeError,
            ]);

            Log::error("[RECURRING-BILLING] Failed for school #{$schoolId} on month {$targetMonthStr}: " . $e->getMessage());
            throw $e;
        }
    }
}
