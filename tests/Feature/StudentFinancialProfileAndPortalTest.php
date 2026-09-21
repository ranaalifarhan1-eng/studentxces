<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\FeeCategory;
use App\Models\FeeChallan;
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
use App\Models\StudentFeeAssignment;
use App\Models\StudentFeeDiscount;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StudentFinancialProfileAndPortalTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected User $adminUser;
    protected Package $package;
    protected AcademicYear $academicYear;
    protected SchoolClass $class;
    protected Section $section;
    protected Student $student;
    protected FeeCategory $tuitionCategory;
    protected FeeCategory $admissionCategory;

    protected function setUp(): void
    {
        parent::setUp();

        // Roles & Permissions
        $permissions = [
            'students.view', 'students.create', 'students.edit', 'students.delete',
            'fees.view', 'fees.collect', 'fees.structure',
        ];
        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $role = Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);
        $role->syncPermissions($permissions);

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'parent', 'guard_name' => 'web']);

        // School
        $this->school = School::create([
            'name'   => 'Greenfield Academy Test',
            'slug'   => 'greenfield-test-' . uniqid(),
            'email'  => 'info@greenfield-' . uniqid() . '.edu.pk',
            'status' => 'active',
        ]);

        // Subscription with required modules
        $this->package = Package::create([
            'name'          => 'Enterprise Plan',
            'slug'          => 'plan-' . uniqid(),
            'price_monthly' => 50,
            'price_yearly'  => 500,
            'max_students'  => 500,
            'max_staff'     => 50,
            'storage_gb'    => 50,
            'is_active'     => true,
            'is_internal'   => false,
        ]);
        PackageModule::create(['package_id' => $this->package->id, 'module_slug' => 'students']);
        PackageModule::create(['package_id' => $this->package->id, 'module_slug' => 'fees']);

        SchoolSubscription::create([
            'school_id'  => $this->school->id,
            'package_id' => $this->package->id,
            'status'     => 'active',
            'start_date' => Carbon::now()->subDays(10),
            'end_date'   => Carbon::now()->addDays(50),
        ]);

        // Admin User
        $this->adminUser = User::create([
            'name'              => 'School Administrator',
            'email'             => 'admin-' . uniqid() . '@greenfield.edu.pk',
            'password'          => bcrypt('password'),
            'school_id'         => $this->school->id,
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->adminUser->assignRole('school-admin');

        // Academic Year
        $this->academicYear = AcademicYear::create([
            'school_id'  => $this->school->id,
            'name'       => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date'   => '2027-08-31',
            'is_current' => true,
        ]);

        // Class & Section
        $this->class = SchoolClass::create([
            'school_id'    => $this->school->id,
            'name'         => 'Class 5',
            'numeric_name' => 5,
        ]);

        $this->section = Section::create([
            'school_id' => $this->school->id,
            'class_id'  => $this->class->id,
            'name'      => 'A',
            'capacity'  => 30,
        ]);

        // Categories
        $this->tuitionCategory = FeeCategory::create([
            'school_id' => $this->school->id,
            'name'      => 'Tuition Fee',
            'type'      => 'tuition',
            'is_active' => true,
        ]);

        $this->admissionCategory = FeeCategory::create([
            'school_id' => $this->school->id,
            'name'      => 'Admission Fee',
            'type'      => 'other',
            'is_active' => true,
        ]);

        // Student
        $this->student = Student::create([
            'school_id'             => $this->school->id,
            'class_id'              => $this->class->id,
            'section_id'            => $this->section->id,
            'admission_no'          => 'ADM-2026-0001',
            'first_name'            => 'Hassan',
            'last_name'             => 'Shahzad',
            'gender'                => 'male',
            'dob'                   => '2015-05-15',
            'admission_date'        => '2026-09-01',
            'status'                => 'active',
            'academic_year'         => '2026-2027',
            'student_category'      => 'regular',
        ]);
    }

    protected function createChallan(array $overrides = []): FeeChallan
    {
        return FeeChallan::create(array_merge([
            'school_id'          => $this->school->id,
            'student_id'         => $this->student->id,
            'class_id'           => $this->class->id,
            'section_id'         => $this->section->id,
            'academic_year_id'   => $this->academicYear->id,
            'student_name'       => $this->student->first_name . ' ' . $this->student->last_name,
            'admission_no'       => $this->student->admission_no,
            'class_name'         => $this->class->name,
            'section_name'       => $this->section->name,
            'academic_year_name' => $this->academicYear->name,
            'challan_no'         => 'CHL-' . uniqid(),
            'billing_period_key' => '2026-09',
            'issue_date'         => '2026-09-01',
            'due_date'           => '2026-09-10',
            'gross_amount'       => '3000.00',
            'discount_amount'    => '0.00',
            'fine_amount'        => '0.00',
            'total_payable'      => '3000.00',
            'paid_amount'        => '0.00',
            'status'             => 'unpaid',
        ], $overrides));
    }

    public function test_student_profile_loads_financial_summary_and_portal_user(): void
    {
        // Attach challan
        $challan = $this->createChallan([
            'challan_no'      => 'CHL-2026-00001',
            'gross_amount'    => '3000.00',
            'discount_amount' => '500.00',
            'total_payable'   => '2500.00',
        ]);

        FeeChallanItem::create([
            'school_id'          => $this->school->id,
            'student_id'         => $this->student->id,
            'fee_challan_id'     => $challan->id,
            'fee_category_id'    => $this->tuitionCategory->id,
            'charge_period_key'  => '2026-09',
            'fee_head_name'      => 'Tuition Fee',
            'gross_amount'       => '3000.00',
            'discount_amount'    => '500.00',
            'net_amount'         => '2500.00',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->get(route('school.students.show', $this->student->id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Students/Show')
            ->has('student')
            ->has('financialSummary')
            ->has('challans')
            ->has('payments')
            ->where('financialSummary.total_billed', '2500.00')
            ->where('financialSummary.outstanding_balance', '2500.00')
        );
    }

    public function test_create_portal_access_generates_student_user_and_session_flash_credentials(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->post(route('school.students.portal-access.create', $this->student->id), [
                'email' => 'hassan.shahzad@greenfield.edu.pk',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('portal_credentials');

        $credentials = session('portal_credentials');
        $this->assertEquals('hassan.shahzad@greenfield.edu.pk', $credentials['email']);
        $this->assertNotEmpty($credentials['temp_password']);

        // User should exist in database with student role
        $createdUser = User::where('email', 'hassan.shahzad@greenfield.edu.pk')->first();
        $this->assertNotNull($createdUser);
        $this->assertEquals($this->school->id, $createdUser->school_id);
        $this->assertTrue($createdUser->hasRole('student'));
        $this->assertEquals('active', $createdUser->status);

        // Password must NOT be stored in plaintext
        $this->assertTrue(Hash::check($credentials['temp_password'], $createdUser->password));

        // Student must be linked to User
        $this->student->refresh();
        $this->assertEquals($createdUser->id, $this->student->user_id);
    }

    public function test_toggle_portal_access_status(): void
    {
        // First create portal user
        $user = User::create([
            'name'              => $this->student->first_name . ' ' . $this->student->last_name,
            'email'             => 'portal-student@greenfield.edu.pk',
            'password'          => bcrypt('secret123'),
            'school_id'         => $this->school->id,
            'email_verified_at' => now(),
            'status'            => 'active',
        ]);
        $user->assignRole('student');
        $this->student->update(['user_id' => $user->id]);

        // Toggle to inactive
        $res = $this->actingAs($this->adminUser)
            ->patch(route('school.students.portal-access.status', $this->student->id));

        $res->assertRedirect();
        $user->refresh();
        $this->assertEquals('inactive', $user->status);

        // Toggle back to active
        $this->actingAs($this->adminUser)
            ->patch(route('school.students.portal-access.status', $this->student->id));

        $user->refresh();
        $this->assertEquals('active', $user->status);
    }

    public function test_reset_portal_password_generates_new_hash_and_flashes_temporary_password(): void
    {
        $oldHash = bcrypt('oldpassword');
        $user = User::create([
            'name'              => 'Student User',
            'email'             => 'reset-test@greenfield.edu.pk',
            'password'          => $oldHash,
            'school_id'         => $this->school->id,
            'email_verified_at' => now(),
            'status'            => 'active',
        ]);
        $user->assignRole('student');
        $this->student->update(['user_id' => $user->id]);

        $response = $this->actingAs($this->adminUser)
            ->post(route('school.students.portal-access.reset-password', $this->student->id));

        $response->assertRedirect();
        $response->assertSessionHas('portal_credentials');

        $credentials = session('portal_credentials');
        $this->assertEquals('reset-test@greenfield.edu.pk', $credentials['email']);
        $this->assertNotEmpty($credentials['temp_password']);

        $user->refresh();
        $this->assertNotEquals($oldHash, $user->password);
        $this->assertTrue(Hash::check($credentials['temp_password'], $user->password));
    }

    public function test_fee_payments_hub_defaults_to_challans_and_displays_unpaid_vouchers(): void
    {
        // Create an unpaid challan (e.g. newly issued admission voucher)
        $this->createChallan([
            'challan_no' => 'CHL-2026-UNPAID',
            'status'     => 'unpaid',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->get(route('school.fees.payments.index', ['tab' => 'challans', 'status' => 'unpaid']));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Fees/Payments')
            ->where('activeTab', 'challans')
            ->has('challans.data', 1)
            ->where('challans.data.0.challan_no', 'CHL-2026-UNPAID')
        );
    }

    public function test_fee_collect_disambiguates_duplicate_matching_students(): void
    {
        // Create a second student with the same first name
        Student::create([
            'school_id'        => $this->school->id,
            'class_id'         => $this->class->id,
            'section_id'       => $this->section->id,
            'admission_no'     => 'ADM-2026-0002',
            'first_name'       => 'Hassan',
            'last_name'        => 'Ali',
            'gender'           => 'male',
            'dob'              => '2015-06-20',
            'admission_date'   => '2026-09-01',
            'status'           => 'active',
            'academic_year'    => '2026-2027',
            'student_category' => 'regular',
        ]);

        // Search for "Hassan"
        $response = $this->actingAs($this->adminUser)
            ->get(route('school.fees.payments.create', ['query' => 'Hassan']));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Fees/Collect')
            ->where('student', null)
            ->has('studentCandidates', 2)
        );
    }

    public function test_fee_outstanding_returns_individual_challans_with_billing_period_and_overdue_flag(): void
    {
        $this->createChallan([
            'challan_no' => 'CHL-2026-OVERDUE',
            'due_date'   => '2026-08-10', // past due
            'status'     => 'unpaid',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->get(route('school.fees.outstanding'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Fees/Outstanding')
            ->has('outstanding', 1)
            ->where('outstanding.0.challan_no', 'CHL-2026-OVERDUE')
            ->where('outstanding.0.is_overdue', true)
            ->where('outstanding.0.status', 'overdue')
            ->where('outstanding.0.billing_period_label', 'Sep 2026')
        );
    }

    public function test_fee_receipt_displays_academic_year_billing_period_and_reconciliation(): void
    {
        $challan = $this->createChallan([
            'challan_no'      => 'CHL-2026-REC-01',
            'gross_amount'    => '3000.00',
            'discount_amount' => '500.00',
            'total_payable'   => '2500.00',
            'paid_amount'     => '1000.00',
            'status'          => 'partial',
        ]);

        $payment = FeePayment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student->id,
            'fee_challan_id'   => $challan->id,
            'receipt_no'       => 'RCP-2026-00001',
            'amount_due'       => '2500.00',
            'amount_paid'      => '1000.00',
            'discount'         => '500.00',
            'fine'             => '0.00',
            'balance_snapshot' => '1500.00',
            'payment_date'     => '2026-09-05',
            'month_year'       => '2026-09',
            'method'           => 'cash',
            'status'           => 'partial',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->get(route('school.fees.payments.show', $payment->id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Fees/Receipt')
            ->where('payment.receipt_no', 'RCP-2026-00001')
            ->where('payment.billing_period', 'Sep 2026')
            ->where('payment.academic_year', '2026-2027')
            ->where('payment.remaining_balance', '1500.00')
        );
    }
}
