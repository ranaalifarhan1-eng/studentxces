<?php

namespace Tests\Feature;

use App\Console\Commands\MigrateTenantStorage;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\StaffDocument;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\User;
use App\Services\TenantStorage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TenantStorageMigrationCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected School $schoolA;
    protected School $schoolB;
    protected Student $studentA;
    protected Staff $staffA;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        Storage::fake('public');

        $this->schoolA = School::create([
            'name'   => 'School Alpha Migration Test',
            'slug'   => 'school-alpha-migration-' . uniqid(),
            'code'   => 'SAM' . rand(1000, 9999),
            'status' => 'active',
        ]);

        $this->schoolB = School::create([
            'name'   => 'School Beta Migration Test',
            'slug'   => 'school-beta-migration-' . uniqid(),
            'code'   => 'SBM' . rand(1000, 9999),
            'status' => 'active',
        ]);

        $classA = SchoolClass::create(['school_id' => $this->schoolA->id, 'name' => 'Class A', 'numeric_name' => 1]);
        $this->studentA = Student::create([
            'school_id'  => $this->schoolA->id,
            'class_id'   => $classA->id,
            'first_name' => 'Student',
            'last_name'  => 'Alpha',
            'gender'     => 'male',
            'category'   => 'general',
            'status'     => 'active',
        ]);

        $this->staffA = Staff::create([
            'school_id'   => $this->schoolA->id,
            'first_name'  => 'Staff',
            'last_name'   => 'Alpha',
            'gender'      => 'female',
            'salary_type' => 'fixed',
            'status'      => 'active',
        ]);
    }

    /**
     * Test 1: Dry run performs zero writes, no DB updates, and no manifest mutations.
     */
    public function test_dry_run_performs_zero_writes_and_no_manifest_creation(): void
    {
        $legacyPath = "students/{$this->studentA->id}/documents/birth_cert.pdf";
        Storage::disk('private')->put($legacyPath, 'Original Certificate Content');

        $doc = StudentDocument::create([
            'school_id'  => $this->schoolA->id,
            'student_id' => $this->studentA->id,
            'title'      => 'Birth Certificate',
            'file_path'  => $legacyPath,
            'file_type'  => 'application/pdf',
            'file_size'  => 1024,
        ]);

        $exitCode = Artisan::call('tenant:migrate-storage', ['--dry-run' => true]);
        $this->assertEquals(0, $exitCode);

        // Verify source file still exists and target file does NOT exist
        $this->assertTrue(Storage::disk('private')->exists($legacyPath));
        $newExpectedPath = TenantStorage::studentDocumentPath($this->schoolA->id, $this->studentA->id, 'birth_cert.pdf');
        $this->assertFalse(Storage::disk('private')->exists($newExpectedPath));

        // DB record must remain untouched
        $doc->refresh();
        $this->assertEquals($legacyPath, $doc->file_path);

        // Manifest must not exist
        $this->assertFalse(Storage::disk('private')->exists(MigrateTenantStorage::MANIFEST_PATH));
    }

    /**
     * Test 2: Live migration moves legacy files to tenant-partitioned paths and updates database.
     */
    public function test_legacy_file_migrates_to_correct_tenant_path_and_updates_db(): void
    {
        // 1. Student Document
        $legacyStudentDoc = "students/{$this->studentA->id}/documents/transcript.pdf";
        Storage::disk('private')->put($legacyStudentDoc, 'Student Transcript Content');

        $doc = StudentDocument::create([
            'school_id'  => $this->schoolA->id,
            'student_id' => $this->studentA->id,
            'title'      => 'Transcript',
            'file_path'  => $legacyStudentDoc,
            'file_type'  => 'application/pdf',
            'file_size'  => 1024,
        ]);

        // 2. Staff Document
        $legacyStaffDoc = "staff/{$this->staffA->id}/documents/contract.pdf";
        Storage::disk('private')->put($legacyStaffDoc, 'Staff Contract Content');

        $staffDoc = StaffDocument::create([
            'school_id'  => $this->schoolA->id,
            'staff_id'   => $this->staffA->id,
            'title'      => 'Employment Contract',
            'file_path'  => $legacyStaffDoc,
            'file_type'  => 'application/pdf',
            'file_size'  => 1024,
        ]);

        // 3. School Logo
        $legacyLogo = "schools/{$this->schoolA->id}/logo.png";
        Storage::disk('public')->put($legacyLogo, 'PNG Image Binary');
        $this->schoolA->update(['logo' => $legacyLogo]);

        $exitCode = Artisan::call('tenant:migrate-storage');
        $this->assertEquals(0, $exitCode);

        // Verify Student Doc
        $newStudentPath = TenantStorage::studentDocumentPath($this->schoolA->id, $this->studentA->id, 'transcript.pdf');
        $this->assertFalse(Storage::disk('private')->exists($legacyStudentDoc));
        $this->assertTrue(Storage::disk('private')->exists($newStudentPath));
        $doc->refresh();
        $this->assertEquals($newStudentPath, $doc->file_path);

        // Verify Staff Doc
        $newStaffPath = TenantStorage::staffDocumentPath($this->schoolA->id, $this->staffA->id, 'contract.pdf');
        $this->assertFalse(Storage::disk('private')->exists($legacyStaffDoc));
        $this->assertTrue(Storage::disk('private')->exists($newStaffPath));
        $staffDoc->refresh();
        $this->assertEquals($newStaffPath, $staffDoc->file_path);

        // Verify School Logo
        $newLogoPath = TenantStorage::schoolBrandingPath($this->schoolA->id, 'logo.png');
        $this->assertFalse(Storage::disk('public')->exists($legacyLogo));
        $this->assertTrue(Storage::disk('public')->exists($newLogoPath));
        $this->schoolA->refresh();
        $this->assertEquals($newLogoPath, $this->schoolA->logo);

        // Manifest must be created and contain entries
        $this->assertTrue(Storage::disk('private')->exists(MigrateTenantStorage::MANIFEST_PATH));
        $manifest = json_decode(Storage::disk('private')->get(MigrateTenantStorage::MANIFEST_PATH), true);
        $this->assertArrayHasKey("student_document:{$doc->id}", $manifest['entries']);
        $this->assertArrayHasKey("staff_document:{$staffDoc->id}", $manifest['entries']);
        $this->assertArrayHasKey("school_logo:{$this->schoolA->id}", $manifest['entries']);
    }

    /**
     * Test 3: Repeated migration execution is idempotent and skips already migrated files.
     */
    public function test_repeated_migration_is_idempotent(): void
    {
        $legacyPath = "students/{$this->studentA->id}/documents/diploma.pdf";
        Storage::disk('private')->put($legacyPath, 'Diploma Content');

        $doc = StudentDocument::create([
            'school_id'  => $this->schoolA->id,
            'student_id' => $this->studentA->id,
            'title'      => 'Diploma',
            'file_path'  => $legacyPath,
            'file_type'  => 'application/pdf',
            'file_size'  => 1024,
        ]);

        // Run 1
        $exit1 = Artisan::call('tenant:migrate-storage');
        $this->assertEquals(0, $exit1);

        $doc->refresh();
        $migratedPath = $doc->file_path;

        // Run 2
        $exit2 = Artisan::call('tenant:migrate-storage');
        $this->assertEquals(0, $exit2);

        $doc->refresh();
        $this->assertEquals($migratedPath, $doc->file_path);
        $this->assertTrue(Storage::disk('private')->exists($migratedPath));
    }

    /**
     * Test 4: Destination collision never overwrites an existing file and preserves source.
     */
    public function test_destination_collision_does_not_overwrite(): void
    {
        $legacyPath = "students/{$this->studentA->id}/documents/report.pdf";
        Storage::disk('private')->put($legacyPath, 'Legacy Source Report');

        $targetPath = TenantStorage::studentDocumentPath($this->schoolA->id, $this->studentA->id, 'report.pdf');
        Storage::disk('private')->put($targetPath, 'Pre-existing Conflict Report');

        $doc = StudentDocument::create([
            'school_id'  => $this->schoolA->id,
            'student_id' => $this->studentA->id,
            'title'      => 'Report',
            'file_path'  => $legacyPath,
            'file_type'  => 'application/pdf',
            'file_size'  => 1024,
        ]);

        $exitCode = Artisan::call('tenant:migrate-storage');
        $this->assertEquals(1, $exitCode); // returns 1 due to conflict error count

        // Target content must NOT be overwritten
        $this->assertEquals('Pre-existing Conflict Report', Storage::disk('private')->get($targetPath));

        // Source file must remain intact
        $this->assertTrue(Storage::disk('private')->exists($legacyPath));

        // DB must remain at legacy path
        $doc->refresh();
        $this->assertEquals($legacyPath, $doc->file_path);
    }

    /**
     * Test 5: Rollback restores ONLY files recorded in migration manifest.
     */
    public function test_rollback_restores_only_manifest_recorded_migrated_file(): void
    {
        $legacyPath = "students/{$this->studentA->id}/documents/award.pdf";
        Storage::disk('private')->put($legacyPath, 'Award Certificate Content');

        $doc = StudentDocument::create([
            'school_id'  => $this->schoolA->id,
            'student_id' => $this->studentA->id,
            'title'      => 'Award',
            'file_path'  => $legacyPath,
            'file_type'  => 'application/pdf',
            'file_size'  => 1024,
        ]);

        // 1. Run live migration
        Artisan::call('tenant:migrate-storage');
        $doc->refresh();
        $targetPath = $doc->file_path;
        $this->assertTrue(Storage::disk('private')->exists($targetPath));

        // 2. Run rollback
        $rollbackExit = Artisan::call('tenant:migrate-storage', ['--rollback' => true]);
        $this->assertEquals(0, $rollbackExit);

        // Verify file is moved back to legacy path
        $this->assertFalse(Storage::disk('private')->exists($targetPath));
        $this->assertTrue(Storage::disk('private')->exists($legacyPath));

        // Verify DB updated back to legacy path
        $doc->refresh();
        $this->assertEquals($legacyPath, $doc->file_path);

        // Verify manifest updated
        $manifest = json_decode(Storage::disk('private')->get(MigrateTenantStorage::MANIFEST_PATH), true);
        $this->assertEquals('rolled_back', $manifest['entries']["student_document:{$doc->id}"]['status']);
    }

    /**
     * Test 6: Rollback does NOT touch newly uploaded post-P1A files.
     */
    public function test_rollback_does_not_move_normal_new_tenant_path_upload(): void
    {
        // 1. Migrate a legacy document
        $legacyPath = "students/{$this->studentA->id}/documents/old_cert.pdf";
        Storage::disk('private')->put($legacyPath, 'Old Cert');
        $oldDoc = StudentDocument::create([
            'school_id'  => $this->schoolA->id,
            'student_id' => $this->studentA->id,
            'title'      => 'Old Cert',
            'file_path'  => $legacyPath,
            'file_type'  => 'application/pdf',
            'file_size'  => 1024,
        ]);
        Artisan::call('tenant:migrate-storage');

        // 2. Create a new post-P1A document uploaded directly to tenant path (not in manifest)
        $newTenantPath = TenantStorage::studentDocumentPath($this->schoolA->id, $this->studentA->id, 'new_fresh_doc.pdf');
        Storage::disk('private')->put($newTenantPath, 'Fresh Post-P1A Upload');
        $newDoc = StudentDocument::create([
            'school_id'  => $this->schoolA->id,
            'student_id' => $this->studentA->id,
            'title'      => 'New Fresh Doc',
            'file_path'  => $newTenantPath,
            'file_type'  => 'application/pdf',
            'file_size'  => 1024,
        ]);

        // 3. Run rollback
        Artisan::call('tenant:migrate-storage', ['--rollback' => true]);

        // New document must be 100% UNTOUCHED
        $this->assertTrue(Storage::disk('private')->exists($newTenantPath));
        $newDoc->refresh();
        $this->assertEquals($newTenantPath, $newDoc->file_path);
    }

    /**
     * Test 7: Rollback collision never overwrites an existing file at the legacy destination.
     */
    public function test_rollback_collision_does_not_overwrite_legacy_destination(): void
    {
        $legacyPath = "students/{$this->studentA->id}/documents/cert_roll.pdf";
        Storage::disk('private')->put($legacyPath, 'Migrated Content');

        $doc = StudentDocument::create([
            'school_id'  => $this->schoolA->id,
            'student_id' => $this->studentA->id,
            'title'      => 'Cert Roll',
            'file_path'  => $legacyPath,
            'file_type'  => 'application/pdf',
            'file_size'  => 1024,
        ]);

        Artisan::call('tenant:migrate-storage');
        $doc->refresh();
        $targetPath = $doc->file_path;

        // Simulate a new conflicting file created at legacy path
        Storage::disk('private')->put($legacyPath, 'Conflicting Legacy File');

        // Attempt rollback
        $exitCode = Artisan::call('tenant:migrate-storage', ['--rollback' => true]);
        $this->assertEquals(1, $exitCode);

        // Both files must be preserved without overwrite
        $this->assertTrue(Storage::disk('private')->exists($targetPath));
        $this->assertEquals('Conflicting Legacy File', Storage::disk('private')->get($legacyPath));
        $doc->refresh();
        $this->assertEquals($targetPath, $doc->file_path);
    }

    /**
     * Test 8: Tampered manifest entry with cross-school mismatch or path traversal is blocked.
     */
    public function test_tampered_manifest_is_blocked(): void
    {
        // Fake a tampered manifest
        $manifest = [
            'version' => 1,
            'entries' => [
                'student_document:9999' => [
                    'model_type'  => StudentDocument::class,
                    'model_id'    => $this->studentA->id,
                    'school_id'   => $this->schoolB->id, // School mismatch (belongs to School A)
                    'disk'        => 'private',
                    'legacy_path' => 'students/1/documents/hack.pdf',
                    'tenant_path' => 'schools/2/students/1/documents/hack.pdf',
                    'status'      => 'migrated',
                ],
                'tampered_traversal' => [
                    'model_type'  => StudentDocument::class,
                    'model_id'    => $this->studentA->id,
                    'school_id'   => $this->schoolA->id,
                    'disk'        => 'private',
                    'legacy_path' => '../../etc/passwd',
                    'tenant_path' => 'schools/1/documents/hack.pdf',
                    'status'      => 'migrated',
                ],
            ],
        ];

        Storage::disk('private')->put(MigrateTenantStorage::MANIFEST_PATH, json_encode($manifest));

        $exitCode = Artisan::call('tenant:migrate-storage', ['--rollback' => true]);
        $this->assertEquals(1, $exitCode);
    }

    /**
     * Test 9: Simulated DB update failure triggers automatic compensation and restores source file.
     */
    public function test_simulated_db_update_failure_triggers_compensation_and_restores_source(): void
    {
        $legacyPath = "students/{$this->studentA->id}/documents/trans_fail.pdf";
        Storage::disk('private')->put($legacyPath, 'Unsaved Source Content');

        $doc = StudentDocument::create([
            'school_id'  => $this->schoolA->id,
            'student_id' => $this->studentA->id,
            'title'      => 'Fail Test Doc',
            'file_path'  => $legacyPath,
            'file_type'  => 'application/pdf',
            'file_size'  => 1024,
        ]);

        // Intercept updating event to throw an exception
        StudentDocument::updating(function ($model) {
            if ($model->title === 'Fail Test Doc') {
                throw new \RuntimeException('Simulated Database Crash during update');
            }
        });

        $exitCode = Artisan::call('tenant:migrate-storage');
        $this->assertEquals(1, $exitCode); // Failed item yields error exit code

        // Source file must be restored back to legacy path via compensation
        $this->assertTrue(Storage::disk('private')->exists($legacyPath));

        // Target path must NOT retain the file
        $targetPath = TenantStorage::studentDocumentPath($this->schoolA->id, $this->studentA->id, 'trans_fail.pdf');
        $this->assertFalse(Storage::disk('private')->exists($targetPath));

        // DB record must remain at legacy path
        $doc->refresh();
        $this->assertEquals($legacyPath, $doc->file_path);
    }
}
