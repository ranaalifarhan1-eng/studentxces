<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\StaffDocument;
use App\Models\StudentDocument;
use App\Services\TenantStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MigrateTenantStorage extends Command
{
    protected $signature = 'tenant:migrate-storage 
                            {--dry-run : Simulate the migration without moving files or updating records}
                            {--rollback : Roll back previous migration moving files back to legacy paths}';

    protected $description = 'Idempotently migrate legacy unpartitioned storage paths to tenant-partitioned paths';

    public function handle(): int
    {
        $dryRun   = $this->option('dry-run');
        $rollback = $this->option('rollback');

        $mode = $rollback ? 'ROLLBACK' : ($dryRun ? 'DRY-RUN' : 'LIVE MIGRATION');
        $this->info("=== Tenant Storage Migration [{$mode}] ===");

        $privateDisk = TenantStorage::privateDisk();
        $publicDisk  = TenantStorage::publicDisk();

        $processed = 0;
        $skipped   = 0;
        $errors    = 0;

        // 1. Student Documents (Private Disk)
        $this->line("\n--- Inspecting Student Documents ---");
        $studentDocs = StudentDocument::withoutGlobalScopes()->get();

        foreach ($studentDocs as $doc) {
            $currentPath = $doc->file_path;
            $newPath = TenantStorage::studentDocumentPath($doc->school_id, $doc->student_id, basename($currentPath));
            $legacyPath = "students/{$doc->student_id}/documents/" . basename($currentPath);

            $source = $rollback ? $newPath : $currentPath;
            $target = $rollback ? $legacyPath : $newPath;

            if ($source === $target) {
                $this->line("[SKIP] StudentDoc #{$doc->id} already at {$target}");
                $skipped++;
                continue;
            }

            if (! $privateDisk->exists($source)) {
                $this->warn("[MISSING] StudentDoc #{$doc->id} source file not found at [{$source}]");
                $skipped++;
                continue;
            }

            if ($privateDisk->exists($target) && $source !== $target) {
                $this->error("[CONFLICT] StudentDoc #{$doc->id} destination already exists at [{$target}]. Skipped to avoid overwrite.");
                $errors++;
                continue;
            }

            $this->info("[PLAN] StudentDoc #{$doc->id}: [{$source}] -> [{$target}]");

            if (! $dryRun) {
                $privateDisk->move($source, $target);
                $doc->update(['file_path' => $target]);
                $this->info("  -> Moved and record updated.");
            }

            $processed++;
        }

        // 2. Staff Documents (Private Disk)
        $this->line("\n--- Inspecting Staff Documents ---");
        $staffDocs = StaffDocument::withoutGlobalScopes()->get();

        foreach ($staffDocs as $doc) {
            $currentPath = $doc->file_path;
            $newPath = TenantStorage::staffDocumentPath($doc->school_id, $doc->staff_id, basename($currentPath));
            $legacyPath = "staff/{$doc->staff_id}/documents/" . basename($currentPath);

            $source = $rollback ? $newPath : $currentPath;
            $target = $rollback ? $legacyPath : $newPath;

            if ($source === $target) {
                $this->line("[SKIP] StaffDoc #{$doc->id} already at {$target}");
                $skipped++;
                continue;
            }

            if (! $privateDisk->exists($source)) {
                $this->warn("[MISSING] StaffDoc #{$doc->id} source file not found at [{$source}]");
                $skipped++;
                continue;
            }

            if ($privateDisk->exists($target) && $source !== $target) {
                $this->error("[CONFLICT] StaffDoc #{$doc->id} destination already exists at [{$target}]. Skipped to avoid overwrite.");
                $errors++;
                continue;
            }

            $this->info("[PLAN] StaffDoc #{$doc->id}: [{$source}] -> [{$target}]");

            if (! $dryRun) {
                $privateDisk->move($source, $target);
                $doc->update(['file_path' => $target]);
                $this->info("  -> Moved and record updated.");
            }

            $processed++;
        }

        // 3. School Branding / Logos (Public Disk)
        $this->line("\n--- Inspecting School Logos ---");
        $schools = School::withoutGlobalScopes()->whereNotNull('logo')->get();

        foreach ($schools as $school) {
            $currentPath = $school->logo;
            $newPath = TenantStorage::schoolBrandingPath($school->id, basename($currentPath));
            $legacyPath = "schools/{$school->id}/" . basename($currentPath);

            $source = $rollback ? $newPath : $currentPath;
            $target = $rollback ? $legacyPath : $newPath;

            if ($source === $target) {
                $this->line("[SKIP] School #{$school->id} logo already at {$target}");
                $skipped++;
                continue;
            }

            if (! $publicDisk->exists($source)) {
                $this->warn("[MISSING] School #{$school->id} logo file not found at [{$source}]");
                $skipped++;
                continue;
            }

            if ($publicDisk->exists($target) && $source !== $target) {
                $this->error("[CONFLICT] School #{$school->id} logo destination already exists at [{$target}]. Skipped.");
                $errors++;
                continue;
            }

            $this->info("[PLAN] School #{$school->id} Logo: [{$source}] -> [{$target}]");

            if (! $dryRun) {
                $publicDisk->move($source, $target);
                $school->update(['logo' => $target]);
                $this->info("  -> Moved and record updated.");
            }

            $processed++;
        }

        $this->line("\n==========================================");
        $this->info("Summary: Processed: {$processed} | Skipped: {$skipped} | Errors/Conflicts: {$errors}");

        if ($dryRun) {
            $this->comment("Dry-run complete. No files or database records were modified.");
        }

        return $errors > 0 ? 1 : 0;
    }
}
