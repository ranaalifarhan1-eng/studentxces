<?php

namespace App\Console\Commands;

use App\Jobs\ProcessSchoolRecurringBillingJob;
use App\Models\FeeBillingRun;
use App\Models\School;
use App\Services\RecurringFeeBillingService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RunRecurringBillingCycleCommand extends Command
{
    protected $signature = 'fees:run-billing-cycle
        {--school= : Process only a specific school ID}
        {--month= : Target billing month in YYYY-MM format}
        {--dry-run : Calculate and report only, create zero records}
        {--sync : Run immediately in the foreground instead of queuing}';

    protected $description = 'Execute automated recurring fee billing cycle for eligible schools';

    public function handle(): int
    {
        $schoolId = $this->option('school');
        $monthOption = $this->option('month');
        $dryRun = (bool) $this->option('dry-run');
        $sync = (bool) $this->option('sync');

        if ($monthOption && ! preg_match('/^\d{4}-\d{2}$/', (string) $monthOption)) {
            $this->error("Invalid month format '{$monthOption}'. Expected YYYY-MM (e.g. 2026-11).");
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn("=== DRY-RUN MODE: ZERO DATABASE RECORDS WILL BE MUTATED ===");
        }

        if ($schoolId) {
            $school = School::find((int) $schoolId);
            if (! $school) {
                $this->error("School #{$schoolId} not found.");
                return self::FAILURE;
            }

            return $this->processSingleSchool($school, $monthOption, $dryRun, $sync);
        }

        return $this->discoverAndProcessSchools($monthOption, $dryRun, $sync);
    }

    protected function processSingleSchool(School $school, ?string $monthOption, bool $dryRun, bool $sync): int
    {
        $tz = $school->timezone ?: config('app.timezone', 'Asia/Karachi');
        $targetMonth = $monthOption ? Carbon::parse($monthOption) : Carbon::now($tz)->startOfMonth();
        $targetMonthStr = $targetMonth->format('Y-m');

        $this->info("Targeting School: #{$school->id} ({$school->name}) | Month: {$targetMonthStr}");

        if ($dryRun) {
            $preview = RecurringFeeBillingService::previewBillingCycle($school->id, $targetMonth);
            $this->renderPreviewTable([$preview]);
            return self::SUCCESS;
        }

        if ($sync) {
            $this->info("Processing synchronously in foreground...");
            $result = RecurringFeeBillingService::executeBillingCycle($school->id, $targetMonth, 'manual');
            $this->info("Status: {$result['status']} | Created: {$result['challans_created']} | Skipped: {$result['challans_skipped']} | Total: PKR {$result['total_amount']}");
            return self::SUCCESS;
        }

        // Check if already completed and no unbilled students exist
        $completed = FeeBillingRun::where('school_id', $school->id)
            ->where('target_month', $targetMonthStr)
            ->where('status', 'completed')
            ->first();

        $academicYear = \App\Models\AcademicYear::where('school_id', $school->id)->where('is_current', true)->first()
            ?? \App\Models\AcademicYear::where('school_id', $school->id)->orderByDesc('start_date')->first();

        if ($completed && $academicYear && ! RecurringFeeBillingService::hasUnbilledEligibleStudents($school, $academicYear, $targetMonth)) {
            $this->warn("Billing run for {$targetMonthStr} has already completed on {$completed->completed_at}. All eligible students already billed.");
            return self::SUCCESS;
        }

        ProcessSchoolRecurringBillingJob::dispatch($school->id, $targetMonthStr, 'manual');
        $this->info("Dispatched tenant recurring billing job to queue for school #{$school->id}.");
        return self::SUCCESS;
    }

    protected function discoverAndProcessSchools(?string $monthOption, bool $dryRun, bool $sync): int
    {
        $schools = School::where('status', 'active')->orderBy('id')->get();
        $this->info("Scanning " . $schools->count() . " active school(s) for recurring billing eligibility...");

        $previews = [];
        $dispatchedCount = 0;
        $skippedCount = 0;

        foreach ($schools as $school) {
            $tz = $school->timezone ?: config('app.timezone', 'Asia/Karachi');
            $now = Carbon::now($tz);
            $targetMonth = $monthOption ? Carbon::parse($monthOption) : $now->copy()->startOfMonth();
            $targetMonthStr = $targetMonth->format('Y-m');

            $isEligible = RecurringFeeBillingService::isSchoolEligibleForCycle($school, $now);

            if (! $isEligible) {
                $skippedCount++;
                continue;
            }

            if ($dryRun) {
                $previews[] = RecurringFeeBillingService::previewBillingCycle($school->id, $targetMonth);
                continue;
            }

            if ($sync) {
                $this->info("Processing school #{$school->id} ({$school->name})...");
                RecurringFeeBillingService::executeBillingCycle($school->id, $targetMonth, 'scheduler');
                $dispatchedCount++;
            } else {
                ProcessSchoolRecurringBillingJob::dispatch($school->id, $targetMonthStr, 'scheduler');
                $dispatchedCount++;
            }
        }

        if ($dryRun) {
            if (empty($previews)) {
                $this->info("No schools are currently eligible for recurring billing.");
            } else {
                $this->renderPreviewTable($previews);
            }
            return self::SUCCESS;
        }

        $this->info("Completed scan: {$dispatchedCount} school job(s) dispatched, {$skippedCount} school(s) skipped (disabled, before billing day, or already billed).");
        return self::SUCCESS;
    }

    protected function renderPreviewTable(array $previews): void
    {
        $headers = ['School ID', 'School Name', 'Target Month', 'Due Date', 'Students', 'Expected Challans', 'Duplicates Skipped', 'Expected Gross'];
        $rows = [];

        foreach ($previews as $p) {
            $rows[] = [
                $p['school_id'] ?? '—',
                $p['school_name'] ?? '—',
                $p['target_month'] ?? '—',
                $p['due_date'] ?? '—',
                $p['eligible_students_count'] ?? 0,
                $p['expected_challans_count'] ?? 0,
                $p['skipped_duplicates_count'] ?? 0,
                'PKR ' . number_format((float) ($p['expected_gross_amount'] ?? 0), 2),
            ];
        }

        $this->table($headers, $rows);
    }
}
