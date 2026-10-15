<?php

namespace Tests\Feature\SchoolAdmin;

use App\Models\AcademicYear;
use App\Models\FeeCategory;
use App\Models\FeeChallan;
use App\Models\FeePayment;
use App\Models\FeeStructure;
use App\Models\Package;
use App\Models\PackageModule;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\SchoolSubscription;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentFeeAssignment;
use App\Models\User;
use App\Services\FeeBillingService;
use App\Services\StudentFeeAssignmentService;
use App\Services\StudentFinancialLedgerService;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FeePaymentRelationalIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected School $schoolB;
    protected User $adminUser;
    protected AcademicYear $academicYear;
    protected SchoolClass $class;
    protected Section $section;
    protected Student $studentA;
    protected Student $studentB;
    protected FeeStructure $feeStructure;

    protected function setUp(): void
    {
        parent::setUp();

        $pView = Permission::firstOrCreate(['name' => 'fees.view', 'guard_name' => 'web']);
        $pCollect = Permission::firstOrCreate(['name' => 'fees.collect', 'guard_name' => 'web']);
        $pAdjust = Permission::firstOrCreate(['name' => 'fees.adjustment', 'guard_name' => 'web']);
        $adminRole = Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions([$pView, $pCollect, $pAdjust]);

        $this->school = School::create([
            'name'     => 'Lahore Cambridge Test',
            'slug'     => 'lcs-test-' . uniqid(),
            'email'    => 'admin-' . uniqid() . '@lcs.test',
            'status'   => 'active',
            'timezone' => 'Asia/Karachi',
        ]);

        $this->schoolB = School::create([
            'name'     => 'Other School',
            'slug'     => 'other-' . uniqid(),
            'email'    => 'admin-' . uniqid() . '@other.test',
            'status'   => 'active',
            'timezone' => 'Asia/Karachi',
        ]);

        $package = Package::create([
            'name'          => 'Pro Plan',
            'slug'          => 'pro-' . uniqid(),
            'price_monthly' => 50,
            'price_yearly'  => 500,
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

        $this->adminUser = User::create([
            'name'              => 'Admin User',
            'email'             => 'admin-' . uniqid() . '@lcs.test',
            'password'          => bcrypt('password'),
            'school_id'         => $this->school->id,
            'email_verified_at' => now(),
        ]);
        $this->adminUser->assignRole('school-admin');

        $this->academicYear = AcademicYear::create([
            'school_id'  => $this->school->id,
            'name'       => '2026-2027',
            'start_date' => Carbon::now()->startOfYear()->toDateString(),
            'end_date'   => Carbon::now()->endOfYear()->toDateString(),
            'is_current' => true,
        ]);

        $this->class = SchoolClass::create([
            'school_id'    => $this->school->id,
            'name'         => 'Grade 7',
            'numeric_name' => 7,
        ]);

        $this->section = Section::create([
            'school_id' => $this->school->id,
            'class_id'  => $this->class->id,
            'name'      => 'Section A',
            'capacity'  => 30,
        ]);

        $this->studentA = Student::create([
            'school_id'      => $this->school->id,
            'class_id'       => $this->class->id,
            'section_id'     => $this->section->id,
            'admission_no'   => 'LCS-2026-001',
            'first_name'     => 'Student',
            'last_name'      => 'A',
            'gender'         => 'male',
            'status'         => 'active',
            'date_of_birth'  => '2014-01-01',
            'admission_date' => '2026-08-01',
        ]);

        $this->studentB = Student::create([
            'school_id'      => $this->school->id,
            'class_id'       => $this->class->id,
            'section_id'     => $this->section->id,
            'admission_no'   => 'LCS-2026-002',
            'first_name'     => 'Student',
            'last_name'      => 'B',
            'gender'         => 'female',
            'status'         => 'active',
            'date_of_birth'  => '2014-05-01',
            'admission_date' => '2026-08-01',
        ]);

        $category = FeeCategory::create([
            'school_id' => $this->school->id,
            'name'      => 'Tuition',
            'type'      => 'tuition',
        ]);

        $this->feeStructure = FeeStructure::create([
            'school_id'        => $this->school->id,
            'fee_category_id'  => $category->id,
            'class_id'         => $this->class->id,
            'academic_year'    => '2026-2027',
            'amount'           => '5000.00',
            'frequency'        => 'monthly',
            'is_optional'      => false,
        ]);

        StudentFeeAssignment::create([
            'school_id'         => $this->school->id,
            'student_id'        => $this->studentA->id,
            'fee_structure_id'  => $this->feeStructure->id,
            'academic_year_id'  => $this->academicYear->id,
            'is_active'         => true,
            'starts_on'         => '2026-08-01',
            'assigned_by'       => $this->adminUser->id,
            'assigned_at'       => now(),
        ]);
        StudentFeeAssignment::create([
            'school_id'         => $this->school->id,
            'student_id'        => $this->studentB->id,
            'fee_structure_id'  => $this->feeStructure->id,
            'academic_year_id'  => $this->academicYear->id,
            'is_active'         => true,
            'starts_on'         => '2026-08-01',
            'assigned_by'       => $this->adminUser->id,
            'assigned_at'       => now(),
        ]);
    }

    public function test_payment_is_strictly_rejected_when_challan_belongs_to_different_student(): void
    {
        // 1. Generate Challan belonging to Student B
        $challanB = FeeBillingService::createChallan(
            $this->studentB,
            $this->academicYear,
            Carbon::create(2026, 10, 1),
            Carbon::create(2026, 10, 10)
        );
        $this->assertNotNull($challanB);
        $this->assertEquals($this->studentB->id, $challanB->student_id);
        $this->assertEquals('5000.00', $challanB->total_payable);
        $this->assertEquals('0.00', $challanB->paid_amount);

        // Pre-attempt state snapshots
        $paymentCountBefore = FeePayment::count();
        $summaryABefore = StudentFinancialLedgerService::summary($this->studentA);
        $summaryBBefore = StudentFinancialLedgerService::summary($this->studentB);

        // 2. Submit payment attempting to credit Student A using Student B's Challan
        $payload = [
            'student_id'       => $this->studentA->id,
            'fee_challan_id'   => $challanB->id,
            'amount_paid'      => '5000.00',
            'payment_date'     => '2026-10-15',
            'method'           => 'cash',
            'idempotency_key'  => (string) Str::uuid(),
        ];

        $response = $this->actingAs($this->adminUser)
            ->post('/school/fees/payments', $payload);

        // 3. Assertions
        $response->assertSessionHasErrors(['fee_challan_id', 'student_id']);

        // Assert no records created or mutated
        $this->assertEquals($paymentCountBefore, FeePayment::count(), 'Zero FeePayment rows must be created.');

        $challanBFresh = $challanB->fresh();
        $this->assertEquals('0.00', $challanBFresh->paid_amount, 'Student B challan paid_amount must remain untouched.');
        $this->assertEquals('unpaid', $challanBFresh->status, 'Student B challan status must remain unpaid.');

        // Financial ledgers for both students must be completely untouched
        $summaryAAfter = StudentFinancialLedgerService::summary($this->studentA);
        $summaryBAfter = StudentFinancialLedgerService::summary($this->studentB);
        $this->assertEquals($summaryABefore, $summaryAAfter, 'Student A ledger must be identical.');
        $this->assertEquals($summaryBBefore, $summaryBAfter, 'Student B ledger must be identical.');
    }

    public function test_payment_rejected_when_challan_belongs_to_another_school(): void
    {
        // Setup Challan in School B
        $classB = SchoolClass::create([
            'school_id'    => $this->schoolB->id,
            'name'         => 'Class Other',
            'numeric_name' => 1,
        ]);
        $sectionB = Section::create([
            'school_id' => $this->schoolB->id,
            'class_id'  => $classB->id,
            'name'      => 'A',
            'capacity'  => 20,
        ]);
        $studentOtherSchool = Student::create([
            'school_id'      => $this->schoolB->id,
            'class_id'       => $classB->id,
            'section_id'     => $sectionB->id,
            'admission_no'   => 'SCHB-001',
            'first_name'     => 'Other',
            'last_name'      => 'Student',
            'gender'         => 'male',
            'status'         => 'active',
            'admission_date' => '2026-08-01',
        ]);
        $ayB = AcademicYear::create([
            'school_id'  => $this->schoolB->id,
            'name'       => '2026-2027',
            'start_date' => Carbon::now()->startOfYear()->toDateString(),
            'end_date'   => Carbon::now()->endOfYear()->toDateString(),
            'is_current' => true,
        ]);
        $catB = FeeCategory::create([
            'school_id' => $this->schoolB->id,
            'name'      => 'Tuition',
            'type'      => 'tuition',
        ]);
        $structB = FeeStructure::create([
            'school_id'        => $this->schoolB->id,
            'fee_category_id'  => $catB->id,
            'class_id'         => $classB->id,
            'academic_year'    => '2026-2027',
            'amount'           => '3000.00',
            'frequency'        => 'monthly',
        ]);
        StudentFeeAssignment::create([
            'school_id'         => $this->schoolB->id,
            'student_id'        => $studentOtherSchool->id,
            'fee_structure_id'  => $structB->id,
            'academic_year_id'  => $ayB->id,
            'is_active'         => true,
            'starts_on'         => '2026-08-01',
            'assigned_by'       => $this->adminUser->id,
            'assigned_at'       => now(),
        ]);
        $challanOtherSchool = FeeBillingService::createChallan(
            $studentOtherSchool,
            $ayB,
            Carbon::create(2026, 10, 1),
            Carbon::create(2026, 10, 10)
        );

        $paymentCountBefore = FeePayment::count();

        $payload = [
            'student_id'       => $this->studentA->id,
            'fee_challan_id'   => $challanOtherSchool->id,
            'amount_paid'      => '3000.00',
            'payment_date'     => '2026-10-15',
            'method'           => 'cash',
            'idempotency_key'  => (string) Str::uuid(),
        ];

        $response = $this->actingAs($this->adminUser)
            ->post('/school/fees/payments', $payload);

        $response->assertSessionHasErrors(['fee_challan_id']);
        $this->assertEquals($paymentCountBefore, FeePayment::count());
    }

    public function test_payment_rejected_for_void_challan(): void
    {
        $challanA = FeeBillingService::createChallan(
            $this->studentA,
            $this->academicYear,
            Carbon::create(2026, 10, 1),
            Carbon::create(2026, 10, 10)
        );
        $challanA->voidChallan('Test cancellation', $this->adminUser->id);
        $this->assertEquals('void', $challanA->fresh()->status);

        $paymentCountBefore = FeePayment::count();

        $payload = [
            'student_id'       => $this->studentA->id,
            'fee_challan_id'   => $challanA->id,
            'amount_paid'      => '5000.00',
            'payment_date'     => '2026-10-15',
            'method'           => 'cash',
            'idempotency_key'  => (string) Str::uuid(),
        ];

        $response = $this->actingAs($this->adminUser)
            ->post('/school/fees/payments', $payload);

        $response->assertSessionHasErrors(['fee_challan_id']);
        $this->assertEquals($paymentCountBefore, FeePayment::count());
        $this->assertEquals('0.00', $challanA->fresh()->paid_amount);
    }

    public function test_payment_rejected_for_already_paid_challan(): void
    {
        $challanA = FeeBillingService::createChallan(
            $this->studentA,
            $this->academicYear,
            Carbon::create(2026, 10, 1),
            Carbon::create(2026, 10, 10)
        );

        // First payment fully settles challan
        $payload1 = [
            'student_id'       => $this->studentA->id,
            'fee_challan_id'   => $challanA->id,
            'amount_paid'      => '5000.00',
            'payment_date'     => '2026-10-15',
            'method'           => 'cash',
            'idempotency_key'  => (string) Str::uuid(),
        ];
        $this->actingAs($this->adminUser)->post('/school/fees/payments', $payload1)->assertSessionHasNoErrors();
        $this->assertEquals('paid', $challanA->fresh()->status);

        // Second payment attempt on paid challan must be rejected
        $payload2 = [
            'student_id'       => $this->studentA->id,
            'fee_challan_id'   => $challanA->id,
            'amount_paid'      => '100.00',
            'payment_date'     => '2026-10-15',
            'method'           => 'cash',
            'idempotency_key'  => (string) Str::uuid(),
        ];

        $res2 = $this->actingAs($this->adminUser)->post('/school/fees/payments', $payload2);
        $res2->assertSessionHasErrors(['fee_challan_id']);
        $this->assertEquals(1, FeePayment::where('fee_challan_id', $challanA->id)->count());
    }

    public function test_payment_succeeds_when_student_and_challan_match(): void
    {
        $challanA = FeeBillingService::createChallan(
            $this->studentA,
            $this->academicYear,
            Carbon::create(2026, 10, 1),
            Carbon::create(2026, 10, 10)
        );

        $payload = [
            'student_id'       => $this->studentA->id,
            'fee_challan_id'   => $challanA->id,
            'amount_paid'      => '5000.00',
            'payment_date'     => '2026-10-15',
            'method'           => 'cash',
            'idempotency_key'  => (string) Str::uuid(),
        ];

        $response = $this->actingAs($this->adminUser)->post('/school/fees/payments', $payload);
        $response->assertSessionHasNoErrors();

        $payment = FeePayment::where('fee_challan_id', $challanA->id)->first();
        $this->assertNotNull($payment);
        $this->assertEquals('5000.00', $payment->amount_paid);
        $this->assertEquals('paid', $challanA->fresh()->status);
        $this->assertNotEmpty($payment->receipt_no);
    }
}
