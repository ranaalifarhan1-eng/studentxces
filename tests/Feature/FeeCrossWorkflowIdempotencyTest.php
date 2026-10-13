<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\FeeCategory;
use App\Models\FeeChallan;
use App\Models\FeeChallanItem;
use App\Models\FeeStructure;
use App\Models\Guardian;
use App\Models\Package;
use App\Models\PackageModule;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\SchoolSubscription;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentFeeAssignment;
use App\Models\StudentFeeDiscount;
use App\Models\User;
use App\Services\FeeBillingService;
use App\Services\FeeBulkAssignService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FeeCrossWorkflowIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected User $adminUser;
    protected AcademicYear $academicYear;
    protected SchoolClass $class;
    protected Section $section;
    protected Student $student;
    protected FeeCategory $tuitionCategory;
    protected FeeStructure $tuitionStructure;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = [
            'students.view', 'students.create', 'students.edit',
            'fees.view', 'fees.collect', 'fees.structure', 'fees.assign', 'fees.bulk_bill',
        ];
        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $adminRole = Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions($permissions);

        $this->school = School::create([
            'name'       => 'Idempotency Primary School',
            'domain'     => 'idempotency-test',
            'is_active'  => true,
            'created_by' => 1,
        ]);

        $package = Package::create([
            'name'          => 'Pro Plan',
            'slug'          => 'plan-' . uniqid(),
            'price_monthly' => 50,
            'price_yearly'  => 500,
            'max_students'  => 500,
            'max_staff'     => 50,
            'storage_gb'    => 50,
            'is_active'     => true,
            'is_internal'   => false,
        ]);
        PackageModule::create(['package_id' => $package->id, 'module_slug' => 'students']);
        PackageModule::create(['package_id' => $package->id, 'module_slug' => 'fees']);

        SchoolSubscription::create([
            'school_id'  => $this->school->id,
            'package_id' => $package->id,
            'status'     => 'active',
            'start_date' => Carbon::now()->subDays(10),
            'end_date'   => Carbon::now()->addDays(50),
        ]);

        $this->adminUser = User::create([
            'name'      => 'Idempotency Admin',
            'email'     => 'admin@idempotency.test',
            'password'  => bcrypt('secret123'),
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
        $this->adminUser->assignRole('school-admin');

        $this->academicYear = AcademicYear::create([
            'school_id'  => $this->school->id,
            'name'       => '2026-2027',
            'start_date' => '2026-01-01',
            'end_date'   => '2026-12-31',
            'is_current' => true,
        ]);

        $this->class = SchoolClass::create([
            'school_id'    => $this->school->id,
            'name'         => 'Playgroup',
            'numeric_name' => 1,
        ]);

        $this->section = Section::create([
            'school_id' => $this->school->id,
            'class_id'  => $this->class->id,
            'name'      => 'Rose',
        ]);

        $guardian = Guardian::create([
            'school_id' => $this->school->id,
            'name'      => 'Parent Tester',
            'email'     => 'parent@idempotency.test',
            'phone'     => '03001234567',
        ]);

        $this->student = Student::create([
            'school_id'    => $this->school->id,
            'class_id'     => $this->class->id,
            'section_id'   => $this->section->id,
            'guardian_id'  => $guardian->id,
            'admission_no' => 'ADM-IDEM-001',
            'first_name'   => 'Ayan',
            'last_name'    => 'Ali',
            'email'        => 'ayan@idempotency.test',
            'status'       => 'active',
        ]);

        $this->tuitionCategory = FeeCategory::create([
            'school_id' => $this->school->id,
            'name'      => 'Tuition Fee',
            'type'      => 'tuition',
            'is_active' => true,
        ]);

        $this->tuitionStructure = FeeStructure::create([
            'school_id'                => $this->school->id,
            'class_id'                 => $this->class->id,
            'fee_category_id'          => $this->tuitionCategory->id,
            'academic_year'            => '2026-2027',
            'amount'                   => '3000.00',
            'frequency'                => 'monthly',
            'is_active'                => true,
            'is_optional'              => false,
            'admission_voucher_policy' => 'required',
        ]);

        // Student fee assignment starting 2026-09-01
        StudentFeeAssignment::create([
            'school_id'         => $this->school->id,
            'student_id'        => $this->student->id,
            'fee_structure_id'  => $this->tuitionStructure->id,
            'academic_year_id'  => $this->academicYear->id,
            'is_active'         => true,
            'starts_on'         => '2026-09-01',
            'assigned_by'       => $this->adminUser->id,
            'assigned_at'       => now(),
        ]);
    }

    /**
     * Scenario 1: Admission workflow creates October challan first.
     * Subsequent bulk assign for same student, same structure, same October is BLOCKED.
     */
    public function test_admission_first_then_bulk_assign_same_october_blocks_duplicate(): void
    {
        $targetMonth = Carbon::parse('2026-10-01');

        // 1. Admission voucher generates October challan
        $admChallan = FeeBillingService::createAdmissionChallan(
            $this->student,
            $this->academicYear,
            $targetMonth
        );

        $this->assertNotNull($admChallan);
        $expectedCanonicalPeriodKey = "AY{$this->academicYear->id}-2026-10";
        $expectedActivePeriodKey = "{$this->student->id}_{$expectedCanonicalPeriodKey}";
        $this->assertEquals($expectedCanonicalPeriodKey, $admChallan->billing_period_key);
        $this->assertEquals($expectedActivePeriodKey, $admChallan->active_period_key);
        $this->assertEquals('unpaid', $admChallan->status);
        $this->assertEquals(1, FeeChallan::where('student_id', $this->student->id)->where('status', '!=', 'void')->count());

        // 2. Bulk assign workflow is executed for same class, same structure, same October
        $result = FeeBulkAssignService::execute(
            $this->school->id,
            $this->tuitionStructure->id,
            $this->academicYear->id,
            $this->class->id,
            [
                'target_mode'    => 'entire_class',
                'execution_mode' => 'assign_and_bill',
                'billing_label'  => 'Tuition Fee',
                'issue_date'     => '2026-10-01',
            ],
            $this->adminUser
        );

        // 3. Assert bulk operation correctly reports already billed and creates 0 vouchers
        $this->assertEquals(0, $result['vouchers_generated']);
        $this->assertEquals(1, $result['already_billed']);

        // 4. Assert exactly one active challan and one item exist in the database
        $this->assertEquals(1, FeeChallan::where('student_id', $this->student->id)->where('status', '!=', 'void')->count());
        $this->assertEquals(1, FeeChallanItem::where('student_id', $this->student->id)->count());
    }

    /**
     * Scenario 2: Bulk assign runs first for October.
     * Subsequent admission challan generation for same student, same October is BLOCKED.
     */
    public function test_bulk_first_then_admission_same_october_blocks_duplicate(): void
    {
        // 1. Bulk assign workflow creates October challan first
        $result = FeeBulkAssignService::execute(
            $this->school->id,
            $this->tuitionStructure->id,
            $this->academicYear->id,
            $this->class->id,
            [
                'target_mode'    => 'entire_class',
                'execution_mode' => 'assign_and_bill',
                'billing_label'  => 'Tuition Fee',
                'issue_date'     => '2026-10-01',
            ],
            $this->adminUser
        );

        $this->assertEquals(1, $result['vouchers_generated']);
        $this->assertEquals(0, $result['already_billed']);

        $bulkChallan = FeeChallan::where('student_id', $this->student->id)->firstOrFail();
        $expectedCanonicalPeriodKey = "AY{$this->academicYear->id}-2026-10";
        $this->assertEquals($expectedCanonicalPeriodKey, $bulkChallan->billing_period_key);
        $this->assertEquals("{$this->student->id}_{$expectedCanonicalPeriodKey}", $bulkChallan->active_period_key);

        // 2. Admission voucher workflow attempts to create October challan
        $admChallan = FeeBillingService::createAdmissionChallan(
            $this->student,
            $this->academicYear,
            Carbon::parse('2026-10-01')
        );

        // 3. Assert null returned (gracefully skipped) and no duplicate created
        $this->assertNull($admChallan);
        $this->assertEquals(1, FeeChallan::where('student_id', $this->student->id)->where('status', '!=', 'void')->count());
        $this->assertEquals(1, FeeChallanItem::where('student_id', $this->student->id)->count());
    }

    /**
     * Scenario 3: September existing voucher + October generation.
     * Critical regression check: distinct months must be ALLOWED and NOT collapsed.
     */
    public function test_september_existing_and_october_generation_preserves_sequential_billing(): void
    {
        // 1. Generate September challan (e.g. from admission)
        $sepChallan = FeeBillingService::createAdmissionChallan(
            $this->student,
            $this->academicYear,
            Carbon::parse('2026-09-01')
        );
        $this->assertNotNull($sepChallan);
        $this->assertEquals("AY{$this->academicYear->id}-2026-09", $sepChallan->billing_period_key);

        // 2. Generate October challan via bulk assign
        $result = FeeBulkAssignService::execute(
            $this->school->id,
            $this->tuitionStructure->id,
            $this->academicYear->id,
            $this->class->id,
            [
                'target_mode'    => 'entire_class',
                'execution_mode' => 'assign_and_bill',
                'billing_label'  => 'Tuition Fee',
                'issue_date'     => '2026-10-01',
            ],
            $this->adminUser
        );

        $this->assertEquals(1, $result['vouchers_generated']);
        $this->assertEquals(0, $result['already_billed']);

        // 3. Assert both September and October challans exist independently
        $activeChallans = FeeChallan::where('student_id', $this->student->id)
            ->where('status', '!=', 'void')
            ->orderBy('issue_date')
            ->get();

        $this->assertCount(2, $activeChallans);
        $this->assertEquals("AY{$this->academicYear->id}-2026-09", $activeChallans[0]->billing_period_key);
        $this->assertEquals("AY{$this->academicYear->id}-2026-10", $activeChallans[1]->billing_period_key);
    }

    /**
     * Scenario 4: October existing voucher + November generation.
     * Sequential monthly billing continues cleanly without conflict.
     */
    public function test_october_existing_and_november_generation_preserves_sequential_billing(): void
    {
        // 1. October bulk assign
        $octResult = FeeBulkAssignService::execute(
            $this->school->id,
            $this->tuitionStructure->id,
            $this->academicYear->id,
            $this->class->id,
            [
                'target_mode'    => 'entire_class',
                'execution_mode' => 'assign_and_bill',
                'billing_label'  => 'Tuition Fee',
                'issue_date'     => '2026-10-01',
            ],
            $this->adminUser
        );
        $this->assertEquals(1, $octResult['vouchers_generated']);

        // 2. November bulk assign
        $novResult = FeeBulkAssignService::execute(
            $this->school->id,
            $this->tuitionStructure->id,
            $this->academicYear->id,
            $this->class->id,
            [
                'target_mode'    => 'entire_class',
                'execution_mode' => 'assign_and_bill',
                'billing_label'  => 'Tuition Fee',
                'issue_date'     => '2026-11-01',
            ],
            $this->adminUser
        );
        $this->assertEquals(1, $novResult['vouchers_generated']);

        // 3. Assert 2 distinct monthly challans exist
        $activeChallans = FeeChallan::where('student_id', $this->student->id)
            ->where('status', '!=', 'void')
            ->orderBy('issue_date')
            ->get();

        $this->assertCount(2, $activeChallans);
        $this->assertEquals("AY{$this->academicYear->id}-2026-10", $activeChallans[0]->billing_period_key);
        $this->assertEquals("AY{$this->academicYear->id}-2026-11", $activeChallans[1]->billing_period_key);
    }

    /**
     * Scenario 5: Multi-tenancy isolation. Same month in School A does not conflict with School B.
     */
    public function test_same_month_different_school_is_tenant_isolated(): void
    {
        // 1. Generate October challan for School A
        FeeBulkAssignService::execute(
            $this->school->id,
            $this->tuitionStructure->id,
            $this->academicYear->id,
            $this->class->id,
            [
                'target_mode'    => 'entire_class',
                'execution_mode' => 'assign_and_bill',
                'issue_date'     => '2026-10-01',
            ],
            $this->adminUser
        );

        // 2. Setup School B
        $schoolB = School::create([
            'name'       => 'Idempotency Secondary School',
            'domain'     => 'secondary-test',
            'is_active'  => true,
            'created_by' => 1,
        ]);

        $ayB = AcademicYear::create([
            'school_id'  => $schoolB->id,
            'name'       => '2026-2027',
            'start_date' => '2026-01-01',
            'end_date'   => '2026-12-31',
            'is_current' => true,
        ]);

        $classB = SchoolClass::create([
            'school_id'    => $schoolB->id,
            'name'         => 'Playgroup',
            'numeric_name' => 1,
        ]);

        $studentB = Student::create([
            'school_id'    => $schoolB->id,
            'class_id'     => $classB->id,
            'admission_no' => 'ADM-B-001',
            'first_name'   => 'Bilal',
            'last_name'    => 'Khan',
            'status'       => 'active',
        ]);

        $catB = FeeCategory::create([
            'school_id' => $schoolB->id,
            'name'      => 'Tuition Fee',
            'type'      => 'tuition',
            'is_active' => true,
        ]);

        $structB = FeeStructure::create([
            'school_id'                => $schoolB->id,
            'class_id'                 => $classB->id,
            'fee_category_id'          => $catB->id,
            'academic_year'            => '2026-2027',
            'amount'                   => '4000.00',
            'frequency'                => 'monthly',
            'is_active'                => true,
            'is_optional'              => false,
            'admission_voucher_policy' => 'required',
        ]);

        StudentFeeAssignment::create([
            'school_id'         => $schoolB->id,
            'student_id'        => $studentB->id,
            'fee_structure_id'  => $structB->id,
            'academic_year_id'  => $ayB->id,
            'is_active'         => true,
            'starts_on'         => '2026-10-01',
        ]);

        $adminB = User::create([
            'name'      => 'Admin School B',
            'email'     => 'admin@schoolb.test',
            'password'  => bcrypt('secret123'),
            'school_id' => $schoolB->id,
            'is_active' => true,
        ]);
        $adminB->assignRole('school-admin');

        // 3. Generate October challan for School B
        $resultB = FeeBulkAssignService::execute(
            $schoolB->id,
            $structB->id,
            $ayB->id,
            $classB->id,
            [
                'target_mode'    => 'entire_class',
                'execution_mode' => 'assign_and_bill',
                'issue_date'     => '2026-10-01',
            ],
            $adminB
        );

        $this->assertEquals(1, $resultB['vouchers_generated']);
        $this->assertEquals(1, FeeChallan::where('school_id', $this->school->id)->count());
        $this->assertEquals(1, FeeChallan::where('school_id', $schoolB->id)->count());
    }

    /**
     * Scenario 6: Same student has multiple legitimate fee structures.
     * Handled according to business rules: all assigned heads resolve onto one unified challan without key collisions.
     */
    public function test_same_student_different_fee_structures_handled_per_business_rules(): void
    {
        // Create second structure: Lab Fee (PKR 1,000 monthly)
        $labCategory = FeeCategory::create([
            'school_id' => $this->school->id,
            'name'      => 'Lab Fee',
            'type'      => 'other',
            'is_active' => true,
        ]);

        $labStructure = FeeStructure::create([
            'school_id'                => $this->school->id,
            'class_id'                 => $this->class->id,
            'fee_category_id'          => $labCategory->id,
            'academic_year'            => '2026-2027',
            'amount'                   => '1000.00',
            'frequency'                => 'monthly',
            'is_active'                => true,
            'is_optional'              => false,
            'admission_voucher_policy' => 'required',
        ]);

        StudentFeeAssignment::create([
            'school_id'         => $this->school->id,
            'student_id'        => $this->student->id,
            'fee_structure_id'  => $labStructure->id,
            'academic_year_id'  => $this->academicYear->id,
            'is_active'         => true,
            'starts_on'         => '2026-09-01',
        ]);

        // Generate October challan using unified FeeBillingService
        $challan = FeeBillingService::createChallan(
            $this->student,
            $this->academicYear,
            Carbon::parse('2026-10-01')
        );

        $this->assertNotNull($challan);
        // Total = 3,000 + 1,000 = 4,000
        $this->assertEquals('4000.00', $challan->total_payable);
        $this->assertEquals(2, $challan->items()->count());

        $tuitionItem = $challan->items()->where('fee_structure_id', $this->tuitionStructure->id)->firstOrFail();
        $labItem = $challan->items()->where('fee_structure_id', $labStructure->id)->firstOrFail();

        $this->assertEquals("AY{$this->academicYear->id}-M-2026-10", $tuitionItem->charge_period_key);
        $this->assertEquals("AY{$this->academicYear->id}-M-2026-10", $labItem->charge_period_key);
        $this->assertEquals("{$this->student->id}_{$this->tuitionStructure->id}_AY{$this->academicYear->id}-M-2026-10", $tuitionItem->active_charge_key);
        $this->assertEquals("{$this->student->id}_{$labStructure->id}_AY{$this->academicYear->id}-M-2026-10", $labItem->active_charge_key);

        // Calling createChallan a second time returns null (already billed)
        $secondChallan = FeeBillingService::createChallan(
            $this->student,
            $this->academicYear,
            Carbon::parse('2026-10-01')
        );
        $this->assertNull($secondChallan);
    }

    /**
     * Scenario 7: Concessions / discounts remain identical and do not affect canonical keys or idempotency.
     */
    public function test_discounts_and_concessions_do_not_alter_canonical_keys_or_bypass_idempotency(): void
    {
        // 20% concession on tuition fee
        StudentFeeDiscount::create([
            'school_id'       => $this->school->id,
            'student_id'      => $this->student->id,
            'fee_category_id' => $this->tuitionCategory->id,
            'type'            => 'percentage',
            'value'           => '20.00',
            'title'           => 'Staff Concession 20%',
            'is_active'       => true,
        ]);

        // First bulk assign for October
        $result1 = FeeBulkAssignService::execute(
            $this->school->id,
            $this->tuitionStructure->id,
            $this->academicYear->id,
            $this->class->id,
            [
                'target_mode'    => 'entire_class',
                'execution_mode' => 'assign_and_bill',
                'issue_date'     => '2026-10-01',
            ],
            $this->adminUser
        );

        $this->assertEquals(1, $result1['vouchers_generated']);

        $challan = FeeChallan::where('student_id', $this->student->id)->firstOrFail();
        $this->assertEquals('3000.00', $challan->gross_amount);
        $this->assertEquals('600.00', $challan->discount_amount);
        $this->assertEquals('2400.00', $challan->total_payable);
        // Canonical keys remain unaltered by concession
        $this->assertEquals("AY{$this->academicYear->id}-2026-10", $challan->billing_period_key);
        $this->assertEquals("{$this->student->id}_AY{$this->academicYear->id}-2026-10", $challan->active_period_key);

        // Second run: duplicate is strictly blocked
        $result2 = FeeBulkAssignService::execute(
            $this->school->id,
            $this->tuitionStructure->id,
            $this->academicYear->id,
            $this->class->id,
            [
                'target_mode'    => 'entire_class',
                'execution_mode' => 'assign_and_bill',
                'issue_date'     => '2026-10-01',
            ],
            $this->adminUser
        );

        $this->assertEquals(0, $result2['vouchers_generated']);
        $this->assertEquals(1, $result2['already_billed']);
        $this->assertEquals(1, FeeChallan::where('student_id', $this->student->id)->count());
    }

    /**
     * Scenario 8: Duplicate prevention works even when UI/display label differs.
     */
    public function test_duplicate_prevention_works_even_when_ui_display_label_differs(): void
    {
        // First run with label "Tuition Fee"
        $result1 = FeeBulkAssignService::execute(
            $this->school->id,
            $this->tuitionStructure->id,
            $this->academicYear->id,
            $this->class->id,
            [
                'target_mode'    => 'entire_class',
                'execution_mode' => 'assign_and_bill',
                'billing_label'  => 'Tuition Fee',
                'issue_date'     => '2026-10-01',
            ],
            $this->adminUser
        );
        $this->assertEquals(1, $result1['vouchers_generated']);

        // Second run with completely different display label "Special October Billing"
        $result2 = FeeBulkAssignService::execute(
            $this->school->id,
            $this->tuitionStructure->id,
            $this->academicYear->id,
            $this->class->id,
            [
                'target_mode'    => 'entire_class',
                'execution_mode' => 'assign_and_bill',
                'billing_label'  => 'Special October Billing',
                'issue_date'     => '2026-10-01',
            ],
            $this->adminUser
        );

        // Authoritative canonical key AY{id}-2026-10 prevents duplicate regardless of UI label
        $this->assertEquals(0, $result2['vouchers_generated']);
        $this->assertEquals(1, $result2['already_billed']);
        $this->assertEquals(1, FeeChallan::where('student_id', $this->student->id)->count());
    }

    /**
     * Scenario 9: Historical legacy data compatibility & cross-month isolation.
     * An existing legacy October challan (billing_period_key = "Tuition Fee", issue_date = 2026-10-01)
     * MUST block duplicate October generation, but MUST NOT block November or December generation.
     */
    public function test_historical_legacy_billing_period_keys_remain_compatible_and_prevent_duplicates(): void
    {
        // 1. Create historical legacy October challan matching old pre-fix structure
        $legacyChallan = FeeChallan::create([
            'school_id'                     => $this->school->id,
            'challan_no'                    => 'CHL-HIST-OCT',
            'billing_period_key'            => 'Tuition Fee',
            'active_period_key'             => "{$this->student->id}_Tuition Fee",
            'student_id'                    => $this->student->id,
            'class_id'                      => $this->class->id,
            'academic_year_id'              => $this->academicYear->id,
            'student_name'                  => $this->student->full_name,
            'admission_no'                  => $this->student->admission_no,
            'class_name'                    => 'Playgroup',
            'academic_year_name'            => '2026-2027',
            'issue_date'                    => '2026-10-01',
            'due_date'                      => '2026-10-05',
            'gross_amount'                  => '3000.00',
            'total_payable'                 => '3000.00',
            'status'                        => 'unpaid',
            'notes'                         => 'Tuition Fee',
        ]);

        FeeChallanItem::create([
            'school_id'         => $this->school->id,
            'student_id'        => $this->student->id,
            'fee_challan_id'    => $legacyChallan->id,
            'fee_structure_id'  => $this->tuitionStructure->id,
            'charge_period_key' => 'Tuition Fee',
            'active_charge_key' => "{$this->student->id}_{$this->tuitionStructure->id}_Tuition Fee",
            'fee_head_name'     => 'Tuition Fee',
            'gross_amount'      => '3000.00',
            'discount_amount'   => '0.00',
            'net_amount'        => '3000.00',
        ]);

        // Assert legacy challan displays historical label cleanly
        $this->assertEquals('Tuition Fee', $legacyChallan->billing_period_label);

        // 2. Generate October 2026 -> DUPLICATE BLOCKED
        $previewOct = FeeBulkAssignService::preview(
            $this->school->id,
            $this->tuitionStructure->id,
            $this->academicYear->id,
            $this->class->id,
            [
                'billing_label' => 'Tuition Fee',
                'issue_date'    => '2026-10-01',
            ]
        );
        $this->assertEquals(1, $previewOct['summary']['already_billed']);
        $this->assertEquals(0, $previewOct['summary']['vouchers_to_generate']);

        $resultOct = FeeBulkAssignService::execute(
            $this->school->id,
            $this->tuitionStructure->id,
            $this->academicYear->id,
            $this->class->id,
            [
                'target_mode'    => 'entire_class',
                'execution_mode' => 'assign_and_bill',
                'billing_label'  => 'Tuition Fee',
                'issue_date'     => '2026-10-01',
            ],
            $this->adminUser
        );
        $this->assertEquals(0, $resultOct['vouchers_generated']);
        $this->assertEquals(1, $resultOct['already_billed']);

        // 3. Generate November 2026 -> ALLOWED (new canonical AY{id}-2026-11 challan created)
        $previewNov = FeeBulkAssignService::preview(
            $this->school->id,
            $this->tuitionStructure->id,
            $this->academicYear->id,
            $this->class->id,
            [
                'billing_label' => 'Tuition Fee',
                'issue_date'    => '2026-11-01',
            ]
        );
        $this->assertEquals(0, $previewNov['summary']['already_billed']);
        $this->assertEquals(1, $previewNov['summary']['vouchers_to_generate']);

        $resultNov = FeeBulkAssignService::execute(
            $this->school->id,
            $this->tuitionStructure->id,
            $this->academicYear->id,
            $this->class->id,
            [
                'target_mode'    => 'entire_class',
                'execution_mode' => 'assign_and_bill',
                'billing_label'  => 'Tuition Fee',
                'issue_date'     => '2026-11-01',
            ],
            $this->adminUser
        );
        $this->assertEquals(1, $resultNov['vouchers_generated']);
        $this->assertEquals(0, $resultNov['already_billed']);

        $novChallan = FeeChallan::where('student_id', $this->student->id)
            ->where('issue_date', '2026-11-01')
            ->firstOrFail();
        $this->assertEquals("AY{$this->academicYear->id}-2026-11", $novChallan->billing_period_key);

        // 4. Generate December 2026 -> ALLOWED (new canonical AY{id}-2026-12 challan created)
        $previewDec = FeeBulkAssignService::preview(
            $this->school->id,
            $this->tuitionStructure->id,
            $this->academicYear->id,
            $this->class->id,
            [
                'billing_label' => 'Tuition Fee',
                'issue_date'    => '2026-12-01',
            ]
        );
        $this->assertEquals(0, $previewDec['summary']['already_billed']);
        $this->assertEquals(1, $previewDec['summary']['vouchers_to_generate']);

        $resultDec = FeeBulkAssignService::execute(
            $this->school->id,
            $this->tuitionStructure->id,
            $this->academicYear->id,
            $this->class->id,
            [
                'target_mode'    => 'entire_class',
                'execution_mode' => 'assign_and_bill',
                'billing_label'  => 'Tuition Fee',
                'issue_date'     => '2026-12-01',
            ],
            $this->adminUser
        );
        $this->assertEquals(1, $resultDec['vouchers_generated']);
        $this->assertEquals(0, $resultDec['already_billed']);

        $decChallan = FeeChallan::where('student_id', $this->student->id)
            ->where('issue_date', '2026-12-01')
            ->firstOrFail();
        $this->assertEquals("AY{$this->academicYear->id}-2026-12", $decChallan->billing_period_key);

        // 5. Total active challans for student: Exactly 3 (Legacy October + Canonical November + Canonical December)
        $this->assertEquals(3, FeeChallan::where('student_id', $this->student->id)->where('status', '!=', 'void')->count());
    }
}
