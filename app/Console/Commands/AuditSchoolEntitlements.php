<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\SchoolModule;
use App\Services\SchoolEntitlementResolver;
use Illuminate\Console\Command;

class AuditSchoolEntitlements extends Command
{
    protected $signature = 'entitlement:audit
                            {--school= : Specific school ID to audit}
                            {--all : Audit all schools (default)}';

    protected $description = 'Read-only diagnostic audit of tenant school subscriptions, module entitlements, and would-be ENFORCE outcomes.';

    public function handle(SchoolEntitlementResolver $resolver): int
    {
        $schoolId = $this->option('school');

        $schools = collect();

        if ($schoolId) {
            $school = School::find((int) $schoolId);
            if (! $school) {
                $this->error("School with ID {$schoolId} not found.");
                return self::FAILURE;
            }
            $schools->push($school);
        } else {
            $schools = School::orderBy('id')->get();
        }

        if ($schools->isEmpty()) {
            $this->info('No schools found.');
            return self::SUCCESS;
        }

        $canonical = config('modules.canonical', []);
        $totalModules = count($canonical);

        $this->info("=== READ-ONLY TENANT ENTITLEMENT AUDIT ({$schools->count()} Schools) ===");
        $this->line("Operating Mode in config: " . config('entitlement.mode', 'off'));

        $rows = [];

        foreach ($schools as $school) {
            $subResult   = $resolver->checkSubscription($school);
            $subModel    = $resolver->resolveSubscription($school);
            $effective   = $resolver->getEffectiveModules($school);
            $overrides   = SchoolModule::where('school_id', $school->id)->count();

            $enabledCount = count(array_filter($effective));

            // Determine ENFORCE outcome
            $enforceOutcome = 'BLOCKED (403)';
            if ($subResult->isEntitled) {
                if ($enabledCount === $totalModules) {
                    $enforceOutcome = 'ALLOWED (All Access)';
                } else {
                    $enforceOutcome = "PARTIAL ({$enabledCount}/{$totalModules} Modules)";
                }
            }

            $this->line("School #{$school->id} [{$school->name}]: Sub Status: {$subResult->reason} | Effective: {$enabledCount}/{$totalModules} | ENFORCE Outcome: {$enforceOutcome}");

            $rows[] = [
                'ID'              => $school->id,
                'Name'            => $school->name,
                'Status'          => $school->status,
                'Sub Status'      => $subResult->reason,
                'Package'         => $subModel?->package?->name ?? 'None',
                'Dates'           => $subModel ? "{$subModel->start_date?->format('Y-m-d')} to {$subModel->end_date?->format('Y-m-d')}" : 'N/A',
                'Overrides'       => $overrides > 0 ? "{$overrides} custom" : '0',
                'Effective'       => "{$enabledCount}/{$totalModules}",
                'Under ENFORCE'   => $enforceOutcome,
            ];
        }

        $this->table([
            'ID', 'Name', 'Status', 'Sub Status', 'Package', 'Dates', 'Overrides', 'Effective', 'Under ENFORCE',
        ], $rows);

        $this->info('Audit completed with zero database writes.');

        return self::SUCCESS;
    }
}
