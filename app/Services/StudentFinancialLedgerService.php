<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\FeeChallan;
use App\Models\FeePayment;
use App\Models\Student;
use App\Models\StudentFeeAssignment;
use App\Models\StudentFeeDiscount;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class StudentFinancialLedgerService
{
    /**
     * Resolve start of today in the school's configured timezone.
     */
    public static function getSchoolToday(Student $student): Carbon
    {
        $timezone = $student->school?->timezone ?: config('app.timezone', 'Asia/Karachi');
        try {
            return Carbon::now($timezone)->startOfDay();
        } catch (\Throwable) {
            return Carbon::now(config('app.timezone', 'Asia/Karachi'))->startOfDay();
        }
    }

    /**
     * Compute authoritative integer-cents financial summary for a student.
     * Reconciles modern challans and legacy direct fee payments without double counting.
     */
    public static function summary(Student $student, ?AcademicYear $academicYear = null): array
    {
        $sid = $student->school_id;
        $today = self::getSchoolToday($student);

        // 1. Modern Challans (Billed, Outstanding, Overdue)
        $challanQuery = FeeChallan::where('school_id', $sid)
            ->where('student_id', $student->id)
            ->where('status', '!=', 'void');

        if ($academicYear) {
            $challanQuery->where('academic_year_id', $academicYear->id);
        }

        $challans = $challanQuery->get(['id', 'total_payable', 'paid_amount', 'status', 'due_date']);

        $modernBilledCents = 0;
        $modernOutstandingCents = 0;
        $modernOverdueCents = 0;

        foreach ($challans as $ch) {
            $payableCents = Money::toCents($ch->total_payable);
            $paidCents    = Money::toCents($ch->paid_amount);
            $openCents    = max(0, $payableCents - $paidCents);

            $modernBilledCents += $payableCents;

            if (in_array($ch->status, ['unpaid', 'partial']) && $openCents > 0) {
                $modernOutstandingCents += $openCents;

                if ($ch->due_date && Carbon::parse($ch->due_date)->lt($today)) {
                    $modernOverdueCents += $openCents;
                }
            }
        }

        // 2. Legacy Direct Payments (Obligations where fee_challan_id IS NULL)
        $legacyPayments = FeePayment::where('school_id', $sid)
            ->where('student_id', $student->id)
            ->whereNull('fee_challan_id')
            ->get(['amount_due', 'amount_paid', 'discount', 'fine', 'status', 'payment_date']);

        $legacyBilledCents = 0;
        $legacyOutstandingCents = 0;
        $legacyOverdueCents = 0;

        foreach ($legacyPayments as $lp) {
            $dueCents  = Money::toCents($lp->amount_due) + Money::toCents($lp->fine) - Money::toCents($lp->discount);
            $paidCents = Money::toCents($lp->amount_paid);
            $openCents = max(0, $dueCents - $paidCents);

            $legacyBilledCents += $dueCents;

            if (in_array($lp->status, ['pending', 'partial', 'overdue']) && $openCents > 0) {
                $legacyOutstandingCents += $openCents;

                if ($lp->status === 'overdue' || ($lp->payment_date && Carbon::parse($lp->payment_date)->lt($today))) {
                    $legacyOverdueCents += $openCents;
                }
            }
        }

        // 3. Total Payments / Receipts (Modern linked payments + Legacy standalone payments)
        $paymentQuery = FeePayment::where('school_id', $sid)
            ->where('student_id', $student->id)
            ->whereIn('status', ['paid', 'partial']);

        if ($academicYear) {
            $paymentQuery->where(function ($q) use ($academicYear) {
                $q->whereHas('feeChallan', function ($cq) use ($academicYear) {
                    $cq->where('academic_year_id', $academicYear->id);
                })->orWhere(function ($lq) use ($academicYear) {
                    $lq->whereNull('fee_challan_id')
                       ->where('month_year', 'like', "%{$academicYear->name}%");
                });
            });
        }

        $allPayments = $paymentQuery->get(['amount_paid']);
        $totalPaidCents = 0;
        foreach ($allPayments as $p) {
            $totalPaidCents += Money::toCents($p->amount_paid);
        }

        // 4. Counts
        $assignmentsCount = StudentFeeAssignment::where('school_id', $sid)
            ->where('student_id', $student->id)
            ->when($academicYear, fn ($q) => $q->where('academic_year_id', $academicYear->id))
            ->where('is_active', true)
            ->count();

        $concessionsCount = StudentFeeDiscount::where('school_id', $sid)
            ->where('student_id', $student->id)
            ->when($academicYear, fn ($q) => $q->where('academic_year_id', $academicYear->id))
            ->where('is_active', true)
            ->count();

        $totalBilledCents      = $modernBilledCents + $legacyBilledCents;
        $totalOutstandingCents = $modernOutstandingCents + $legacyOutstandingCents;
        $totalOverdueCents     = $modernOverdueCents + $legacyOverdueCents;

        return [
            'total_billed_cents'       => $totalBilledCents,
            'total_billed'             => Money::toDecimal($totalBilledCents),
            'total_paid_cents'         => $totalPaidCents,
            'total_paid'               => Money::toDecimal($totalPaidCents),
            'outstanding_cents'        => $totalOutstandingCents,
            'outstanding_balance'      => Money::toDecimal($totalOutstandingCents),
            'overdue_cents'            => $totalOverdueCents,
            'overdue_balance'          => Money::toDecimal($totalOverdueCents),
            'is_overdue'               => $totalOverdueCents > 0,
            'active_assignments_count' => $assignmentsCount,
            'active_concessions_count' => $concessionsCount,
        ];
    }

    /**
     * Retrieve all challans with derived display status and remaining balance.
     */
    public static function challans(Student $student, array $filters = []): Collection
    {
        $today = self::getSchoolToday($student);

        $query = FeeChallan::where('school_id', $student->school_id)
            ->where('student_id', $student->id)
            ->with(['items.feeStructure.feeCategory', 'payments'])
            ->latest('issue_date');

        if (! empty($filters['academic_year_id'])) {
            $query->where('academic_year_id', $filters['academic_year_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->get()->map(function (FeeChallan $ch) use ($today) {
            $payableCents = Money::toCents($ch->total_payable);
            $paidCents    = Money::toCents($ch->paid_amount);
            $balanceCents = max(0, $payableCents - $paidCents);

            $isOverdue = (! in_array($ch->status, ['paid', 'void']))
                && $balanceCents > 0
                && $ch->due_date
                && Carbon::parse($ch->due_date)->lt($today);

            $displayStatus = ($ch->status === 'unpaid' && $isOverdue) ? 'overdue' : $ch->status;

            $ch->derived_balance_cents = $balanceCents;
            $ch->derived_balance       = Money::toDecimal($balanceCents);
            $ch->is_overdue            = $isOverdue;
            $ch->display_status        = $displayStatus;

            return $ch;
        });
    }

    /**
     * Retrieve all payment transactions and receipts for a student.
     */
    public static function payments(Student $student, array $filters = []): Collection
    {
        $query = FeePayment::where('school_id', $student->school_id)
            ->where('student_id', $student->id)
            ->with(['feeChallan', 'feeStructure.feeCategory', 'collector:id,name'])
            ->orderBy('payment_date', 'desc')
            ->orderBy('id', 'desc');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->get();
    }

    /**
     * Retrieve active fee assignments for a student.
     */
    public static function activeAssignments(Student $student, ?AcademicYear $academicYear = null): Collection
    {
        return StudentFeeAssignment::where('school_id', $student->school_id)
            ->where('student_id', $student->id)
            ->when($academicYear, fn ($q) => $q->where('academic_year_id', $academicYear->id))
            ->where('is_active', true)
            ->with(['feeStructure.feeCategory', 'assignedBy:id,name', 'academicYear'])
            ->get();
    }

    /**
     * Retrieve active fee concessions for a student.
     */
    public static function activeDiscounts(Student $student, ?AcademicYear $academicYear = null): Collection
    {
        return StudentFeeDiscount::where('school_id', $student->school_id)
            ->where('student_id', $student->id)
            ->when($academicYear, fn ($q) => $q->where('academic_year_id', $academicYear->id))
            ->where('is_active', true)
            ->with(['feeCategory', 'academicYear'])
            ->get();
    }

    /**
     * Unified chronological statement of debits (issued invoices) and credits (receipts)
     * with running balance in integer cents.
     */
    public static function ledgerEntries(Student $student, ?AcademicYear $academicYear = null): Collection
    {
        $entries = collect();

        // 1. Debits: Non-void challans
        $challanQuery = FeeChallan::where('school_id', $student->school_id)
            ->where('student_id', $student->id)
            ->where('status', '!=', 'void');

        if ($academicYear) {
            $challanQuery->where('academic_year_id', $academicYear->id);
        }

        foreach ($challanQuery->get() as $ch) {
            $payableCents = Money::toCents($ch->total_payable);
            $entries->push([
                'id'           => "CHL-{$ch->id}",
                'date'         => Carbon::parse($ch->issue_date)->toDateString(),
                'type'         => 'debit',
                'category'     => 'Fee Challan',
                'reference'    => $ch->challan_no,
                'description'  => "Fee Challan {$ch->challan_no} ({$ch->academic_year_name})",
                'debit_cents'  => $payableCents,
                'credit_cents' => 0,
                'debit'        => Money::toDecimal($payableCents),
                'credit'       => '0.00',
                'status'       => $ch->status,
                'created_at'   => $ch->created_at,
            ]);
        }

        // 2. Credits: Payments
        $paymentQuery = FeePayment::where('school_id', $student->school_id)
            ->where('student_id', $student->id)
            ->whereIn('status', ['paid', 'partial']);

        if ($academicYear) {
            $paymentQuery->where(function ($q) use ($academicYear) {
                $q->whereHas('feeChallan', fn ($cq) => $cq->where('academic_year_id', $academicYear->id))
                  ->orWhere(fn ($lq) => $lq->whereNull('fee_challan_id')->where('month_year', 'like', "%{$academicYear->name}%"));
            });
        }

        foreach ($paymentQuery->get() as $p) {
            $paidCents = Money::toCents($p->amount_paid);
            $entries->push([
                'id'           => "RCP-{$p->id}",
                'date'         => Carbon::parse($p->payment_date)->toDateString(),
                'type'         => 'credit',
                'category'     => 'Payment Receipt',
                'reference'    => $p->receipt_no,
                'description'  => "Payment Receipt {$p->receipt_no} (" . ucfirst($p->method ?? 'cash') . ")",
                'debit_cents'  => 0,
                'credit_cents' => $paidCents,
                'debit'        => '0.00',
                'credit'       => Money::toDecimal($paidCents),
                'status'       => $p->status,
                'created_at'   => $p->created_at,
            ]);
        }

        // 3. Sort chronologically
        $sorted = $entries->sortBy(function ($e) {
            return $e['date'] . '_' . ($e['type'] === 'debit' ? '0' : '1') . '_' . $e['id'];
        })->values();

        // 4. Calculate running balance in exact integer cents
        $runningBalanceCents = 0;
        return $sorted->map(function ($entry) use (&$runningBalanceCents) {
            $runningBalanceCents += ($entry['debit_cents'] - $entry['credit_cents']);
            $entry['running_balance_cents'] = $runningBalanceCents;
            $entry['running_balance']       = Money::toDecimal($runningBalanceCents);
            return (object) $entry;
        });
    }
}
