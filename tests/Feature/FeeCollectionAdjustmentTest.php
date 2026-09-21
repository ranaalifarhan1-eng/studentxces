<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\FeeCategory;
use App\Models\FeeChallan;
use App\Models\FeeChallanAdjustment;
use App\Models\FeeChallanItem;
use App\Models\FeePayment;
use App\Models\FeeStructure;
use App\Models\Package;
use App\Models\PackageModule;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\SchoolSubscription;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FeeCollectionAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected School $schoolB;
    protected User $adminUser;
    protected User $accountantUser;
    protected User $accountantWithAdjustmentUser;
    protected User $teacherUser;
    protected User $receptionistUser;
    protected AcademicYear $academicYear;
    protected SchoolClass $class;
    protected Section $section;
    protected Student $student;
    protected FeeCategory $tuitionCategory;
    protected FeeStructure $tuitionStructure;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed core permissions
        $pCollect = Permission::firstOrCreate(['name' => 'fees.collect', 'guard_name' => 'web']);
        $pView = Permission::firstOrCreate(['name' => 'fees.view', 'guard_name' => 'web']);
        $pStructure = Permission::firstOrCreate(['name' => 'fees.structure', 'guard_name' => 'web']);
        $pAssign = Permission::firstOrCreate(['name' => 'fees.assign', 'guard_name' => 'web']);
        $pDiscount = Permission::firstOrCreate(['name' => 'fees.discount', 'guard_name' => 'web']);
        $pAdjustment = Permission::firstOrCreate(['name' => 'fees.adjustment', 'guard_name' => 'web']);
        $pWaiver = Permission::firstOrCreate(['name' => 'fees.waiver', 'guard_name' => 'web']);

        $adminRole = Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions([$pCollect, $pView, $pStructure, $pAssign, $pDiscount, $pAdjustment, $pWaiver]);

        $accountantRole = Role::firstOrCreate(['name' => 'accountant', 'guard_name' => 'web']);
        $accountantRole->syncPermissions([$pCollect, $pView, $pStructure, $pAssign]);

        $teacherRole = Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web']);
        $teacherRole->syncPermissions([]);

        $receptionistRole = Role::firstOrCreate(['name' => 'receptionist', 'guard_name' => 'web']);
        $receptionistRole->syncPermissions([]);

        // Create main school
        $this->school = School::create([
            'name'   => 'Lahore Cambridge Test',
            'slug'   => 'lcs-test-' . uniqid(),
            'email'  => 'lcs-' . uniqid() . '@cambridge.test',
            'status' => 'active',
        ]);

        $package = Package::create([
            'name'          => 'Test Plan',
            'slug'          => 'test-plan-' . uniqid(),
            'price_monthly' => 30,
            'price_yearly'  => 300,
            'max_students'  => 500,
            'max_staff'     => 50,
            'storage_gb'    => 20,
            'is_active'     => true,
            'is_internal'   => false,
        ]);
        PackageModule::create(['package_id' => $package->id, 'module_slug' => 'fees']);

        SchoolSubscription::create([
            'school_id'   => $this->school->id,
            'package_id'  => $package->id,
            'status'      => 'active',
            'start_date'  => Carbon::now()->subDays(10),
            'end_date'    => Carbon::now()->addDays(30),
        ]);

        // Users
        $this->adminUser = User::create([
            'name'              => 'Admin LCS',
            'email'             => 'admin-' . uniqid() . '@lcs.test',
            'password'          => bcrypt('password'),
            'school_id'         => $this->school->id,
            'email_verified_at' => now(),
        ]);
        $this->adminUser->assignRole('school-admin');

        $this->accountantUser = User::create([
            'name'              => 'Accountant Basic',
            'email'             => 'acc-' . uniqid() . '@lcs.test',
            'password'          => bcrypt('password'),
            'school_id'         => $this->school->id,
            'email_verified_at' => now(),
        ]);
        $this->accountantUser->assignRole('accountant');

        $this->accountantWithAdjustmentUser = User::create([
            'name'              => 'Accountant With Adj',
            'email'             => 'acc-adj-' . uniqid() . '@lcs.test',
            'password'          => bcrypt('password'),
            'school_id'         => $this->school->id,
            'email_verified_at' => now(),
        ]);
        $this->accountantWithAdjustmentUser->assignRole('accountant');
        $this->accountantWithAdjustmentUser->givePermissionTo('fees.adjustment');

        $this->teacherUser = User::create([
            'name'              => 'Teacher LCS',
            'email'             => 'teacher-' . uniqid() . '@lcs.test',
            'password'          => bcrypt('password'),
            'school_id'         => $this->school->id,
            'email_verified_at' => now(),
        ]);
        $this->teacherUser->assignRole('teacher');

        $this->receptionistUser = User::create([
            'name'              => 'Receptionist LCS',
            'email'             => 'receptionist-' . uniqid() . '@lcs.test',
            'password'          => bcrypt('password'),
            'school_id'         => $this->school->id,
            'email_verified_at' => now(),
        ]);
        $this->receptionistUser->assignRole('receptionist');

        // Second School for tenant isolation testing
        $this->schoolB = School::create([
            'name'   => 'Other School Test',
            'slug'   => 'other-test-' . uniqid(),
            'email'  => 'other-' . uniqid() . '@other.test',
            'status' => 'active',
        ]);
        SchoolSubscription::create([
            'school_id'   => $this->schoolB->id,
            'package_id'  => $package->id,
            'status'      => 'active',
            'start_date'  => Carbon::now()->subDays(10),
            'end_date'    => Carbon::now()->addDays(30),
        ]);

        $this->academicYear = AcademicYear::create([
            'school_id'  => $this->school->id,
            'name'       => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date'   => '2027-08-31',
            'is_current' => true,
        ]);

        $this->class = SchoolClass::create([
            'school_id'    => $this->school->id,
            'name'         => 'Class 9',
            'numeric_name' => 9,
        ]);

        $this->section = Section::create([
            'school_id' => $this->school->id,
            'class_id'  => $this->class->id,
            'name'      => 'A',
        ]);

        $this->student = Student::create([
            'school_id'      => $this->school->id,
            'class_id'       => $this->class->id,
            'section_id'     => $this->section->id,
            'first_name'     => 'Hassan',
            'last_name'      => 'Shahzad',
            'admission_no'   => 'LCS-2026-001',
            'gender'         => 'male',
            'admission_date' => '2026-09-01',
            'status'         => 'active',
        ]);

        $this->tuitionCategory = FeeCategory::create([
            'school_id' => $this->school->id,
            'name'      => 'Tuition Fee',
            'type'      => 'tuition',
            'is_active' => true,
        ]);

        $this->tuitionStructure = FeeStructure::create([
            'school_id'       => $this->school->id,
            'class_id'        => $this->class->id,
            'fee_category_id' => $this->tuitionCategory->id,
            'academic_year'   => '2026-2027',
            'amount'          => 5000.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
            'is_optional'     => false,
        ]);
    }

    /** Helper to create a standard challan */
    protected function createTestChallan(
        float $gross = 5000.00,
        float $discount = 0.00,
        float $paid = 0.00,
        string $status = 'unpaid',
        ?School $school = null
    ): FeeChallan {
        $school = $school ?? $this->school;
        $totalPayable = max(0.0, $gross - $discount);

        $challan = FeeChallan::create([
            'school_id'            => $school->id,
            'student_id'           => $this->student->id,
            'class_id'             => $this->class->id,
            'section_id'           => $this->section->id,
            'academic_year_id'     => $this->academicYear->id,
            'student_name'         => $this->student->first_name . ' ' . $this->student->last_name,
            'admission_no'         => $this->student->admission_no,
            'class_name'           => $this->class->name,
            'section_name'         => $this->section->name,
            'academic_year_name'   => $this->academicYear->name,
            'challan_no'           => 'CHL-TEST-' . uniqid(),
            'billing_period_key'   => '2026-09',
            'issue_date'           => '2026-09-01',
            'due_date'             => '2026-09-15',
            'billing_period_month' => 9,
            'billing_period_year'  => 2026,
            'gross_amount'         => $gross,
            'discount_amount'      => $discount,
            'adjustment_amount'    => 0.00,
            'fine_amount'          => 0.00,
            'total_payable'        => $totalPayable,
            'paid_amount'          => $paid,
            'status'               => $status,
        ]);

        FeeChallanItem::create([
            'school_id'         => $school->id,
            'student_id'        => $this->student->id,
            'fee_challan_id'    => $challan->id,
            'fee_category_id'   => $this->tuitionCategory->id,
            'charge_period_key' => '2026-09',
            'fee_head_name'     => 'Tuition Fee',
            'gross_amount'      => $gross,
            'discount_amount'   => $discount,
            'net_amount'        => $totalPayable,
        ]);

        return $challan;
    }

    /** 1. Fixed amount adjustment reduces payable and does not create payment */
    public function test_fixed_adjustment_reduces_payable_and_balance_without_payment(): void
    {
        $challan = $this->createTestChallan(5000.00);

        $response = $this->actingAs($this->adminUser)
            ->post(route('school.fees.challans.adjustments.store', $challan), [
                'adjustment_type' => 'fixed',
                'value'           => 1000.00,
                'reason'          => 'hardship',
                'notes'           => 'Principal approved financial hardship concession',
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $challan->refresh();
        $this->assertEquals(1000.00, (float) $challan->adjustment_amount);
        $this->assertEquals(4000.00, (float) $challan->total_payable);
        $this->assertEquals(0.00, (float) $challan->paid_amount);
        $this->assertEquals('unpaid', $challan->status);

        $this->assertDatabaseHas('fee_challan_adjustments', [
            'fee_challan_id'    => $challan->id,
            'school_id'         => $this->school->id,
            'student_id'        => $this->student->id,
            'adjustment_type'   => 'fixed',
            'value'             => 1000.00,
            'adjustment_amount' => 1000.00,
            'previous_balance'  => 5000.00,
            'new_balance'       => 4000.00,
            'reason'            => 'hardship',
            'created_by'        => $this->adminUser->id,
        ]);

        // Zero payments created
        $this->assertEquals(0, FeePayment::count());
    }

    /** 2. Percentage adjustment computes against current balance */
    public function test_percentage_adjustment_computes_against_current_outstanding_balance(): void
    {
        // Challan 4000 payable
        $challan = $this->createTestChallan(4000.00);

        $response = $this->actingAs($this->adminUser)
            ->post(route('school.fees.challans.adjustments.store', $challan), [
                'adjustment_type' => 'percentage',
                'value'           => 25.00, // 25% of 4000 = 1000
                'reason'          => 'early_settlement',
                'notes'           => 'Approved 25% one-off early settlement waiver',
            ]);

        $response->assertSessionHasNoErrors();

        $challan->refresh();
        $this->assertEquals(1000.00, (float) $challan->adjustment_amount);
        $this->assertEquals(3000.00, (float) $challan->total_payable);

        $this->assertDatabaseHas('fee_challan_adjustments', [
            'fee_challan_id'    => $challan->id,
            'adjustment_type'   => 'percentage',
            'value'             => 25.00,
            'adjustment_amount' => 1000.00,
            'previous_balance'  => 4000.00,
            'new_balance'       => 3000.00,
        ]);
    }

    /** 3. Stacking with existing student concession */
    public function test_stacking_check_enforces_gross_cap(): void
    {
        // Gross 5000, student-level concession discount 1000 -> payable 4000, balance 4000
        $challan = $this->createTestChallan(5000.00, 1000.00);

        // Attempt adjustment of 4500: 1000 concession + 4500 adjustment = 5500 > 5000 gross
        $response = $this->actingAs($this->adminUser)
            ->post(route('school.fees.challans.adjustments.store', $challan), [
                'adjustment_type' => 'fixed',
                'value'           => 4500.00,
                'reason'          => 'discretionary',
                'notes'           => 'Exceeds gross cap test',
            ]);

        $response->assertSessionHasErrors('value');
        $this->assertEquals(0.00, (float) $challan->fresh()->adjustment_amount);

        // Valid adjustment of 1500: 1000 + 1500 = 2500 <= 5000 gross
        $responseValid = $this->actingAs($this->adminUser)
            ->post(route('school.fees.challans.adjustments.store', $challan), [
                'adjustment_type' => 'fixed',
                'value'           => 1500.00,
                'reason'          => 'discretionary',
                'notes'           => 'Valid stacked adjustment',
            ]);

        $responseValid->assertSessionHasNoErrors();
        $challan->refresh();
        $this->assertEquals(1500.00, (float) $challan->adjustment_amount);
        $this->assertEquals(2500.00, (float) $challan->total_payable);
        $this->assertEquals(2500.00, (float) $challan->balance);
    }

    /** 4. Partial payment followed by collection adjustment */
    public function test_partial_payment_followed_by_collection_adjustment(): void
    {
        // Gross 5000, payable 5000, partially paid 2000, balance 3000
        $challan = $this->createTestChallan(5000.00, 0.00, 2000.00, 'partial');

        FeePayment::create([
            'school_id'        => $this->school->id,
            'fee_challan_id'   => $challan->id,
            'student_id'       => $this->student->id,
            'amount_due'       => 5000.00,
            'amount_paid'      => 2000.00,
            'discount'         => 0.00,
            'fine'             => 0.00,
            'balance_snapshot' => 3000.00,
            'payment_date'     => '2026-09-05',
            'month_year'       => '2026-09',
            'method'           => 'cash',
            'status'           => 'partial',
            'receipt_no'       => 'RCP-TEST-001',
            'collected_by'     => $this->adminUser->id,
        ]);

        // Apply 50% adjustment on remaining 3000 balance -> 1500
        $response = $this->actingAs($this->adminUser)
            ->post(route('school.fees.challans.adjustments.store', $challan), [
                'adjustment_type' => 'percentage',
                'value'           => 50.00,
                'reason'          => 'management_waiver',
                'notes'           => 'Remaining 50% waiver approved by board',
            ]);

        $response->assertSessionHasNoErrors();

        $challan->refresh();
        $this->assertEquals(1500.00, (float) $challan->adjustment_amount);
        // Total payable was 5000, now 5000 - 1500 = 3500
        $this->assertEquals(3500.00, (float) $challan->total_payable);
        // Paid remains 2000
        $this->assertEquals(2000.00, (float) $challan->paid_amount);
        // Remaining balance = 3500 - 2000 = 1500
        $this->assertEquals(1500.00, (float) $challan->balance);
        $this->assertEquals('partial', $challan->status);
        $this->assertEquals('partial', $challan->display_status);
        $this->assertEquals('Partially Paid + Adjusted', $challan->settlement_classification);

        // Payments count remains exactly 1
        $this->assertEquals(1, FeePayment::count());
    }

    /** 4b. Partial payment followed by full remaining adjustment settles as Paid + Adjusted */
    public function test_partial_payment_followed_by_full_remaining_adjustment_settles_with_paid_adjusted(): void
    {
        // Gross 5000, payable 5000, partially paid 2000, balance 3000
        $challan = $this->createTestChallan(5000.00, 0.00, 2000.00, 'partial');

        FeePayment::create([
            'school_id'        => $this->school->id,
            'fee_challan_id'   => $challan->id,
            'student_id'       => $this->student->id,
            'amount_due'       => 5000.00,
            'amount_paid'      => 2000.00,
            'discount'         => 0.00,
            'fine'             => 0.00,
            'balance_snapshot' => 3000.00,
            'payment_date'     => '2026-09-05',
            'month_year'       => '2026-09',
            'method'           => 'cash',
            'status'           => 'partial',
            'receipt_no'       => 'RCP-TEST-002',
            'collected_by'     => $this->adminUser->id,
        ]);

        // Settle remaining 3000 balance via adjustment
        $response = $this->actingAs($this->adminUser)
            ->post(route('school.fees.challans.adjustments.store', $challan), [
                'adjustment_type' => 'fixed',
                'value'           => 3000.00,
                'reason'          => 'management_waiver',
                'notes'           => 'Clear remaining balance by management approval',
            ]);

        $response->assertSessionHasNoErrors();

        $challan->refresh();
        $this->assertEquals(3000.00, (float) $challan->adjustment_amount);
        $this->assertEquals(2000.00, (float) $challan->total_payable);
        $this->assertEquals(2000.00, (float) $challan->paid_amount);
        $this->assertEquals(0.00, (float) $challan->balance);
        $this->assertEquals('paid', $challan->status);
        $this->assertEquals('paid_adjusted', $challan->display_status);
        $this->assertEquals('Paid + Adjusted', $challan->settlement_classification);

        // Receipts / Payments count remains strictly 1
        $this->assertEquals(1, FeePayment::count());
    }

    /** 5. 100% adjustment settles challan fully with status = paid and display = waived */
    public function test_full_waiver_settles_challan_with_paid_status(): void
    {
        $challan = $this->createTestChallan(3000.00);

        $response = $this->actingAs($this->adminUser)
            ->post(route('school.fees.challans.adjustments.store', $challan), [
                'adjustment_type' => 'percentage',
                'value'           => 100.00,
                'reason'          => 'management_waiver',
                'notes'           => 'Full 100% scholarship settlement granted',
            ]);

        $response->assertSessionHasNoErrors();

        $challan->refresh();
        $this->assertEquals(3000.00, (float) $challan->adjustment_amount);
        $this->assertEquals(0.00, (float) $challan->total_payable);
        $this->assertEquals(0.00, (float) $challan->paid_amount);
        $this->assertEquals(0.00, (float) $challan->balance);
        $this->assertEquals('paid', $challan->status);
        $this->assertEquals('waived', $challan->display_status);
        $this->assertEquals('Settled — Adjusted', $challan->settlement_classification);

        // Crucial: FeePayment count must remain strictly 0
        $this->assertEquals(0, FeePayment::count());

        // Cannot apply another adjustment to now-paid challan
        $response2 = $this->actingAs($this->adminUser)
            ->post(route('school.fees.challans.adjustments.store', $challan), [
                'adjustment_type' => 'fixed',
                'value'           => 100.00,
                'reason'          => 'discretionary',
                'notes'           => 'Subsequent adjustment test',
            ]);

        $response2->assertSessionHasErrors('adjustment');
    }

    /** 6. Capping violations and input validations */
    public function test_adjustment_cannot_exceed_balance(): void
    {
        $challan = $this->createTestChallan(2000.00);

        // Adjustment greater than current balance (2500 > 2000)
        $response = $this->actingAs($this->adminUser)
            ->post(route('school.fees.challans.adjustments.store', $challan), [
                'adjustment_type' => 'fixed',
                'value'           => 2500.00,
                'reason'          => 'error_correction',
                'notes'           => 'Attempting over-adjustment',
            ]);

        $response->assertSessionHasErrors('value');
    }

    public function test_adjustment_percentage_cannot_exceed_100(): void
    {
        $challan = $this->createTestChallan(2000.00);

        $response = $this->actingAs($this->adminUser)
            ->post(route('school.fees.challans.adjustments.store', $challan), [
                'adjustment_type' => 'percentage',
                'value'           => 101.00,
                'reason'          => 'error_correction',
                'notes'           => 'Attempting > 100%',
            ]);

        $response->assertSessionHasErrors('value');
    }

    public function test_adjustment_value_must_be_positive(): void
    {
        $challan = $this->createTestChallan(2000.00);

        $response = $this->actingAs($this->adminUser)
            ->post(route('school.fees.challans.adjustments.store', $challan), [
                'adjustment_type' => 'fixed',
                'value'           => 0.00,
                'reason'          => 'error_correction',
                'notes'           => 'Zero value test',
            ]);

        $response->assertSessionHasErrors('value');

        $responseNeg = $this->actingAs($this->adminUser)
            ->post(route('school.fees.challans.adjustments.store', $challan), [
                'adjustment_type' => 'fixed',
                'value'           => -500.00,
                'reason'          => 'error_correction',
                'notes'           => 'Negative value test',
            ]);

        $responseNeg->assertSessionHasErrors('value');
    }

    public function test_adjustment_requires_valid_reason_and_note(): void
    {
        $challan = $this->createTestChallan(2000.00);

        // Missing reason
        $responseNoReason = $this->actingAs($this->adminUser)
            ->post(route('school.fees.challans.adjustments.store', $challan), [
                'adjustment_type' => 'fixed',
                'value'           => 500.00,
                'reason'          => '',
                'notes'           => 'Valid note here',
            ]);
        $responseNoReason->assertSessionHasErrors('reason');

        // Reason 'other' with missing note
        $responseOtherNoNote = $this->actingAs($this->adminUser)
            ->post(route('school.fees.challans.adjustments.store', $challan), [
                'adjustment_type' => 'fixed',
                'value'           => 500.00,
                'reason'          => 'other',
                'notes'           => '',
            ]);
        $responseOtherNoNote->assertSessionHasErrors('notes');

        // Reason 'other' with note too short (< 3 chars)
        $responseShortNote = $this->actingAs($this->adminUser)
            ->post(route('school.fees.challans.adjustments.store', $challan), [
                'adjustment_type' => 'fixed',
                'value'           => 500.00,
                'reason'          => 'other',
                'notes'           => 'ok',
            ]);
        $responseShortNote->assertSessionHasErrors('notes');

        // Reason 'other' with valid note (>= 3 chars) succeeds
        $responseOtherValid = $this->actingAs($this->adminUser)
            ->post(route('school.fees.challans.adjustments.store', $challan), [
                'adjustment_type' => 'fixed',
                'value'           => 500.00,
                'reason'          => 'other',
                'notes'           => 'Principal special exception order',
            ]);
        $responseOtherValid->assertSessionHasNoErrors();

        // Predefined reason with empty/null note succeeds
        $challan2 = $this->createTestChallan(2000.00);
        $responsePredefinedEmptyNote = $this->actingAs($this->adminUser)
            ->post(route('school.fees.challans.adjustments.store', $challan2), [
                'adjustment_type' => 'fixed',
                'value'           => 500.00,
                'reason'          => 'hardship',
                'notes'           => null,
            ]);
        $responsePredefinedEmptyNote->assertSessionHasNoErrors();
    }

    /** 7. Void challans cannot be adjusted */
    public function test_void_challan_cannot_be_adjusted(): void
    {
        $challan = $this->createTestChallan(2000.00, 0.00, 0.00, 'void');

        $response = $this->actingAs($this->adminUser)
            ->post(route('school.fees.challans.adjustments.store', $challan), [
                'adjustment_type' => 'fixed',
                'value'           => 500.00,
                'reason'          => 'hardship',
                'notes'           => 'Attempt on void challan',
            ]);

        $response->assertSessionHasErrors('adjustment');
    }

    /** 8. Permission matrix enforcement */
    public function test_school_admin_can_adjust(): void
    {
        $challan = $this->createTestChallan(2000.00);

        $response = $this->actingAs($this->adminUser)
            ->post(route('school.fees.challans.adjustments.store', $challan), [
                'adjustment_type' => 'fixed',
                'value'           => 500.00,
                'reason'          => 'discretionary',
                'notes'           => 'Admin allowed',
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
    }

    public function test_accountant_with_permission_can_adjust(): void
    {
        $challan = $this->createTestChallan(2000.00);

        $response = $this->actingAs($this->accountantWithAdjustmentUser)
            ->post(route('school.fees.challans.adjustments.store', $challan), [
                'adjustment_type' => 'fixed',
                'value'           => 500.00,
                'reason'          => 'discretionary',
                'notes'           => 'Accountant with permission allowed',
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
    }

    public function test_accountant_without_adjustment_permission_is_denied(): void
    {
        $challan = $this->createTestChallan(2000.00);

        $response = $this->actingAs($this->accountantUser)
            ->post(route('school.fees.challans.adjustments.store', $challan), [
                'adjustment_type' => 'fixed',
                'value'           => 500.00,
                'reason'          => 'discretionary',
                'notes'           => 'Accountant denied',
            ]);

        $response->assertStatus(403);
    }

    public function test_teacher_is_denied(): void
    {
        $challan = $this->createTestChallan(2000.00);

        $response = $this->actingAs($this->teacherUser)
            ->post(route('school.fees.challans.adjustments.store', $challan), [
                'adjustment_type' => 'fixed',
                'value'           => 500.00,
                'reason'          => 'discretionary',
                'notes'           => 'Teacher denied',
            ]);

        $response->assertStatus(403);
    }

    /** 9. Multi-tenant isolation */
    public function test_cross_tenant_adjustment_is_denied(): void
    {
        // Challan belonging to School B
        $classB = SchoolClass::create([
            'school_id'    => $this->schoolB->id,
            'name'         => 'Class 9B',
            'numeric_name' => 9,
        ]);
        $sectionB = Section::create([
            'school_id' => $this->schoolB->id,
            'class_id'  => $classB->id,
            'name'      => 'A',
        ]);
        $academicYearB = AcademicYear::create([
            'school_id'  => $this->schoolB->id,
            'name'       => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date'   => '2027-08-31',
            'is_current' => true,
        ]);
        $studentB = Student::create([
            'school_id'      => $this->schoolB->id,
            'class_id'       => $classB->id,
            'section_id'     => $sectionB->id,
            'first_name'     => 'Ali',
            'last_name'      => 'Khan',
            'admission_no'   => 'SCHB-2026-001',
            'gender'         => 'male',
            'admission_date' => '2026-09-01',
            'status'         => 'active',
        ]);
        $challanB = FeeChallan::create([
            'school_id'            => $this->schoolB->id,
            'student_id'           => $studentB->id,
            'class_id'             => $classB->id,
            'section_id'           => $sectionB->id,
            'academic_year_id'     => $academicYearB->id,
            'student_name'         => 'Ali Khan',
            'admission_no'         => 'SCHB-2026-001',
            'class_name'           => 'Class 9B',
            'section_name'         => 'A',
            'academic_year_name'   => '2026-2027',
            'challan_no'           => 'CHL-TEST-B-' . uniqid(),
            'billing_period_key'   => '2026-09',
            'issue_date'           => '2026-09-01',
            'due_date'             => '2026-09-15',
            'billing_period_month' => 9,
            'billing_period_year'  => 2026,
            'gross_amount'         => 2000.00,
            'discount_amount'      => 0.00,
            'adjustment_amount'    => 0.00,
            'fine_amount'          => 0.00,
            'total_payable'        => 2000.00,
            'paid_amount'          => 0.00,
            'status'               => 'unpaid',
        ]);

        // School A Admin attempts to adjust School B challan
        $response = $this->actingAs($this->adminUser)
            ->post(route('school.fees.challans.adjustments.store', $challanB), [
                'adjustment_type' => 'fixed',
                'value'           => 500.00,
                'reason'          => 'discretionary',
                'notes'           => 'Cross-tenant attack',
            ]);

        // Either 404 (due to tenant scope) or 403
        $this->assertTrue(in_array($response->getStatusCode(), [403, 404]));
    }

    public function test_receptionist_is_denied(): void
    {
        $challan = $this->createTestChallan(2000.00);

        $response = $this->actingAs($this->receptionistUser)
            ->post(route('school.fees.challans.adjustments.store', $challan), [
                'adjustment_type' => 'fixed',
                'value'           => 500.00,
                'reason'          => 'discretionary',
                'notes'           => 'Receptionist denied',
            ]);

        $response->assertStatus(403);
    }

    /** 10. Idempotency guarantees */
    public function test_idempotent_adjustment_submission_returns_existing_record_without_duplicate_deductions(): void
    {
        $challan = $this->createTestChallan(5000.00);
        $idempotencyKey = (string) \Illuminate\Support\Str::uuid();

        // First submission
        $res1 = $this->actingAs($this->adminUser)
            ->post(route('school.fees.challans.adjustments.store', $challan), [
                'adjustment_type' => 'fixed',
                'value'           => 1000.00,
                'reason'          => 'management_waiver',
                'notes'           => 'Idempotent test deduction',
                'idempotency_key' => $idempotencyKey,
            ]);

        $res1->assertSessionHasNoErrors();
        $challan->refresh();
        $this->assertEquals(1000.00, (float) $challan->adjustment_amount);
        $this->assertEquals(4000.00, (float) $challan->total_payable);
        $this->assertEquals(4000.00, (float) $challan->balance);
        $this->assertEquals(1, FeeChallanAdjustment::where('school_id', $this->school->id)->count());

        // Replay same submission with identical idempotency key
        $res2 = $this->actingAs($this->adminUser)
            ->post(route('school.fees.challans.adjustments.store', $challan), [
                'adjustment_type' => 'fixed',
                'value'           => 1000.00,
                'reason'          => 'management_waiver',
                'notes'           => 'Idempotent test deduction replay',
                'idempotency_key' => $idempotencyKey,
            ]);

        $res2->assertSessionHasNoErrors();
        $challan->refresh();
        // Crucial: balance and adjustment amount MUST NOT have changed!
        $this->assertEquals(1000.00, (float) $challan->adjustment_amount);
        $this->assertEquals(4000.00, (float) $challan->total_payable);
        $this->assertEquals(4000.00, (float) $challan->balance);
        // DB row count must remain strictly 1
        $this->assertEquals(1, FeeChallanAdjustment::where('school_id', $this->school->id)->count());
    }

    public function test_distinct_idempotency_keys_allow_sequential_adjustments(): void
    {
        $challan = $this->createTestChallan(5000.00);
        $key1 = (string) \Illuminate\Support\Str::uuid();
        $key2 = (string) \Illuminate\Support\Str::uuid();

        // First adjustment: PKR 1000
        $res1 = $this->actingAs($this->adminUser)
            ->post(route('school.fees.challans.adjustments.store', $challan), [
                'adjustment_type' => 'fixed',
                'value'           => 1000.00,
                'reason'          => 'management_waiver',
                'notes'           => 'First stage waiver',
                'idempotency_key' => $key1,
            ]);
        $res1->assertSessionHasNoErrors();

        // Second adjustment: PKR 500 with different key
        $res2 = $this->actingAs($this->adminUser)
            ->post(route('school.fees.challans.adjustments.store', $challan), [
                'adjustment_type' => 'fixed',
                'value'           => 500.00,
                'reason'          => 'hardship',
                'notes'           => 'Second stage hardship',
                'idempotency_key' => $key2,
            ]);
        $res2->assertSessionHasNoErrors();

        $challan->refresh();
        $this->assertEquals(1500.00, (float) $challan->adjustment_amount);
        $this->assertEquals(3500.00, (float) $challan->total_payable);
        $this->assertEquals(3500.00, (float) $challan->balance);
        $this->assertEquals(2, FeeChallanAdjustment::where('school_id', $this->school->id)->count());
    }

    /** 11. Revenue vs Adjustment accounting integrity */
    public function test_revenue_integrity_adjustments_do_not_count_as_collected_revenue(): void
    {
        // Challan 1: PKR 5000, receives actual cash payment of PKR 2000
        $challanPaid = $this->createTestChallan(5000.00, 0.00, 2000.00, 'partial');
        FeePayment::create([
            'school_id'        => $this->school->id,
            'fee_challan_id'   => $challanPaid->id,
            'student_id'       => $this->student->id,
            'amount_due'       => 5000.00,
            'amount_paid'      => 2000.00,
            'discount'         => 0.00,
            'fine'             => 0.00,
            'balance_snapshot' => 3000.00,
            'payment_date'     => '2026-09-05',
            'month_year'       => '2026-09',
            'method'           => 'cash',
            'status'           => 'partial',
            'receipt_no'       => 'RCP-CASH-001',
            'collected_by'     => $this->adminUser->id,
        ]);

        // Challan 2: PKR 4000, receives 100% full waiver adjustment (no cash)
        $challanWaived = $this->createTestChallan(4000.00);
        $res = $this->actingAs($this->adminUser)
            ->post(route('school.fees.challans.adjustments.store', $challanWaived), [
                'adjustment_type' => 'percentage',
                'value'           => 100.00,
                'reason'          => 'management_waiver',
                'notes'           => 'Full board waiver',
            ]);
        $res->assertSessionHasNoErrors();

        // 1. FeePayment metrics: strictly reflects cash received
        $totalCollected = FeePayment::where('school_id', $this->school->id)->sum('amount_paid');
        $totalReceipts  = FeePayment::where('school_id', $this->school->id)->count();
        $this->assertEquals(2000.00, (float) $totalCollected);
        $this->assertEquals(1, $totalReceipts);

        // 2. FeeChallanAdjustment metrics: non-cash liability reductions
        $totalAdjustments = FeeChallanAdjustment::where('school_id', $this->school->id)->sum('adjustment_amount');
        $totalAdjCount    = FeeChallanAdjustment::where('school_id', $this->school->id)->count();
        $this->assertEquals(4000.00, (float) $totalAdjustments);
        $this->assertEquals(1, $totalAdjCount);

        // 3. Challan settlement display semantics
        $challanPaid->refresh();
        $challanWaived->refresh();
        $this->assertEquals('Partially Paid', $challanPaid->settlement_classification);
        $this->assertEquals('Settled — Adjusted', $challanWaived->settlement_classification);
        $this->assertEquals('waived', $challanWaived->display_status);
        $this->assertEquals('paid', $challanWaived->status);
    }
}
