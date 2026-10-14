<?php

namespace App\Services;

use App\Models\FeeChallan;
use App\Models\FeePayment;
use App\Models\School;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FeeFinancialSummaryService
{
    /**
     * Resolve the active school's timezone.
     * Priority:
     *   1. School's configured timezone (if valid IANA identifier)
     *   2. Application/shared configured timezone (config('app.timezone'))
     *   3. Safe fallback ('UTC')
     */
    public function resolveSchoolTimezone(int $schoolId): string
    {
        $schoolTimezone = School::where('id', $schoolId)->value('timezone');
        if (! empty($schoolTimezone) && $this->isValidTimezone($schoolTimezone)) {
            return trim($schoolTimezone);
        }

        $appTimezone = config('app.timezone');
        if (! empty($appTimezone) && $this->isValidTimezone($appTimezone)) {
            return trim($appTimezone);
        }

        return 'UTC';
    }

    /**
     * Validate whether a string is a valid IANA timezone identifier.
     */
    public function isValidTimezone(string $tz): bool
    {
        try {
            new \DateTimeZone($tz);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Resolve the current Carbon instant in the school's local calendar.
     */
    public function getSchoolNow(int $schoolId): Carbon
    {
        $tz = $this->resolveSchoolTimezone($schoolId);
        return Carbon::now($tz);
    }

    /**
     * Lifetime cash collected for a school across all time from FeePayment (in integer cents).
     */
    public function lifetimeCollectedCents(int $schoolId): int
    {
        $sum = FeePayment::where('school_id', $schoolId)->sum('amount_paid');
        return Money::toCents($sum);
    }

    /**
     * Cash collected between two dates (inclusive) for a school (in integer cents).
     */
    public function collectedBetweenCents(int $schoolId, Carbon|string $from, Carbon|string $to): int
    {
        $fromDate = $from instanceof Carbon ? $from->toDateString() : (string) $from;
        $toDate   = $to instanceof Carbon ? $to->toDateString() : (string) $to;

        $sum = FeePayment::where('school_id', $schoolId)
            ->whereBetween('payment_date', [$fromDate, $toDate])
            ->sum('amount_paid');

        return Money::toCents($sum);
    }

    /**
     * Cash collected in a given month (in integer cents).
     * Defaults to the school's active local calendar month.
     */
    public function currentMonthCollectedCents(int $schoolId, ?Carbon $month = null): int
    {
        $target = $month ?? $this->getSchoolNow($schoolId);

        $sum = FeePayment::where('school_id', $schoolId)
            ->whereYear('payment_date', $target->year)
            ->whereMonth('payment_date', $target->month)
            ->sum('amount_paid');

        return Money::toCents($sum);
    }

    /**
     * Cash collected today (in integer cents).
     * Defaults to the school's active local calendar date.
     */
    public function todayCollectedCents(int $schoolId, ?Carbon $date = null): int
    {
        $target = $date ? $date->toDateString() : $this->getSchoolNow($schoolId)->toDateString();

        $sum = FeePayment::where('school_id', $schoolId)
            ->whereDate('payment_date', $target)
            ->sum('amount_paid');

        return Money::toCents($sum);
    }

    /**
     * Outstanding balance from modern active challans (status: unpaid or partial) (in integer cents).
     * Void and fully paid challans are strictly excluded.
     * Note: Total payable already reflects post-adjustment net payable; subtracting adjustments
     * a second time would cause double-deduction.
     */
    public function modernOutstandingCents(int $schoolId): int
    {
        $raw = FeeChallan::where('school_id', $schoolId)
            ->whereIn('status', ['unpaid', 'partial'])
            ->selectRaw('COALESCE(SUM(total_payable - paid_amount), 0) as bal')
            ->value('bal');

        return max(0, Money::toCents($raw));
    }

    /**
     * Outstanding balance from legacy fee payments (fee_challan_id IS NULL) (in integer cents).
     */
    public function legacyOutstandingCents(int $schoolId): int
    {
        $raw = FeePayment::where('school_id', $schoolId)
            ->whereNull('fee_challan_id')
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->selectRaw('COALESCE(SUM(amount_due + fine - discount - amount_paid), 0) as bal')
            ->value('bal');

        return max(0, Money::toCents($raw));
    }

    /**
     * Canonical snapshot of total outstanding balance (modern + legacy) (in integer cents).
     */
    public function currentOutstandingCents(int $schoolId): int
    {
        return max(0, $this->modernOutstandingCents($schoolId) + $this->legacyOutstandingCents($schoolId));
    }

    /**
     * Total adjustments tracked on active challans (in integer cents).
     */
    public function totalAdjustmentsCents(int $schoolId): int
    {
        $sum = FeeChallan::where('school_id', $schoolId)->sum('adjustment_amount');
        return Money::toCents($sum);
    }

    /**
     * Monthly collections trend for the last N months.
     * Aggregated in the school's local calendar context.
     * Returns array suitable for charts: [['month' => 'Jan', 'year_month' => '2026-01', 'amount' => 1234.56, 'cents' => 123456], ...]
     */
    public function monthlyCollectionsSeries(int $schoolId, int $months = 6, ?Carbon $asOf = null): array
    {
        $asOfDate = $asOf ?? $this->getSchoolNow($schoolId);
        $startDate = $asOfDate->copy()->subMonths($months - 1)->startOfMonth();

        $grouped = FeePayment::where('school_id', $schoolId)
            ->where('payment_date', '>=', $startDate->toDateString())
            ->where('payment_date', '<=', $asOfDate->copy()->endOfMonth()->toDateString())
            ->selectRaw("DATE_FORMAT(payment_date, '%Y-%m') as ym, SUM(amount_paid) as total_amount")
            ->groupBy('ym')
            ->pluck('total_amount', 'ym');

        $series = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $m = $asOfDate->copy()->subMonths($i);
            $ym = $m->format('Y-m');
            $rawAmount = $grouped->get($ym, 0);
            $cents = Money::toCents($rawAmount);

            $series[] = [
                'month'      => $m->format('M'),
                'year_month' => $ym,
                'amount'     => (float) Money::toDecimal($cents),
                'cents'      => $cents,
            ];
        }

        return $series;
    }

    /**
     * Daily collection series between two dates.
     */
    public function dailyCollectionsSeries(int $schoolId, Carbon|string $from, Carbon|string $to): array
    {
        $fromDate = $from instanceof Carbon ? $from->toDateString() : (string) $from;
        $toDate   = $to instanceof Carbon ? $to->toDateString() : (string) $to;

        $rows = FeePayment::where('school_id', $schoolId)
            ->whereBetween('payment_date', [$fromDate, $toDate])
            ->selectRaw('DATE(payment_date) as day, SUM(amount_paid) as amount')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        return $rows->map(function ($row) {
            $cents = Money::toCents($row->amount);
            return [
                'day'    => (string) $row->day,
                'amount' => (float) Money::toDecimal($cents),
                'cents'  => $cents,
            ];
        })->values()->all();
    }

    /**
     * Summary counts for payments and pending obligations.
     */
    public function counts(int $schoolId): array
    {
        $paidCount = FeePayment::where('school_id', $schoolId)->where('status', 'paid')->count();
        $pendingCount = FeeChallan::where('school_id', $schoolId)->whereIn('status', ['unpaid', 'partial'])->count()
            + FeePayment::where('school_id', $schoolId)->whereNull('fee_challan_id')->whereIn('status', ['pending', 'overdue'])->count();

        return [
            'paid_count'    => $paidCount,
            'pending_count' => $pendingCount,
        ];
    }

    /**
     * Get aggregate statistics for Fee Management page (/school/fees/payments).
     * Preserves exact contract keys.
     */
    public function getFeeManagementStats(int $schoolId): array
    {
        $counts = $this->counts($schoolId);

        return [
            'total_collected'   => Money::toDecimal($this->lifetimeCollectedCents($schoolId)),
            'total_outstanding' => Money::toDecimal($this->currentOutstandingCents($schoolId)),
            'total_adjustments' => Money::toDecimal($this->totalAdjustmentsCents($schoolId)),
            'paid_count'        => $counts['paid_count'],
            'pending_count'     => $counts['pending_count'],
        ];
    }

    /**
     * Get financial statistics for Admin Dashboard.
     */
    public function getDashboardFinancialStats(int $schoolId): array
    {
        $monthCents       = $this->currentMonthCollectedCents($schoolId);
        $outstandingCents = $this->currentOutstandingCents($schoolId);

        return [
            'monthFees'   => (float) Money::toDecimal($monthCents),
            'pendingFees' => (float) Money::toDecimal($outstandingCents),
            'feeChart'    => $this->monthlyCollectionsSeries($schoolId, 6),
        ];
    }

    /**
     * Get financial statistics for Accountant Dashboard.
     */
    public function getAccountantDashboardFinancialStats(int $schoolId): array
    {
        $todayCents       = $this->todayCollectedCents($schoolId);
        $monthCents       = $this->currentMonthCollectedCents($schoolId);
        $outstandingCents = $this->currentOutstandingCents($schoolId);

        return [
            'todayCollection' => (float) Money::toDecimal($todayCents),
            'monthFees'       => (float) Money::toDecimal($monthCents),
            'pendingFees'     => (float) Money::toDecimal($outstandingCents),
            'feeChart'        => $this->monthlyCollectionsSeries($schoolId, 6),
        ];
    }

    /**
     * Get financial statistics for Finance Report.
     */
    public function getFinanceReportStats(int $schoolId, Carbon|string $from, Carbon|string $to): array
    {
        $collectedCents   = $this->collectedBetweenCents($schoolId, $from, $to);
        $outstandingCents = $this->currentOutstandingCents($schoolId);

        return [
            'collected'   => (float) Money::toDecimal($collectedCents),
            'outstanding' => (float) Money::toDecimal($outstandingCents),
            'dailyChart'  => $this->dailyCollectionsSeries($schoolId, $from, $to),
        ];
    }
}
