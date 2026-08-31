<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PackageModule;
use App\Models\School;
use App\Models\SchoolSubscription;
use App\Services\LegacyEntitlementProvisioner;
use App\Services\SchoolEntitlementResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LegacyEntitlementProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected LegacyEntitlementProvisioner $provisioner;
    protected SchoolEntitlementResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(SchoolEntitlementResolver::class);
        $this->provisioner = app(LegacyEntitlementProvisioner::class);

        // Clean up test journal directory if exists
        $journalDir = storage_path('app/private/entitlement_manifests');
        if (File::isDirectory($journalDir)) {
            File::cleanDirectory($journalDir);
        }
    }

    protected function createSchool(string $name = 'Legacy School'): School
    {
        return School::create([
            'name'   => $name,
            'slug'   => \Illuminate\Support\Str::slug($name . '-' . uniqid()),
            'email'  => strtolower(str_replace(' ', '', $name)) . uniqid() . '@example.com',
            'status' => 'active',
        ]);
    }

    public function test_get_or_create_legacy_package_creates_inactive_package_with_all_14_canonical_modules(): void
    {
        $package = $this->provisioner->getOrCreateLegacyPackage();

        $this->assertNotNull($package);
        $this->assertEquals(LegacyEntitlementProvisioner::LEGACY_PACKAGE_SLUG, $package->slug);
        $this->assertEquals(LegacyEntitlementProvisioner::LEGACY_PACKAGE_NAME, $package->name);
        $this->assertFalse((bool) $package->is_active, 'Legacy package must be inactive by default to avoid public plan exposure.');
        $this->assertEquals(14, $package->modules->count());

        $canonical = config('modules.canonical');
        foreach ($canonical as $slug) {
            $this->assertTrue($package->modules->pluck('module_slug')->contains($slug));
        }

        // Must NOT appear in active commercial package query
        $activePackages = Package::where('is_active', true)->get();
        $this->assertFalse($activePackages->pluck('slug')->contains(LegacyEntitlementProvisioner::LEGACY_PACKAGE_SLUG));
    }

    public function test_inactive_legacy_package_still_grants_all_modules_to_entitled_subscription(): void
    {
        $school = $this->createSchool('School Inactive Entitled');

        $res = $this->provisioner->provisionSchool($school);
        $this->assertEquals('PROVISIONED', $res['status']);

        // Verify resolver sees school as active and entitled for all 14 canonical modules
        $this->assertTrue($this->resolver->hasActiveSubscription($school));
        foreach (config('modules.canonical') as $slug) {
            $this->assertTrue($this->resolver->isModuleEnabled($school, $slug));
        }
    }

    public function test_dry_run_performs_zero_database_writes_and_no_journal(): void
    {
        $school = $this->createSchool('School Dry Run');

        $initialSubsCount = SchoolSubscription::count();
        $initialPkgCount  = Package::count();
        $journalDir = storage_path('app/private/entitlement_manifests');

        $result = $this->provisioner->provisionSchool($school, ['dry_run' => true]);

        $this->assertEquals('WOULD_PROVISION', $result['status']);
        $this->assertEquals($school->id, $result['school_id']);
        $this->assertEquals(14, $result['modules_count']);

        $this->assertEquals($initialSubsCount, SchoolSubscription::count());
        $this->assertEquals($initialPkgCount, Package::count());

        $journalFiles = File::isDirectory($journalDir) ? File::files($journalDir) : [];
        $this->assertEmpty($journalFiles, 'Dry-run must not create any journal manifest files.');
    }

    public function test_provisioning_creates_committed_journal_manifest_on_successful_execution(): void
    {
        $school = $this->createSchool('School Journaled Target');

        $result = $this->provisioner->provisionSchool($school);

        $this->assertEquals('PROVISIONED', $result['status']);
        $this->assertNotNull($result['journal_id']);

        $journalDir = storage_path('app/private/entitlement_manifests');
        $journalFiles = File::files($journalDir);
        $this->assertCount(1, $journalFiles);

        $journalContent = json_decode(File::get($journalFiles[0]->getRealPath()), true);
        $this->assertEquals('COMMITTED', $journalContent['state']);
        $this->assertEquals($school->id, $journalContent['school_id']);
        $this->assertEquals($result['subscription_id'], $journalContent['created_subscription_id']);
        $this->assertEquals('PROVISIONED', $journalContent['action_result']);
        $this->assertEquals('ALLOWED', $journalContent['resulting_subscription_state']);
        $this->assertNotNull($journalContent['committed_at']);

        // Validate consistency via validateJournalManifest
        $validation = $this->provisioner->validateJournalManifest($journalFiles[0]->getRealPath());
        $this->assertTrue($validation['is_valid']);
    }

    public function test_db_failure_during_provisioning_marks_manifest_as_failed_rolled_back(): void
    {
        $school = $this->createSchool('School DB Failure');
        $journalDir = storage_path('app/private/entitlement_manifests');

        SchoolSubscription::saving(function () {
            throw new \RuntimeException("Simulated database connection crash during subscription creation.");
        });

        try {
            $this->provisioner->provisionSchool($school);
            $this->fail("Expected RuntimeException was not thrown.");
        } catch (\RuntimeException $e) {
            $this->assertEquals("Simulated database connection crash during subscription creation.", $e->getMessage());
        }

        // Verify DB rolled back (zero subscriptions)
        $this->assertEquals(0, SchoolSubscription::count());

        // Verify manifest file on disk is marked FAILED_ROLLED_BACK (NOT COMMITTED)
        $journalFiles = File::files($journalDir);
        $this->assertCount(1, $journalFiles);

        $journalContent = json_decode(File::get($journalFiles[0]->getRealPath()), true);
        $this->assertEquals('FAILED_ROLLED_BACK', $journalContent['state']);
        $this->assertEquals('FAILED', $journalContent['action_result']);
        $this->assertNull($journalContent['created_subscription_id']);
        $this->assertStringContainsString('Simulated database connection crash', $journalContent['error_message']);

        // Assert validateJournalManifest rejects this manifest
        $validation = $this->provisioner->validateJournalManifest($journalFiles[0]->getRealPath());
        $this->assertFalse($validation['is_valid']);
        $this->assertEquals('INCOMPLETE_OR_FAILED_STATE', $validation['reason']);
    }

    public function test_post_commit_journal_failure_reports_db_committed_journal_incomplete(): void
    {
        $school = $this->createSchool('School Post Commit Failure');

        // Subclass provisioner to simulate filesystem write failure ONLY during Phase 3 (COMMITTED write)
        $customProvisioner = new class(app(SchoolEntitlementResolver::class)) extends LegacyEntitlementProvisioner {
            public int $writeCallCount = 0;
            public function writeJournal(array $data, ?string $existingPath = null): string
            {
                $this->writeCallCount++;
                if ($this->writeCallCount === 2) {
                    // Phase 3 COMMITTED update throws
                    throw new \RuntimeException("Simulated disk write failure on COMMITTED finalization.");
                }
                return parent::writeJournal($data, $existingPath);
            }
        };

        $result = $customProvisioner->provisionSchool($school);

        $this->assertEquals('DB_COMMITTED_JOURNAL_INCOMPLETE', $result['status']);
        $this->assertNotNull($result['subscription_id']);
        $this->assertStringContainsString('successfully committed to database', $result['message']);

        // Assert subscription is intact in DB
        $sub = SchoolSubscription::find($result['subscription_id']);
        $this->assertNotNull($sub);
        $this->assertEquals($school->id, $sub->school_id);

        // Assert manifest on disk remained PENDING
        $journalDir = storage_path('app/private/entitlement_manifests');
        $journalFiles = File::files($journalDir);
        $this->assertCount(1, $journalFiles);

        $manifest = json_decode(File::get($journalFiles[0]->getRealPath()), true);
        $this->assertEquals('PENDING', $manifest['state']);
    }

    public function test_reconcile_manifest_promotes_pending_manifest_with_committed_db_record(): void
    {
        $school = $this->createSchool('School Reconcile Success');
        $package = $this->provisioner->getOrCreateLegacyPackage();

        $sub = SchoolSubscription::create([
            'school_id'   => $school->id,
            'package_id'  => $package->id,
            'start_date'  => Carbon::today()->toDateString(),
            'end_date'    => Carbon::today()->addYear()->toDateString(),
            'status'      => 'active',
            'amount_paid' => 0,
        ]);

        $journalDir = storage_path('app/private/entitlement_manifests');
        $filePath = $journalDir . '/test_pending_manifest.json';

        $pendingData = [
            'execution_id'            => 'reconcile-test-uuid',
            'state'                   => 'PENDING',
            'created_at'              => Carbon::now()->toIso8601String(),
            'school_id'               => $school->id,
            'package_id'              => $package->id,
            'start_date'              => Carbon::today()->toDateString(),
            'end_date'                => Carbon::today()->addYear()->toDateString(),
            'created_subscription_id' => null,
        ];
        File::put($filePath, json_encode($pendingData));

        // Reconcile
        $recResult = $this->provisioner->reconcileManifest($filePath);

        $this->assertEquals('RECONCILED_COMMITTED', $recResult['status']);
        $this->assertEquals($sub->id, $recResult['subscription_id']);

        // Check updated file on disk
        $updated = json_decode(File::get($filePath), true);
        $this->assertEquals('COMMITTED', $updated['state']);
        $this->assertEquals($sub->id, $updated['created_subscription_id']);
        $this->assertNotNull($updated['reconciled_at']);

        // Assert 0 duplicate subscriptions created
        $this->assertEquals(1, SchoolSubscription::where('school_id', $school->id)->count());
    }

    public function test_reconcile_manifest_with_no_db_record_returns_retryable(): void
    {
        $school = $this->createSchool('School No DB Match');
        $package = $this->provisioner->getOrCreateLegacyPackage();

        $journalDir = storage_path('app/private/entitlement_manifests');
        $filePath = $journalDir . '/test_pending_no_db.json';

        $pendingData = [
            'execution_id'            => 'reconcile-no-db-uuid',
            'state'                   => 'PENDING',
            'created_at'              => Carbon::now()->toIso8601String(),
            'school_id'               => $school->id,
            'package_id'              => $package->id,
            'start_date'              => Carbon::today()->toDateString(),
            'end_date'                => Carbon::today()->addYear()->toDateString(),
            'created_subscription_id' => null,
        ];
        File::put($filePath, json_encode($pendingData));

        $recResult = $this->provisioner->reconcileManifest($filePath);

        $this->assertEquals('NO_DB_RECORD_RETRYABLE', $recResult['status']);
    }

    public function test_reconcile_manifest_with_ambiguous_db_records_refuses_reconciliation(): void
    {
        $school = $this->createSchool('School Ambiguous DB Match');
        $package = $this->provisioner->getOrCreateLegacyPackage();

        // Create 2 identical subscriptions
        SchoolSubscription::create(['school_id' => $school->id, 'package_id' => $package->id, 'start_date' => Carbon::today()->toDateString(), 'end_date' => Carbon::today()->addYear()->toDateString(), 'status' => 'active', 'amount_paid' => 0]);
        SchoolSubscription::create(['school_id' => $school->id, 'package_id' => $package->id, 'start_date' => Carbon::today()->toDateString(), 'end_date' => Carbon::today()->addYear()->toDateString(), 'status' => 'active', 'amount_paid' => 0]);

        $journalDir = storage_path('app/private/entitlement_manifests');
        $filePath = $journalDir . '/test_pending_ambiguous.json';

        $pendingData = [
            'execution_id'            => 'reconcile-ambiguous-uuid',
            'state'                   => 'PENDING',
            'created_at'              => Carbon::now()->toIso8601String(),
            'school_id'               => $school->id,
            'package_id'              => $package->id,
            'start_date'              => Carbon::today()->toDateString(),
            'end_date'                => Carbon::today()->addYear()->toDateString(),
            'created_subscription_id' => null,
        ];
        File::put($filePath, json_encode($pendingData));

        $recResult = $this->provisioner->reconcileManifest($filePath);

        $this->assertEquals('AMBIGUOUS_MATCHING_SUBSCRIPTIONS', $recResult['status']);
    }

    public function test_repeated_reconciliation_is_idempotent(): void
    {
        $school = $this->createSchool('School Idempotent Reconcile');
        $package = $this->provisioner->getOrCreateLegacyPackage();

        $sub = SchoolSubscription::create(['school_id' => $school->id, 'package_id' => $package->id, 'start_date' => Carbon::today()->toDateString(), 'end_date' => Carbon::today()->addYear()->toDateString(), 'status' => 'active', 'amount_paid' => 0]);

        $journalDir = storage_path('app/private/entitlement_manifests');
        $filePath = $journalDir . '/test_idempotent.json';

        $pendingData = [
            'execution_id'            => 'reconcile-idem-uuid',
            'state'                   => 'PENDING',
            'created_at'              => Carbon::now()->toIso8601String(),
            'school_id'               => $school->id,
            'package_id'              => $package->id,
            'start_date'              => Carbon::today()->toDateString(),
            'end_date'                => Carbon::today()->addYear()->toDateString(),
            'created_subscription_id' => null,
        ];
        File::put($filePath, json_encode($pendingData));

        $firstRec = $this->provisioner->reconcileManifest($filePath);
        $this->assertEquals('RECONCILED_COMMITTED', $firstRec['status']);

        $secondRec = $this->provisioner->reconcileManifest($filePath);
        $this->assertEquals('ALREADY_COMMITTED', $secondRec['status']);
    }

    public function test_artisan_command_with_reconcile_flag_runs_reconciliation(): void
    {
        $school = $this->createSchool('School CLI Reconcile');
        $package = $this->provisioner->getOrCreateLegacyPackage();

        $sub = SchoolSubscription::create(['school_id' => $school->id, 'package_id' => $package->id, 'start_date' => Carbon::today()->toDateString(), 'end_date' => Carbon::today()->addYear()->toDateString(), 'status' => 'active', 'amount_paid' => 0]);

        $journalDir = storage_path('app/private/entitlement_manifests');
        $filePath = $journalDir . '/cli_pending.json';

        $pendingData = [
            'execution_id'            => 'cli-reconcile-uuid',
            'state'                   => 'PENDING',
            'created_at'              => Carbon::now()->toIso8601String(),
            'school_id'               => $school->id,
            'package_id'              => $package->id,
            'start_date'              => Carbon::today()->toDateString(),
            'end_date'                => Carbon::today()->addYear()->toDateString(),
            'created_subscription_id' => null,
        ];
        File::put($filePath, json_encode($pendingData));

        $this->artisan('entitlement:provision-legacy --reconcile')
            ->expectsOutputToContain('=== RECONCILING PENDING PROVISIONING MANIFESTS ===')
            ->expectsOutputToContain('RECONCILED_COMMITTED')
            ->assertExitCode(0);
    }

    public function test_validate_journal_manifest_rejects_tampered_or_stale_data(): void
    {
        $school = $this->createSchool('School Tamper Test');
        $res = $this->provisioner->provisionSchool($school);

        $journalDir = storage_path('app/private/entitlement_manifests');
        $journalFiles = File::files($journalDir);
        $manifestPath = $journalFiles[0]->getRealPath();
        $manifest = json_decode(File::get($manifestPath), true);

        // 1. Valid original
        $this->assertTrue($this->provisioner->validateJournalManifest($manifest)['is_valid']);

        // 2. Tampered school ID
        $tamperedSchool = $manifest;
        $tamperedSchool['school_id'] = 99999;
        $this->assertFalse($this->provisioner->validateJournalManifest($tamperedSchool)['is_valid']);

        // 3. Tampered subscription ID
        $tamperedSub = $manifest;
        $tamperedSub['created_subscription_id'] = 99999;
        $this->assertFalse($this->provisioner->validateJournalManifest($tamperedSub)['is_valid']);

        // 4. Tampered date
        $tamperedDate = $manifest;
        $tamperedDate['start_date'] = '1999-01-01';
        $this->assertFalse($this->provisioner->validateJournalManifest($tamperedDate)['is_valid']);
    }

    public function test_repeated_provisioning_is_strictly_idempotent(): void
    {
        $school = $this->createSchool('School Idempotent Direct');

        // First run
        $res1 = $this->provisioner->provisionSchool($school);
        $this->assertEquals('PROVISIONED', $res1['status']);
        $subId = $res1['subscription_id'];

        $pkgCount = Package::count();
        $subCount = SchoolSubscription::count();

        // Second run
        $res2 = $this->provisioner->provisionSchool($school);
        $this->assertEquals('SKIP_EXISTING_VALID_SUBSCRIPTION', $res2['status']);
        $this->assertEquals($subId, $res2['subscription_id']);

        // Assert no duplicates created
        $this->assertEquals($pkgCount, Package::count());
        $this->assertEquals($subCount, SchoolSubscription::count());
    }

    public function test_existing_valid_subscription_is_not_overwritten(): void
    {
        $school = $this->createSchool('School Existing Sub');

        $customPkg = Package::create([
            'name'          => 'Custom Plan',
            'slug'          => 'custom-plan',
            'price_monthly' => 25,
            'price_yearly'  => 250,
            'max_students'  => 50,
            'max_staff'     => 10,
            'storage_gb'    => 5,
            'is_active'     => true,
        ]);
        PackageModule::create(['package_id' => $customPkg->id, 'module_slug' => 'students']);

        $existingSub = SchoolSubscription::create([
            'school_id'   => $school->id,
            'package_id'  => $customPkg->id,
            'start_date'  => Carbon::yesterday(),
            'end_date'    => Carbon::tomorrow(),
            'status'      => 'active',
            'amount_paid' => 25,
        ]);

        $result = $this->provisioner->provisionSchool($school);

        $this->assertEquals('SKIP_EXISTING_VALID_SUBSCRIPTION', $result['status']);
        $this->assertEquals($existingSub->id, $result['subscription_id']);
        $this->assertEquals($customPkg->id, $result['package_id']);
    }

    public function test_suspended_subscription_is_not_silently_replaced(): void
    {
        $school = $this->createSchool('School Suspended');

        $pkg = Package::create([
            'name'          => 'Basic',
            'slug'          => 'basic-sub',
            'price_monthly' => 10,
            'price_yearly'  => 100,
            'max_students'  => 10,
            'max_staff'     => 5,
            'storage_gb'    => 1,
            'is_active'     => true,
        ]);

        $sub = SchoolSubscription::create([
            'school_id'   => $school->id,
            'package_id'  => $pkg->id,
            'start_date'  => Carbon::yesterday(),
            'end_date'    => Carbon::tomorrow(),
            'status'      => 'suspended',
            'amount_paid' => 10,
        ]);

        $result = $this->provisioner->provisionSchool($school);

        $this->assertEquals('SKIP_EXISTING_SUSPENDED_SUBSCRIPTION', $result['status']);
        $this->assertEquals($sub->id, $result['subscription_id']);
    }

    public function test_expired_subscription_is_not_silently_replaced(): void
    {
        $school = $this->createSchool('School Expired');

        $pkg = Package::create([
            'name'          => 'Tier Exp',
            'slug'          => 'tier-exp',
            'price_monthly' => 10,
            'price_yearly'  => 100,
            'max_students'  => 10,
            'max_staff'     => 5,
            'storage_gb'    => 1,
            'is_active'     => true,
        ]);

        $sub = SchoolSubscription::create([
            'school_id'   => $school->id,
            'package_id'  => $pkg->id,
            'start_date'  => Carbon::now()->subDays(60),
            'end_date'    => Carbon::now()->subDays(5),
            'status'      => 'active',
            'amount_paid' => 10,
        ]);

        $result = $this->provisioner->provisionSchool($school);

        $this->assertEquals('SKIP_EXISTING_EXPIRED_SUBSCRIPTION', $result['status']);
        $this->assertEquals($sub->id, $result['subscription_id']);
    }

    public function test_ambiguous_subscriptions_are_rejected(): void
    {
        $school = $this->createSchool('School Ambiguous');

        $pkgA = Package::create(['name' => 'A', 'slug' => 'pkg-a', 'price_monthly' => 10, 'price_yearly' => 100, 'max_students' => 10, 'max_staff' => 5, 'storage_gb' => 1, 'is_active' => true]);
        $pkgB = Package::create(['name' => 'B', 'slug' => 'pkg-b', 'price_monthly' => 20, 'price_yearly' => 200, 'max_students' => 20, 'max_staff' => 10, 'storage_gb' => 2, 'is_active' => true]);

        SchoolSubscription::create(['school_id' => $school->id, 'package_id' => $pkgA->id, 'start_date' => Carbon::yesterday(), 'end_date' => Carbon::tomorrow(), 'status' => 'active', 'amount_paid' => 10]);
        SchoolSubscription::create(['school_id' => $school->id, 'package_id' => $pkgB->id, 'start_date' => Carbon::yesterday(), 'end_date' => Carbon::tomorrow(), 'status' => 'active', 'amount_paid' => 20]);

        $result = $this->provisioner->provisionSchool($school);

        $this->assertEquals('AMBIGUOUS_ACTIVE_SUBSCRIPTIONS', $result['status']);
    }

    public function test_date_validation_rejects_invalid_date_formats_and_ranges(): void
    {
        // Invalid date format
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid start-date format");
        $this->provisioner->validateDates(['start_date' => '2026/08/31']);
    }

    public function test_date_validation_rejects_end_date_less_than_start_date(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("must be strictly greater than start date");
        $this->provisioner->validateDates(['start_date' => '2026-08-31', 'end_date' => '2026-08-01']);
    }

    public function test_artisan_command_without_options_fails_safe_with_zero_actions(): void
    {
        $this->artisan('entitlement:provision-legacy')
            ->expectsOutput('No target specified. Use --school=<id>, --all-existing, or --reconcile.')
            ->assertExitCode(1);
    }

    public function test_artisan_command_without_execute_flag_defaults_to_simulation(): void
    {
        $school = $this->createSchool('School No Execute Flag');

        $initialSubs = SchoolSubscription::count();

        $this->artisan("entitlement:provision-legacy --school={$school->id}")
            ->expectsOutputToContain('[SIMULATION MODE] --execute flag was not provided. ZERO DATABASE WRITES.')
            ->assertExitCode(0);

        $this->assertEquals($initialSubs, SchoolSubscription::count(), 'Without --execute, zero subscriptions must be created.');
    }

    public function test_artisan_command_with_execute_flag_provisions_clean_target(): void
    {
        $school = $this->createSchool('School Executed Target');

        $this->artisan("entitlement:provision-legacy --school={$school->id} --execute")
            ->expectsOutputToContain('Provisioning completed successfully.')
            ->assertExitCode(0);

        $this->assertTrue($this->resolver->hasActiveSubscription($school));
    }
}
