<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\FeeChallan;
use App\Models\FeeChallanItem;
use App\Models\FeeStructure;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentFeeAssignment;
use App\Models\StudentFeeDiscount;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FeeBillingService
{
    /**
     * Determine which quarter (1-4) a given date falls into, derived strictly
     * from the AcademicYear start_date rather than hardcoded calendar quarters.
     */
    public static function getQuarterForDate(AcademicYear $year, Carbon $date): int
    {
        $startDate = Carbon::parse($year->start_date)->startOfMonth();
        $targetDate = $date->copy()->startOfMonth();

        $diffMonths = $startDate->diffInMonths($targetDate, false);
        if ($diffMonths < 0) {
            return 1;
        }

        $quarterIndex = intdiv((int) $diffMonths, 3) % 4;
        return $quarterIndex + 1; // 1, 2, 3, or 4
    }

    /**
     * Check if a given target month is the start of a quarter.
     */
    public static function isQuarterStartMonth(AcademicYear $year, Carbon $date): ?int
    {
        $startDate = Carbon::parse($year->start_date)->startOfMonth();
        $targetDate = $date->copy()->startOfMonth();

        $diffMonths = $startDate->diffInMonths($targetDate, false);
        if ($diffMonths >= 0 && ($diffMonths % 3 === 0)) {
            $quarterIndex = intdiv((int) $diffMonths, 3) % 4;
            return $quarterIndex + 1;
        }

        return null;
    }

    /**
     * Generate the authoritative charge_period_key for a given structure and target month.
     */
    public static function buildChargePeriodKey(FeeStructure $structure, AcademicYear $year, Carbon $targetMonth): string
    {
        $ayId = $year->id;

        return match ($structure->frequency) {
            'monthly'   => "AY{$ayId}-M-" . $targetMonth->format('Y-m'),
            'quarterly' => "AY{$ayId}-Q" . self::getQuarterForDate($year, $targetMonth),
            'annual'    => "AY{$ayId}-ANNUAL",
            'one_time'  => "ONETIME",
            default     => "AY{$ayId}-M-" . $targetMonth->format('Y-m'),
        };
    }

    /**
     * Check whether a specific fee structure is eligible to be billed for a student on this date.
     */
    public static function isStructureEligible(FeeStructure $structure, Student $student, AcademicYear $year, Carbon $targetMonth): bool
    {
        $chargeKey = self::buildChargePeriodKey($structure, $year, $targetMonth);

        // Check if an active (non-void) challan item with this charge key already exists for this student & structure
        $alreadyBilled = FeeChallanItem::where('school_id', $student->school_id)
            ->where('student_id', $student->id)
            ->where('fee_structure_id', $structure->id)
            ->where('charge_period_key', $chargeKey)
            ->whereNotNull('active_charge_key')
            ->exists();

        if ($alreadyBilled) {
            return false;
        }

        // Frequency-specific eligibility rules:
        if ($structure->frequency === 'quarterly') {
            // Quarterly fee is eligible in ANY month within its derived quarter,
            // provided the student has not already been billed for that quarter (checked above via $alreadyBilled).
            return true;
        }

        if ($structure->frequency === 'annual') {
            // If already billed in this academic year, not eligible
            $annualKey = "AY{$year->id}-ANNUAL";
            return ! FeeChallanItem::where('school_id', $student->school_id)
                ->where('student_id', $student->id)
                ->where('fee_structure_id', $structure->id)
                ->where('charge_period_key', $annualKey)
                ->whereNotNull('active_charge_key')
                ->exists();
        }

        if ($structure->frequency === 'one_time') {
            // If ever billed on an active challan, not eligible
            return ! FeeChallanItem::where('school_id', $student->school_id)
                ->where('student_id', $student->id)
                ->where('fee_structure_id', $structure->id)
                ->where('charge_period_key', 'ONETIME')
                ->whereNotNull('active_charge_key')
                ->exists();
        }

        return true;
    }

    /**
     * Calculate candidate line items with exact integer cents and deterministic discount distribution.
     */
    public static function prepareChallanLines(
        Collection $structures,
        Student $student,
        AcademicYear $year,
        Carbon $targetMonth,
        ?StudentFeeDiscount $discount = null
    ): array {
        $lines = [];
        $totalGrossCents = 0;

        foreach ($structures as $st) {
            if (! self::isStructureEligible($st, $student, $year, $targetMonth)) {
                continue;
            }

            $grossCents = \App\Support\Money::toCents($st->amount);
            if ($grossCents <= 0) {
                continue;
            }

            $chargeKey = self::buildChargePeriodKey($st, $year, $targetMonth);

            $lines[] = [
                'fee_structure_id'  => $st->id,
                'fee_category_id'   => $st->fee_category_id,
                'fee_head_name'     => $st->feeCategory?->name ?? 'Fee Head',
                'charge_period_key' => $chargeKey,
                'gross_cents'       => $grossCents,
                'discount_cents'    => 0,
                'net_cents'         => $grossCents,
            ];

            $totalGrossCents += $grossCents;
        }

        if (empty($lines) || $totalGrossCents <= 0) {
            return [
                'lines'               => [],
                'gross_cents'         => 0,
                'discount_cents'      => 0,
                'discount_title'      => null,
                'total_payable_cents' => 0,
            ];
        }

        // Apply discount if provided and active
        $discountCentsTotal = 0;
        $discountTitle = null;

        if ($discount && $discount->is_active) {
            $discountTitle = $discount->title;

            if ($discount->fee_category_id) {
                // Category-specific discount
                foreach ($lines as &$line) {
                    if ($line['fee_category_id'] === $discount->fee_category_id) {
                        $disc = $discount->calculateDiscountCents($line['gross_cents']);
                        $line['discount_cents'] = $disc;
                        $line['net_cents'] = $line['gross_cents'] - $disc;
                        $discountCentsTotal += $disc;
                    }
                }
                unset($line);
            } else {
                // Global all-head discount
                if ($discount->type === 'percentage') {
                    $percentValueHundredths = \App\Support\Money::toCents($discount->value);
                    $allocatedSum = 0;

                    foreach ($lines as &$line) {
                        $disc = intdiv($line['gross_cents'] * $percentValueHundredths + 5000, 10000);
                        $disc = min($disc, $line['gross_cents']);
                        $line['discount_cents'] = $disc;
                        $line['net_cents'] = $line['gross_cents'] - $disc;
                        $allocatedSum += $disc;
                    }
                    unset($line);

                    // Reconcile total rounding drift to the largest line item
                    $targetTotalDisc = intdiv($totalGrossCents * $percentValueHundredths + 5000, 10000);
                    $targetTotalDisc = min($targetTotalDisc, $totalGrossCents);
                    $drift = $targetTotalDisc - $allocatedSum;

                    if ($drift !== 0 && ! empty($lines)) {
                        $maxKey = self::findLargestLineIndex($lines);
                        $lines[$maxKey]['discount_cents'] += $drift;
                        $lines[$maxKey]['net_cents'] = $lines[$maxKey]['gross_cents'] - $lines[$maxKey]['discount_cents'];
                    }

                    $discountCentsTotal = $targetTotalDisc;
                } else {
                    // Fixed all-head discount: proportional allocation using integer cents
                    $fixedTotal = min(\App\Support\Money::toCents($discount->value), $totalGrossCents);
                    $allocatedSum = 0;

                    foreach ($lines as &$line) {
                        $share = intdiv($line['gross_cents'] * $fixedTotal, $totalGrossCents);
                        $share = min($share, $line['gross_cents']);
                        $line['discount_cents'] = $share;
                        $line['net_cents'] = $line['gross_cents'] - $share;
                        $allocatedSum += $share;
                    }
                    unset($line);

                    // Remainder cents allocated to largest line item
                    $remainder = $fixedTotal - $allocatedSum;
                    if ($remainder > 0 && ! empty($lines)) {
                        $maxKey = self::findLargestLineIndex($lines);
                        $lines[$maxKey]['discount_cents'] += $remainder;
                        $lines[$maxKey]['net_cents'] = $lines[$maxKey]['gross_cents'] - $lines[$maxKey]['discount_cents'];
                    }

                    $discountCentsTotal = $fixedTotal;
                }
            }
        }

        $totalPayableCents = max(0, $totalGrossCents - $discountCentsTotal);

        return [
            'lines'               => $lines,
            'gross_cents'         => $totalGrossCents,
            'discount_cents'      => $discountCentsTotal,
            'discount_title'      => $discountTitle,
            'total_payable_cents' => $totalPayableCents,
        ];
    }

    private static function findLargestLineIndex(array $lines): int
    {
        $maxKey = 0;
        $maxVal = -1;
        foreach ($lines as $k => $line) {
            if ($line['gross_cents'] > $maxVal) {
                $maxVal = $line['gross_cents'];
                $maxKey = $k;
            }
        }
        return $maxKey;
    }

    /**
     * Compute previous unpaid balance for a student as an informational memo.
     * Option A: independent invoices (NOT capitalized into new total_payable).
     */
    public static function computePreviousOutstandingCents(Student $student): int
    {
        // 1. Unpaid / partial modern challans
        $challanBalanceCents = 0;
        $challans = FeeChallan::where('school_id', $student->school_id)
            ->where('student_id', $student->id)
            ->whereIn('status', ['unpaid', 'partial'])
            ->get(['total_payable', 'paid_amount']);

        foreach ($challans as $ch) {
            $due = \App\Support\Money::toCents($ch->total_payable);
            $paid = \App\Support\Money::toCents($ch->paid_amount);
            $challanBalanceCents += max(0, $due - $paid);
        }

        // 2. Legacy pending/partial fee payments without a challan
        $legacyPayments = \App\Models\FeePayment::where('school_id', $student->school_id)
            ->where('student_id', $student->id)
            ->whereNull('fee_challan_id')
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->get(['amount_due', 'amount_paid', 'discount', 'fine']);

        foreach ($legacyPayments as $lp) {
            $due = \App\Support\Money::toCents($lp->amount_due) + \App\Support\Money::toCents($lp->fine) - \App\Support\Money::toCents($lp->discount);
            $paid = \App\Support\Money::toCents($lp->amount_paid);
            $challanBalanceCents += max(0, $due - $paid);
        }

        return $challanBalanceCents;
    }

    /**
     * Authoritatively resolve all eligible fee structures for a student in a billing period.
     * Enforces the transition rule between legacy uninitialized students and assignment-initialized students.
     *
     * Case A (Legacy / Uninitialized):
     *   No StudentFeeAssignment records have EVER existed for this student + academic year.
     *   Fallback to all active mandatory class fee structures (is_optional = false).
     *
     * Case B (Initialized):
     *   One or more StudentFeeAssignment records exist (even if deactivated).
     *   Bill ONLY active assignments valid for the billing period.
     *   NEVER fall back to class mandatory fees if all assignments are inactive.
     */
    public static function resolveBillableStructures(Student $student, AcademicYear $academicYear, Carbon $targetMonth): Collection
    {
        $sid = $student->school_id;

        // Determine if the assignment system has been initialized for this student and academic year
        $hasAnyAssignments = StudentFeeAssignment::where('school_id', $sid)
            ->where('student_id', $student->id)
            ->where('academic_year_id', $academicYear->id)
            ->exists();

        if (! $hasAnyAssignments) {
            // Case A: Legacy student fallback -> All active mandatory structures for class & academic year
            $structures = FeeStructure::with('feeCategory:id,name')
                ->where('school_id', $sid)
                ->where('class_id', $student->class_id)
                ->where('academic_year', $academicYear->name)
                ->where('is_active', true)
                ->where('is_optional', false)
                ->get();

            if ($structures->isEmpty()) {
                // Fallback: search without academic_year if none found (legacy compatibility)
                $structures = FeeStructure::with('feeCategory:id,name')
                    ->where('school_id', $sid)
                    ->where('class_id', $student->class_id)
                    ->where('is_active', true)
                    ->where('is_optional', false)
                    ->get();
            }

            return $structures;
        }

        // Case B: Initialized student -> Only active assignments valid for the target month
        $monthStart = $targetMonth->copy()->startOfMonth()->toDateString();
        $monthEnd   = $targetMonth->copy()->endOfMonth()->toDateString();

        $activeAssignments = StudentFeeAssignment::where('school_id', $sid)
            ->where('student_id', $student->id)
            ->where('academic_year_id', $academicYear->id)
            ->where('is_active', true)
            ->where('starts_on', '<=', $monthEnd)
            ->where(function ($query) use ($monthStart) {
                $query->whereNull('ends_on')
                    ->orWhere('ends_on', '>=', $monthStart);
            })
            ->with(['feeStructure.feeCategory:id,name'])
            ->get();

        if ($activeAssignments->isEmpty()) {
            // Invariant: Do NOT fall back to class fees if initialized but inactive
            return collect();
        }

        $structures = collect();
        foreach ($activeAssignments as $assignment) {
            $st = $assignment->feeStructure;
            if ($st && $st->is_active && $st->school_id === $sid && $st->class_id === $student->class_id) {
                $structures->push($st);
            }
        }

        return $structures;
    }

    /**
     * Generate an issued FeeChallan with line items atomically.
     */
    public static function createChallan(
        Student $student,
        AcademicYear $academicYear,
        Carbon $targetMonth,
        ?Carbon $dueDate = null,
        ?StudentFeeDiscount $discount = null,
        bool $asDraft = false
    ): ?FeeChallan {
        $sid = $student->school_id;
        $dueDate = $dueDate ?? $targetMonth->copy()->endOfMonth();

        // 1. Authoritatively resolve eligible fee structures
        $structures = self::resolveBillableStructures($student, $academicYear, $targetMonth);

        if ($structures->isEmpty()) {
            return null;
        }

        // If no explicit discount passed, check student's active discounts
        if (! $discount) {
            $discount = StudentFeeDiscount::where('school_id', $sid)
                ->where('student_id', $student->id)
                ->where('is_active', true)
                ->first();
        }

        // 2. Prepare line items with exact cents
        $prepared = self::prepareChallanLines($structures, $student, $academicYear, $targetMonth, $discount);
        if (empty($prepared['lines']) || $prepared['total_payable_cents'] <= 0) {
            return null;
        }

        $billingPeriodKey = "AY{$academicYear->id}-" . $targetMonth->format('Y-m');
        $activePeriodKey = "{$student->id}_{$billingPeriodKey}";

        // Prevent duplicate active challan
        $exists = FeeChallan::where('school_id', $sid)
            ->where('active_period_key', $activePeriodKey)
            ->exists();

        if ($exists) {
            return null;
        }

        // 3. Compute informational memo for prior debt
        $prevOutstandingCents = self::computePreviousOutstandingCents($student);

        // 4. Generate unique challan number
        $challanNo = DocumentSequenceService::nextNumber($sid, 'challan', 'CHL', (int) $targetMonth->format('Y'));

        $status = $asDraft ? 'draft' : 'unpaid';
        $issuedAt = $asDraft ? null : now();

        try {
            return DB::transaction(function () use (
                $sid, $student, $academicYear, $targetMonth, $dueDate,
                $challanNo, $billingPeriodKey, $activePeriodKey,
                $prepared, $prevOutstandingCents, $status, $issuedAt
            ) {
                $challan = FeeChallan::create([
                    'school_id'                     => $sid,
                    'challan_no'                    => $challanNo,
                    'billing_period_key'            => $billingPeriodKey,
                    'active_period_key'             => $activePeriodKey,
                    'student_id'                    => $student->id,
                    'class_id'                      => $student->class_id,
                    'section_id'                    => $student->section_id,
                    'academic_year_id'              => $academicYear->id,
                    'student_name'                  => $student->full_name,
                    'admission_no'                  => $student->admission_no,
                    'class_name'                    => $student->schoolClass?->name ?? 'Class',
                    'section_name'                  => $student->section?->name,
                    'academic_year_name'            => $academicYear->name,
                    'issue_date'                    => $targetMonth->copy()->startOfMonth(),
                    'due_date'                      => $dueDate,
                    'issued_at'                     => $issuedAt,
                    'gross_amount'                  => \App\Support\Money::toDecimal($prepared['gross_cents']),
                    'discount_amount'               => \App\Support\Money::toDecimal($prepared['discount_cents']),
                    'discount_title'                => $prepared['discount_title'],
                    'fine_amount'                   => '0.00',
                    'total_payable'                 => \App\Support\Money::toDecimal($prepared['total_payable_cents']),
                    'paid_amount'                   => '0.00',
                    'previous_outstanding_snapshot'  => \App\Support\Money::toDecimal($prevOutstandingCents),
                    'status'                        => $status,
                ]);

                foreach ($prepared['lines'] as $line) {
                    $activeChargeKey = "{$student->id}_{$line['fee_structure_id']}_{$line['charge_period_key']}";

                    FeeChallanItem::create([
                        'fee_challan_id'    => $challan->id,
                        'school_id'         => $sid,
                        'student_id'        => $student->id,
                        'fee_structure_id'  => $line['fee_structure_id'],
                        'charge_period_key' => $line['charge_period_key'],
                        'active_charge_key' => $activeChargeKey,
                        'fee_head_name'     => $line['fee_head_name'],
                        'gross_amount'      => \App\Support\Money::toDecimal($line['gross_cents']),
                        'discount_amount'   => \App\Support\Money::toDecimal($line['discount_cents']),
                        'net_amount'        => \App\Support\Money::toDecimal($line['net_cents']),
                    ]);
                }

                return $challan;
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if (self::isUniqueConstraintViolation($e)) {
                // Concurrent race resulted in duplicate active_period_key or active_charge_key.
                // Safely treat as already generated / skipped without crashing with an unhandled 500 error.
                return null;
            }
            throw $e;
        }
    }

    /**
     * Check if a database exception is specifically a unique constraint violation.
     */
    public static function isUniqueConstraintViolation(\Illuminate\Database\QueryException $e): bool
    {
        $code = (string) $e->getCode();
        $msg = strtolower($e->getMessage());

        if ($code === '23000' || $code === '23505') {
            return true;
        }

        if (str_contains($msg, 'unique constraint failed') || str_contains($msg, 'duplicate entry')) {
            return true;
        }

        return false;
    }

    /**
     * Generate bulk challans for all active students in a class / section.
     */
    public static function generateBulkChallans(
        int $schoolId,
        int $classId,
        ?int $sectionId,
        AcademicYear $academicYear,
        Carbon $targetMonth,
        ?Carbon $dueDate = null
    ): array {
        $students = Student::where('school_id', $schoolId)
            ->where('class_id', $classId)
            ->when($sectionId, fn ($q) => $q->where('section_id', $sectionId))
            ->where('status', 'active')
            ->with(['schoolClass:id,name', 'section:id,name'])
            ->get();

        $generated = [];
        $skippedCount = 0;

        foreach ($students as $student) {
            $challan = self::createChallan($student, $academicYear, $targetMonth, $dueDate);
            if ($challan) {
                $generated[] = $challan;
            } else {
                $skippedCount++;
            }
        }

        return [
            'generated_count' => count($generated),
            'skipped_count'   => $skippedCount,
            'challans'        => $generated,
        ];
    }
}
