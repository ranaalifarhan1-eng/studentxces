<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\StaffDocument;
use App\Models\StudentDocument;
use App\Services\TenantStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class MigrateTenantStorage extends Command
{
    protected $signature = 'tenant:migrate-storage 
                            {--dry-run : Simulate the migration without moving files, updating records, or modifying manifest}
                            {--rollback : Roll back only records previously migrated and recorded in the migration manifest}';

    protected $description = 'Safely and idempotently migrate legacy unpartitioned storage paths to tenant-partitioned paths with manifest-backed rollback and failure compensation';

    public const MANIFEST_PATH = 'migration_manifests/tenant_storage_migration_manifest.json';

    public function handle(): int
    {
        $dryRun   = $this->option('dry-run');
        $rollback = $this->option('rollback');

        $mode = $rollback ? 'ROLLBACK' : ($dryRun ? 'DRY-RUN' : 'LIVE MIGRATION');
        $this->info("=== Tenant Storage Migration [{$mode}] ===");

        if ($rollback) {
            return $this->handleRollback($dryRun);
        }

        return $this->handleMigration($dryRun);
    }

    /**
     * Handle forward migration to tenant-partitioned paths.
     */
    protected function handleMigration(bool $dryRun): int
    {
        $privateDisk = TenantStorage::privateDisk();
        $publicDisk  = TenantStorage::publicDisk();

        $manifest = $this->loadManifest();
        $manifestEntries = $manifest['entries'] ?? [];

        $processed = 0;
        $skipped   = 0;
        $errors    = 0;

        // 1. Student Documents (Private Disk)
        $this->line("\n--- Inspecting Student Documents ---");
        $studentDocs = StudentDocument::withoutGlobalScopes()->get();

        foreach ($studentDocs as $doc) {
            $currentPath = $doc->file_path;
            $newPath = TenantStorage::studentDocumentPath($doc->school_id, $doc->student_id, basename($currentPath));
            $legacyExpectedDir = "students/{$doc->student_id}/documents";

            // If already at tenant path, skip safely
            if (str_starts_with($currentPath, "schools/{$doc->school_id}/")) {
                $this->line("[SKIP] StudentDoc #{$doc->id} already stored under tenant path: {$currentPath}");
                $skipped++;
                continue;
            }

            if (! $privateDisk->exists($currentPath)) {
                $this->warn("[MISSING] StudentDoc #{$doc->id} source file not found at [{$currentPath}]");
                $skipped++;
                continue;
            }

            if ($privateDisk->exists($newPath)) {
                $this->error("[CONFLICT] StudentDoc #{$doc->id} destination already exists at [{$newPath}]. Skipped to avoid overwrite.");
                $errors++;
                continue;
            }

            $this->info("[PLAN] StudentDoc #{$doc->id}: [{$currentPath}] -> [{$newPath}]");

            if (! $dryRun) {
                $success = $this->executeMoveWithCompensation(
                    disk: $privateDisk,
                    source: $currentPath,
                    target: $newPath,
                    onDbUpdate: fn () => $doc->update(['file_path' => $newPath]),
                    context: "StudentDoc #{$doc->id}"
                );

                if (! $success) {
                    $errors++;
                    continue;
                }

                $entryKey = "student_document:{$doc->id}";
                $manifestEntries[$entryKey] = [
                    'model_type'  => StudentDocument::class,
                    'model_id'    => $doc->id,
                    'school_id'   => $doc->school_id,
                    'disk'        => 'private',
                    'legacy_path' => $currentPath,
                    'tenant_path' => $newPath,
                    'status'      => 'migrated',
                    'migrated_at' => now()->toIso8601String(),
                ];
            }

            $processed++;
        }

        // 2. Staff Documents (Private Disk)
        $this->line("\n--- Inspecting Staff Documents ---");
        $staffDocs = StaffDocument::withoutGlobalScopes()->get();

        foreach ($staffDocs as $doc) {
            $currentPath = $doc->file_path;
            $newPath = TenantStorage::staffDocumentPath($doc->school_id, $doc->staff_id, basename($currentPath));

            if (str_starts_with($currentPath, "schools/{$doc->school_id}/")) {
                $this->line("[SKIP] StaffDoc #{$doc->id} already stored under tenant path: {$currentPath}");
                $skipped++;
                continue;
            }

            if (! $privateDisk->exists($currentPath)) {
                $this->warn("[MISSING] StaffDoc #{$doc->id} source file not found at [{$currentPath}]");
                $skipped++;
                continue;
            }

            if ($privateDisk->exists($newPath)) {
                $this->error("[CONFLICT] StaffDoc #{$doc->id} destination already exists at [{$newPath}]. Skipped to avoid overwrite.");
                $errors++;
                continue;
            }

            $this->info("[PLAN] StaffDoc #{$doc->id}: [{$currentPath}] -> [{$newPath}]");

            if (! $dryRun) {
                $success = $this->executeMoveWithCompensation(
                    disk: $privateDisk,
                    source: $currentPath,
                    target: $newPath,
                    onDbUpdate: fn () => $doc->update(['file_path' => $newPath]),
                    context: "StaffDoc #{$doc->id}"
                );

                if (! $success) {
                    $errors++;
                    continue;
                }

                $entryKey = "staff_document:{$doc->id}";
                $manifestEntries[$entryKey] = [
                    'model_type'  => StaffDocument::class,
                    'model_id'    => $doc->id,
                    'school_id'   => $doc->school_id,
                    'disk'        => 'private',
                    'legacy_path' => $currentPath,
                    'tenant_path' => $newPath,
                    'status'      => 'migrated',
                    'migrated_at' => now()->toIso8601String(),
                ];
            }

            $processed++;
        }

        // 3. School Branding / Logos (Public Disk)
        $this->line("\n--- Inspecting School Logos ---");
        $schools = School::withoutGlobalScopes()->whereNotNull('logo')->get();

        foreach ($schools as $school) {
            $currentPath = $school->logo;
            $newPath = TenantStorage::schoolBrandingPath($school->id, basename($currentPath));

            if (str_starts_with($currentPath, "schools/{$school->id}/branding/")) {
                $this->line("[SKIP] School #{$school->id} logo already stored under tenant branding path: {$currentPath}");
                $skipped++;
                continue;
            }

            if (! $publicDisk->exists($currentPath)) {
                $this->warn("[MISSING] School #{$school->id} logo file not found at [{$currentPath}]");
                $skipped++;
                continue;
            }

            if ($publicDisk->exists($newPath)) {
                $this->error("[CONFLICT] School #{$school->id} logo destination already exists at [{$newPath}]. Skipped.");
                $errors++;
                continue;
            }

            $this->info("[PLAN] School #{$school->id} Logo: [{$currentPath}] -> [{$newPath}]");

            if (! $dryRun) {
                $success = $this->executeMoveWithCompensation(
                    disk: $publicDisk,
                    source: $currentPath,
                    target: $newPath,
                    onDbUpdate: fn () => $school->update(['logo' => $newPath]),
                    context: "School #{$school->id} Logo"
                );

                if (! $success) {
                    $errors++;
                    continue;
                }

                $entryKey = "school_logo:{$school->id}";
                $manifestEntries[$entryKey] = [
                    'model_type'  => School::class,
                    'model_id'    => $school->id,
                    'school_id'   => $school->id,
                    'disk'        => 'public',
                    'legacy_path' => $currentPath,
                    'tenant_path' => $newPath,
                    'status'      => 'migrated',
                    'migrated_at' => now()->toIso8601String(),
                ];
            }

            $processed++;
        }

        // Save manifest if live
        if (! $dryRun && $processed > 0) {
            $manifest['entries'] = $manifestEntries;
            $manifest['updated_at'] = now()->toIso8601String();
            $this->saveManifest($manifest);
            $this->info("[MANIFEST] Migration journal updated with {$processed} record(s).");
        }

        $this->line("\n==========================================");
        $this->info("Summary: Migrated: {$processed} | Skipped: {$skipped} | Errors/Conflicts: {$errors}");

        if ($dryRun) {
            $this->comment("Dry-run complete. Zero files moved, zero DB records updated, zero manifest changes.");
        }

        return $errors > 0 ? 1 : 0;
    }

    /**
     * Handle manifest-proven rollback.
     */
    protected function handleRollback(bool $dryRun): int
    {
        $manifest = $this->loadManifest();

        if (empty($manifest['entries'])) {
            $this->warn("[ROLLBACK ABORTED] No migration manifest found or manifest is empty. Rollback cannot proceed without a valid migration journal.");
            return 1;
        }

        $privateDisk = TenantStorage::privateDisk();
        $publicDisk  = TenantStorage::publicDisk();

        $processed = 0;
        $skipped   = 0;
        $errors    = 0;

        $this->line("\n--- Processing Manifest Entries for Rollback ---");

        foreach ($manifest['entries'] as $key => &$entry) {
            if (($entry['status'] ?? '') !== 'migrated') {
                $this->line("[SKIP] Entry {$key} status is '{$entry['status']}', skipping.");
                $skipped++;
                continue;
            }

            $disk = ($entry['disk'] ?? 'private') === 'public' ? $publicDisk : $privateDisk;
            $tenantPath = $entry['tenant_path'];
            $legacyPath = $entry['legacy_path'];
            $modelClass = $entry['model_type'];
            $modelId    = $entry['model_id'];
            $schoolId   = $entry['school_id'];

            // Validation: Path traversal protection
            if (str_contains($legacyPath, '..') || str_contains($tenantPath, '..')) {
                $this->error("[SECURITY ERROR] Entry {$key} contains path traversal. Skipping.");
                $errors++;
                continue;
            }

            // Load model record
            $model = $modelClass::withoutGlobalScopes()->find($modelId);
            if (! $model) {
                $this->warn("[MISSING RECORD] Entry {$key} model #{$modelId} no longer exists in DB. Skipping.");
                $skipped++;
                continue;
            }

            // Verify school ownership matches manifest
            $recordSchoolId = $model instanceof School ? $model->id : $model->school_id;
            if ((int) $recordSchoolId !== (int) $schoolId) {
                $this->error("[OWNERSHIP MISMATCH] Entry {$key} school mismatch. Manifest: {$schoolId}, Record: {$recordSchoolId}. Skipping.");
                $errors++;
                continue;
            }

            // Verify current model path matches the migrated tenant path
            $currentField = $model instanceof School ? $model->logo : $model->file_path;
            if ($currentField !== $tenantPath) {
                $this->warn("[PATH CHANGED] Entry {$key} DB path [{$currentField}] does not match manifest tenant path [{$tenantPath}]. Likely replaced after migration. Skipping.");
                $skipped++;
                continue;
            }

            if (! $disk->exists($tenantPath)) {
                $this->warn("[MISSING FILE] Entry {$key} tenant file not found at [{$tenantPath}]. Skipping.");
                $skipped++;
                continue;
            }

            if ($disk->exists($legacyPath)) {
                $this->error("[CONFLICT] Entry {$key} legacy destination already exists at [{$legacyPath}]. Skipped to avoid overwrite.");
                $errors++;
                continue;
            }

            $this->info("[ROLLBACK PLAN] {$key}: [{$tenantPath}] -> [{$legacyPath}]");

            if (! $dryRun) {
                $success = $this->executeMoveWithCompensation(
                    disk: $disk,
                    source: $tenantPath,
                    target: $legacyPath,
                    onDbUpdate: function () use ($model, $legacyPath) {
                        if ($model instanceof School) {
                            $model->update(['logo' => $legacyPath]);
                        } else {
                            $model->update(['file_path' => $legacyPath]);
                        }
                    },
                    context: "Rollback {$key}"
                );

                if (! $success) {
                    $errors++;
                    continue;
                }

                $entry['status'] = 'rolled_back';
                $entry['rolled_back_at'] = now()->toIso8601String();
            }

            $processed++;
        }
        unset($entry);

        if (! $dryRun && $processed > 0) {
            $manifest['updated_at'] = now()->toIso8601String();
            $this->saveManifest($manifest);
            $this->info("[MANIFEST] Migration journal updated after rollback of {$processed} record(s).");
        }

        $this->line("\n==========================================");
        $this->info("Rollback Summary: Rolled Back: {$processed} | Skipped: {$skipped} | Errors: {$errors}");

        if ($dryRun) {
            $this->comment("Dry-run complete. Zero files moved, zero DB records updated, zero manifest changes.");
        }

        return $errors > 0 ? 1 : 0;
    }

    /**
     * Executes filesystem move with automatic compensating move if DB update throws an error.
     */
    protected function executeMoveWithCompensation(
        \Illuminate\Contracts\Filesystem\Filesystem $disk,
        string $source,
        string $target,
        callable $onDbUpdate,
        string $context
    ): bool {
        // Step 1: Move file to target
        $disk->move($source, $target);

        // Step 2: Attempt DB update
        try {
            $onDbUpdate();
            $this->info("  -> Moved [{$source}] to [{$target}] and database record updated.");
            return true;
        } catch (Throwable $e) {
            $this->error("  -> DB update failed for {$context}: " . $e->getMessage());
            $this->warn("  -> Initiating compensating rollback: moving [{$target}] back to [{$source}]...");

            try {
                $disk->move($target, $source);
                $this->info("  -> Compensating move successful. Source file restored to [{$source}].");
            } catch (Throwable $compException) {
                $this->error("  -> CRITICAL ERROR: Compensation failed! File is at [{$target}], but DB points to [{$source}]. Details: " . $compException->getMessage());
            }

            return false;
        }
    }

    /**
     * Load manifest journal from private disk.
     */
    protected function loadManifest(): array
    {
        $disk = TenantStorage::privateDisk();

        if ($disk->exists(self::MANIFEST_PATH)) {
            $content = $disk->get(self::MANIFEST_PATH);
            $data = json_decode($content, true);
            if (is_array($data)) {
                return $data;
            }
        }

        return [
            'version'    => 1,
            'created_at' => now()->toIso8601String(),
            'entries'    => [],
        ];
    }

    /**
     * Save manifest journal to private disk.
     */
    protected function saveManifest(array $manifest): void
    {
        $disk = TenantStorage::privateDisk();
        $disk->put(self::MANIFEST_PATH, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
