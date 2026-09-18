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
use App\Models\StudentFeeDiscount;
use App\Models\User;
use App\Services\DocumentSequenceService;
use App\Services\FeeBillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FeeCollectionWorkflowTest extends TestCase
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
    protected FeeCategory $examCategory;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'fees.view', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'fees.collect', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'fees.structure', 'guard_name' => 'web']);

        $role = Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);
        $role->syncPermissions(['fees.view', 'fees.collect', 'fees.structure']);

        Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'parent', 'guard_name' => 'web']);

        $this->school = School::create([
            'name'   => 'Lahore Cambridge School Test',
            'slug'   => 'lcs-test-' . uniqid(),
            'email'  => 'info@lcs-' . uniqid() . '.edu.pk',
            'status' => 'active',
        ]);

        $this->package = Package::create([
            'name'          => 'Test Plan',
            'slug'          => 'test-plan-' . uniqid(),
            'price_monthly' => 20,
            'price_yearly'  => 200,
            'max_students'  => 100,
            'max_staff'     => 20,
            'storage_gb'    => 10,
            'is_active'     => true,
            'is_internal'   => false,
        ]);
        PackageModule::create(['package_id' => $this->package->id, 'module_slug' => 'fees']);

        SchoolSubscription::create([
            'school_id'   => $this->school->id,
            'package_id'  => $this->package->id,
            'status'      => 'active',
            'start_date'  => Carbon::now()->subDays(5),
            'end_date'    => Carbon::now()->addDays(25),
        ]);

        $this->adminUser = User::create([
            'name'              => 'Admin Test',
            'email'             => 'admin-' . uniqid() . '@lcs.edu.pk',
            'password'          => bcrypt('password'),
            'school_id'         => $this->school->id,
            'email_verified_at' => now(),
        ]);
        $this->adminUser->assignRole('school-admin');

        $this->academicYear = AcademicYear::create([
            'school_id'  => $this->school->id,
            'name'       => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date'   => '2027-08-31',
            'is_current' => true,
        ]);

        $this->class = SchoolClass::create([
            'school_id'    => $this->school->id,
            'name'         => 'Grade 10',
            'numeric_name' => 10,
        ]);

        $this->section = Section::create([
            'school_id' => $this->school->id,
            'class_id'  => $this->class->id,
            'name'      => 'A',
        ]);

        $this->student = Student::create([
            'school_id'      => $this->school->id,
            'user_id'        => null,
            'class_id'       => $this->class->id,
            'section_id'     => $this->section->id,
            'first_name'     => 'Ali',
            'last_name'      => 'Khan',
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

        $this->examCategory = FeeCategory::create([
            'school_id' => $this->school->id,
            'name'      => 'Exam Fee',
            'type'      => 'exam',
            'is_active' => true,
        ]);
    }

    protected function actingAsAdmin(): self
    {
        return $this->actingAs($this->adminUser)
            ->withSession(['current_school_id' => $this->school->id]);
    }

    public function test_collect_route_does_not_404_with_valid_student_id(): void
    {
        $response = $this->actingAsAdmin()->get('/school/fees/payments/collect?student_id=' . $this->student->id);
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('SchoolAdmin/Fees/Collect')
            ->has('student')
            ->where('student.id', $this->student->id)
            ->where('student.admission_no', 'LCS-2026-001')
        );
    }

    public function test_collect_search_by_neutral_query(): void
    {
        $resAdm = $this->actingAsAdmin()->get('/school/fees/payments/collect?query=LCS-2026-001');
        $resAdm->assertStatus(200);
        $resAdm->assertInertia(fn ($page) => $page->where('student.id', $this->student->id));

        $resName = $this->actingAsAdmin()->get('/school/fees/payments/collect?query=Ali');
        $resName->assertStatus(200);
        $resName->assertInertia(fn ($page) => $page->where('student.id', $this->student->id));

        $resId = $this->actingAsAdmin()->get('/school/fees/payments/collect?query=' . $this->student->id);
        $resId->assertStatus(200);
        $resId->assertInertia(fn ($page) => $page->where('student.id', $this->student->id));
    }

    public function test_collect_search_strictly_scoped_to_active_school(): void
    {
        $otherSchool = School::create([
            'name'   => 'Other School',
            'slug'   => 'other-school-' . uniqid(),
            'email'  => 'info@other-' . uniqid() . '.com',
            'status' => 'active',
        ]);

        $otherStudent = Student::create([
            'school_id'      => $otherSchool->id,
            'class_id'       => $this->class->id,
            'first_name'     => 'Foreign',
            'last_name'      => 'Student',
            'admission_no'   => 'FOR-999',
            'gender'         => 'female',
            'admission_date' => '2026-09-01',
            'status'         => 'active',
        ]);

        $res = $this->actingAsAdmin()->get('/school/fees/payments/collect?query=FOR-999');
        $res->assertStatus(200);
        $res->assertInertia(fn ($page) => $page->where('student', null));

        $resOther = $this->actingAsAdmin()->get('/school/fees/payments/collect?student_id=' . $otherStudent->id);
        $resOther->assertStatus(200);
        $resOther->assertInertia(fn ($page) => $page->where('student', null));
    }

    public function test_quarterly_billing_window_derived_from_academic_year_start_date(): void
    {
        FeeStructure::create([
            'school_id'       => $this->school->id,
            'fee_category_id' => $this->examCategory->id,
            'class_id'        => $this->class->id,
            'academic_year'   => '2026-2027',
            'amount'          => 1500.00,
            'frequency'       => 'quarterly',
            'is_active'       => true,
        ]);

        // Start month of Q1 is September 2026
        $sepMonth = Carbon::create(2026, 9, 1);
        $challanSep = FeeBillingService::createChallan($this->student, $this->academicYear, $sepMonth);
        $this->assertNotNull($challanSep);
        $this->assertCount(1, $challanSep->items);
        $item = $challanSep->items->first();
        $this->assertEquals("AY{$this->academicYear->id}-Q1", $item->charge_period_key);

        // October 2026 (Month 2 of Q1): not a quarter start -> quarterly fee not billed again
        $octMonth = Carbon::create(2026, 10, 1);
        $challanOct = FeeBillingService::createChallan($this->student, $this->academicYear, $octMonth);
        $this->assertNull($challanOct, 'Quarterly fee must not be re-billed within the same quarter');

        // Start month of Q2 is December 2026
        $decMonth = Carbon::create(2026, 12, 1);
        $challanDec = FeeBillingService::createChallan($this->student, $this->academicYear, $decMonth);
        $this->assertNotNull($challanDec);
        $this->assertEquals("AY{$this->academicYear->id}-Q2", $challanDec->items->first()->charge_period_key);
    }

    public function test_annual_fee_billed_only_once_per_academic_year(): void
    {
        FeeStructure::create([
            'school_id'       => $this->school->id,
            'fee_category_id' => $this->tuitionCategory->id,
            'class_id'        => $this->class->id,
            'academic_year'   => '2026-2027',
            'amount'          => 5000.00,
            'frequency'       => 'annual',
            'is_active'       => true,
        ]);

        $sepMonth = Carbon::create(2026, 9, 1);
        $challanSep = FeeBillingService::createChallan($this->student, $this->academicYear, $sepMonth);
        $this->assertNotNull($challanSep);
        $this->assertEquals("AY{$this->academicYear->id}-ANNUAL", $challanSep->items->first()->charge_period_key);

        $octMonth = Carbon::create(2026, 10, 1);
        $challanOct = FeeBillingService::createChallan($this->student, $this->academicYear, $octMonth);
        $this->assertNull($challanOct, 'Annual fee should not be billed again in the same academic year');
    }

    public function test_one_time_fee_billed_only_once_for_student(): void
    {
        $admissionCategory = FeeCategory::create([
            'school_id' => $this->school->id,
            'name'      => 'Admission Fee',
            'type'      => 'other',
            'is_active' => true,
        ]);

        FeeStructure::create([
            'school_id'       => $this->school->id,
            'fee_category_id' => $admissionCategory->id,
            'class_id'        => $this->class->id,
            'academic_year'   => '2026-2027',
            'amount'          => 10000.00,
            'frequency'       => 'one_time',
            'is_active'       => true,
        ]);

        $sepMonth = Carbon::create(2026, 9, 1);
        $challan1 = FeeBillingService::createChallan($this->student, $this->academicYear, $sepMonth);
        $this->assertNotNull($challan1);
        $this->assertEquals('ONETIME', $challan1->items->first()->charge_period_key);

        $nextYear = AcademicYear::create([
            'school_id'  => $this->school->id,
            'name'       => '2027-2028',
            'start_date' => '2027-09-01',
            'end_date'   => '2028-08-31',
            'is_current' => false,
        ]);

        $challanNextYear = FeeBillingService::createChallan($this->student, $nextYear, Carbon::create(2027, 9, 1));
        $this->assertNull($challanNextYear, 'One-time fee must never be re-billed');
    }

    public function test_charge_period_key_deduplication_prevents_duplicate_line_items(): void
    {
        FeeStructure::create([
            'school_id'       => $this->school->id,
            'fee_category_id' => $this->tuitionCategory->id,
            'class_id'        => $this->class->id,
            'academic_year'   => '2026-2027',
            'amount'          => 3000.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
        ]);

        $sepMonth = Carbon::create(2026, 9, 1);
        $challan = FeeBillingService::createChallan($this->student, $this->academicYear, $sepMonth);
        $this->assertNotNull($challan);

        $duplicate = FeeBillingService::createChallan($this->student, $this->academicYear, $sepMonth);
        $this->assertNull($duplicate);
    }

    public function test_bulk_challan_generation_returns_accurate_generated_and_skipped_counts(): void
    {
        $student2 = Student::create([
            'school_id'      => $this->school->id,
            'class_id'       => $this->class->id,
            'section_id'     => $this->section->id,
            'first_name'     => 'Bilal',
            'last_name'      => 'Ahmed',
            'admission_no'   => 'LCS-2026-002',
            'gender'         => 'male',
            'admission_date' => '2026-09-01',
            'status'         => 'active',
        ]);

        FeeStructure::create([
            'school_id'       => $this->school->id,
            'fee_category_id' => $this->tuitionCategory->id,
            'class_id'        => $this->class->id,
            'academic_year'   => '2026-2027',
            'amount'          => 2500.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
        ]);

        $sepMonth = Carbon::create(2026, 9, 1);
        FeeBillingService::createChallan($this->student, $this->academicYear, $sepMonth);

        $result = FeeBillingService::generateBulkChallans(
            $this->school->id,
            $this->class->id,
            null,
            $this->academicYear,
            $sepMonth
        );

        $this->assertEquals(1, $result['generated_count']);
        $this->assertEquals(1, $result['skipped_count']);
    }

    public function test_challan_and_receipt_sequence_generation(): void
    {
        $year = (int) date('Y');
        $c1 = DocumentSequenceService::nextChallanNumber($this->school->id);
        $c2 = DocumentSequenceService::nextChallanNumber($this->school->id);
        $this->assertEquals("CHL-{$year}-00001", $c1);
        $this->assertEquals("CHL-{$year}-00002", $c2);

        $r1 = DocumentSequenceService::nextReceiptNumber($this->school->id);
        $r2 = DocumentSequenceService::nextReceiptNumber($this->school->id);
        $this->assertEquals("RCP-{$year}-00001", $r1);
        $this->assertEquals("RCP-{$year}-00002", $r2);
    }

    public function test_deterministic_integer_cents_discount_distribution(): void
    {
        $c1 = FeeCategory::create(['school_id' => $this->school->id, 'name' => 'C1', 'type' => 'tuition', 'is_active' => true]);
        $c2 = FeeCategory::create(['school_id' => $this->school->id, 'name' => 'C2', 'type' => 'tuition', 'is_active' => true]);
        $c3 = FeeCategory::create(['school_id' => $this->school->id, 'name' => 'C3', 'type' => 'tuition', 'is_active' => true]);

        FeeStructure::create(['school_id' => $this->school->id, 'fee_category_id' => $c1->id, 'class_id' => $this->class->id, 'academic_year' => '2026-2027', 'amount' => 100.00, 'frequency' => 'monthly', 'is_active' => true]);
        FeeStructure::create(['school_id' => $this->school->id, 'fee_category_id' => $c2->id, 'class_id' => $this->class->id, 'academic_year' => '2026-2027', 'amount' => 100.00, 'frequency' => 'monthly', 'is_active' => true]);
        FeeStructure::create(['school_id' => $this->school->id, 'fee_category_id' => $c3->id, 'class_id' => $this->class->id, 'academic_year' => '2026-2027', 'amount' => 100.00, 'frequency' => 'monthly', 'is_active' => true]);

        StudentFeeDiscount::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student->id,
            'fee_category_id'  => null,
            'academic_year_id' => $this->academicYear->id,
            'title'            => 'Financial Aid',
            'type'             => 'fixed',
            'value'            => 10.00,
            'is_active'        => true,
        ]);

        $sepMonth = Carbon::create(2026, 9, 1);
        $challan = FeeBillingService::createChallan($this->student, $this->academicYear, $sepMonth);

        $this->assertNotNull($challan);
        $this->assertEquals('300.00', $challan->gross_amount);
        $this->assertEquals('10.00', $challan->discount_amount);
        $this->assertEquals('290.00', $challan->total_payable);

        $sumDiscounts = $challan->items->sum(fn ($i) => (int) round(((float) $i->discount_amount) * 100));
        $this->assertEquals(1000, $sumDiscounts, 'Integer cents discount sum must exactly equal 1000 cents (10.00)');
    }

    public function test_category_specific_discount_only_reduces_matching_category(): void
    {
        FeeStructure::create(['school_id' => $this->school->id, 'fee_category_id' => $this->tuitionCategory->id, 'class_id' => $this->class->id, 'academic_year' => '2026-2027', 'amount' => 2000.00, 'frequency' => 'monthly', 'is_active' => true]);
        FeeStructure::create(['school_id' => $this->school->id, 'fee_category_id' => $this->examCategory->id, 'class_id' => $this->class->id, 'academic_year' => '2026-2027', 'amount' => 1000.00, 'frequency' => 'monthly', 'is_active' => true]);

        StudentFeeDiscount::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student->id,
            'fee_category_id'  => $this->tuitionCategory->id,
            'academic_year_id' => $this->academicYear->id,
            'title'            => 'Academic Merit (Tuition)',
            'type'             => 'percentage',
            'value'            => 50.00,
            'is_active'        => true,
        ]);

        $challan = FeeBillingService::createChallan($this->student, $this->academicYear, Carbon::create(2026, 9, 1));
        $this->assertNotNull($challan);

        $tuitionItem = $challan->items->firstWhere('fee_head_name', $this->tuitionCategory->name);
        $examItem = $challan->items->firstWhere('fee_head_name', $this->examCategory->name);

        $this->assertEquals('1000.00', $tuitionItem->discount_amount);
        $this->assertEquals('1000.00', $tuitionItem->net_amount);

        $this->assertEquals('0.00', $examItem->discount_amount);
        $this->assertEquals('1000.00', $examItem->net_amount);

        $this->assertEquals('2000.00', $challan->total_payable);
    }

    public function test_previous_unpaid_challans_remain_independent_and_memo_only(): void
    {
        FeeStructure::create(['school_id' => $this->school->id, 'fee_category_id' => $this->tuitionCategory->id, 'class_id' => $this->class->id, 'academic_year' => '2026-2027', 'amount' => 2000.00, 'frequency' => 'monthly', 'is_active' => true]);

        $challanSep = FeeBillingService::createChallan($this->student, $this->academicYear, Carbon::create(2026, 9, 1));
        $this->assertEquals('0.00', $challanSep->previous_outstanding_snapshot);
        $this->assertEquals('2000.00', $challanSep->total_payable);

        $challanOct = FeeBillingService::createChallan($this->student, $this->academicYear, Carbon::create(2026, 10, 1));
        $this->assertNotNull($challanOct);
        $this->assertEquals('2000.00', $challanOct->previous_outstanding_snapshot);
        $this->assertEquals('2000.00', $challanOct->total_payable);

        $challanSep->refresh();
        $this->assertEquals('unpaid', $challanSep->status);
    }

    public function test_void_challan_releases_charge_period_key_for_regeneration(): void
    {
        FeeStructure::create(['school_id' => $this->school->id, 'fee_category_id' => $this->tuitionCategory->id, 'class_id' => $this->class->id, 'academic_year' => '2026-2027', 'amount' => 3000.00, 'frequency' => 'monthly', 'is_active' => true]);

        $sepMonth = Carbon::create(2026, 9, 1);
        $challan = FeeBillingService::createChallan($this->student, $this->academicYear, $sepMonth);
        $this->assertNotNull($challan);
        $this->assertEquals('unpaid', $challan->status);

        $res = $this->actingAsAdmin()->post("/school/fees/challans/{$challan->id}/void", [
            'reason' => 'Incorrect tuition fee structure applied by operator',
        ]);
        $res->assertSessionHas('success');

        $challan->refresh();
        $this->assertEquals('void', $challan->status);
        $this->assertEquals('Incorrect tuition fee structure applied by operator', $challan->void_reason);

        foreach ($challan->items as $item) {
            $this->assertNull($item->active_charge_key);
        }

        $regenerated = FeeBillingService::createChallan($this->student, $this->academicYear, $sepMonth);
        $this->assertNotNull($regenerated);
        $this->assertEquals('unpaid', $regenerated->status);
    }

    public function test_payment_ledger_is_append_only_and_records_partial_and_full_payments(): void
    {
        FeeStructure::create(['school_id' => $this->school->id, 'fee_category_id' => $this->tuitionCategory->id, 'class_id' => $this->class->id, 'academic_year' => '2026-2027', 'amount' => 5000.00, 'frequency' => 'monthly', 'is_active' => true]);

        $challan = FeeBillingService::createChallan($this->student, $this->academicYear, Carbon::create(2026, 9, 1));

        $res1 = $this->actingAsAdmin()->post('/school/fees/payments', [
            'student_id'      => $this->student->id,
            'challan_id'      => $challan->id,
            'amount_paid'     => 2000.00,
            'payment_date'    => '2026-09-05',
            'method'          => 'cash',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $res1->assertSessionHasNoErrors();

        $challan->refresh();
        $this->assertEquals('partial', $challan->status);
        $this->assertEquals('2000.00', $challan->paid_amount);

        $p1 = FeePayment::where('fee_challan_id', $challan->id)->latest()->first();
        $this->assertEquals('2000.00', $p1->amount_paid);
        $this->assertEquals('3000.00', $p1->balance_snapshot);

        $res2 = $this->actingAsAdmin()->post('/school/fees/payments', [
            'student_id'      => $this->student->id,
            'challan_id'      => $challan->id,
            'amount_paid'     => 3000.00,
            'payment_date'    => '2026-09-10',
            'method'          => 'bank_transfer',
            'reference'       => 'TXN-987654',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $res2->assertSessionHasNoErrors();

        $challan->refresh();
        $this->assertEquals('paid', $challan->status);
        $this->assertEquals('5000.00', $challan->paid_amount);
        $this->assertEquals(2, FeePayment::where('fee_challan_id', $challan->id)->count());
    }

    public function test_overpayment_is_strictly_rejected_with_integer_cents_guard(): void
    {
        FeeStructure::create(['school_id' => $this->school->id, 'fee_category_id' => $this->tuitionCategory->id, 'class_id' => $this->class->id, 'academic_year' => '2026-2027', 'amount' => 1000.00, 'frequency' => 'monthly', 'is_active' => true]);

        $challan = FeeBillingService::createChallan($this->student, $this->academicYear, Carbon::create(2026, 9, 1));

        $res = $this->actingAsAdmin()->post('/school/fees/payments', [
            'student_id'      => $this->student->id,
            'challan_id'      => $challan->id,
            'amount_paid'     => 1000.01,
            'payment_date'    => '2026-09-05',
            'method'          => 'cash',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $res->assertSessionHasErrors(['amount_paid']);
        $challan->refresh();
        $this->assertEquals('unpaid', $challan->status);
        $this->assertEquals('0.00', $challan->paid_amount);
    }

    public function test_spatie_fee_permissions_guard_endpoints(): void
    {
        $teacher = User::create([
            'name'              => 'Teacher Test',
            'email'             => 'teacher-' . uniqid() . '@lcs.edu.pk',
            'password'          => bcrypt('password'),
            'school_id'         => $this->school->id,
            'email_verified_at' => now(),
        ]);
        $teacher->assignRole('teacher');

        $actingTeacher = $this->actingAs($teacher)->withSession(['current_school_id' => $this->school->id]);

        $actingTeacher->get('/school/fees/payments')->assertStatus(403);
        $actingTeacher->get('/school/fees/challans')->assertStatus(403);
        $actingTeacher->get('/school/fees/outstanding')->assertStatus(403);

        $actingTeacher->get('/school/fees/payments/collect')->assertStatus(403);
        $actingTeacher->post('/school/fees/payments', [])->assertStatus(403);

        $actingTeacher->get('/school/fees/structures')->assertStatus(403);
        $actingTeacher->get('/school/fees/categories')->assertStatus(403);
        $actingTeacher->get('/school/fees/discounts')->assertStatus(403);
    }

    public function test_cross_tenant_isolation_on_challans_and_payments(): void
    {
        $school2 = School::create([
            'name'   => 'Second School',
            'slug'   => 's2-' . uniqid(),
            'email'  => 'info@s2-' . uniqid() . '.com',
            'status' => 'active',
        ]);

        SchoolSubscription::create([
            'school_id'   => $school2->id,
            'package_id'  => $this->package->id,
            'status'      => 'active',
            'start_date'  => Carbon::now()->subDays(5),
            'end_date'    => Carbon::now()->addDays(25),
        ]);

        $class2 = SchoolClass::create(['school_id' => $school2->id, 'name' => 'Class X', 'numeric_name' => 10]);
        $student2 = Student::create([
            'school_id'      => $school2->id,
            'class_id'       => $class2->id,
            'first_name'     => 'Bob',
            'last_name'      => 'Jones',
            'admission_no'   => 'S2-001',
            'gender'         => 'male',
            'admission_date' => '2026-09-01',
            'status'         => 'active',
        ]);

        $ay2 = AcademicYear::create([
            'school_id'  => $school2->id,
            'name'       => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date'   => '2027-08-31',
            'is_current' => true,
        ]);

        $cat2 = FeeCategory::create(['school_id' => $school2->id, 'name' => 'Fee', 'type' => 'tuition', 'is_active' => true]);
        FeeStructure::create(['school_id' => $school2->id, 'fee_category_id' => $cat2->id, 'class_id' => $class2->id, 'academic_year' => '2026-2027', 'amount' => 1000.00, 'frequency' => 'monthly', 'is_active' => true]);

        $challan2 = FeeBillingService::createChallan($student2, $ay2, Carbon::create(2026, 9, 1));

        $res = $this->actingAsAdmin()->get("/school/fees/challans/{$challan2->id}");
        $res->assertStatus(404);

        $resVoid = $this->actingAsAdmin()->post("/school/fees/challans/{$challan2->id}/void", ['reason' => 'Unauthorized void']);
        $resVoid->assertStatus(404);
    }

    public function test_outstanding_fees_aggregates_mixed_debts(): void
    {
        FeeStructure::create(['school_id' => $this->school->id, 'fee_category_id' => $this->tuitionCategory->id, 'class_id' => $this->class->id, 'academic_year' => '2026-2027', 'amount' => 3000.00, 'frequency' => 'monthly', 'is_active' => true]);

        $challan = FeeBillingService::createChallan($this->student, $this->academicYear, Carbon::create(2026, 9, 1));

        FeePayment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student->id,
            'fee_structure_id' => null,
            'fee_challan_id'   => null,
            'receipt_no'       => 'LEGACY-001',
            'amount_due'       => '1500.00',
            'amount_paid'      => '0.00',
            'discount'         => '0.00',
            'fine'             => '0.00',
            'balance_snapshot' => '1500.00',
            'status'           => 'pending',
            'method'           => 'cash',
        ]);

        $res = $this->actingAsAdmin()->get('/school/fees/outstanding');
        $res->assertStatus(200);
        $res->assertInertia(fn ($page) => $page
            ->component('SchoolAdmin/Fees/Outstanding')
            ->has('outstanding')
        );
    }

    public function test_voided_challan_cannot_be_paid(): void
    {
        FeeStructure::create(['school_id' => $this->school->id, 'fee_category_id' => $this->tuitionCategory->id, 'class_id' => $this->class->id, 'academic_year' => '2026-2027', 'amount' => 1000.00, 'frequency' => 'monthly', 'is_active' => true]);

        $challan = FeeBillingService::createChallan($this->student, $this->academicYear, Carbon::create(2026, 9, 1));
        $challan->voidChallan('Administrative test void', $this->adminUser->id);

        $res = $this->actingAsAdmin()->post('/school/fees/payments', [
            'student_id'      => $this->student->id,
            'challan_id'      => $challan->id,
            'amount_paid'     => 1000.00,
            'payment_date'    => '2026-09-05',
            'method'          => 'cash',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $res->assertSessionHasErrors(['challan_id']);
    }

    public function test_student_discount_crud_lifecycle(): void
    {
        $resStore = $this->actingAsAdmin()->post('/school/fees/discounts', [
            'student_id'       => $this->student->id,
            'fee_category_id'  => $this->tuitionCategory->id,
            'academic_year_id' => $this->academicYear->id,
            'title'            => 'Sibling Concession',
            'type'             => 'percentage',
            'value'            => 25.00,
            'is_active'        => true,
        ]);
        $resStore->assertSessionHas('success');

        $discount = StudentFeeDiscount::where('student_id', $this->student->id)->first();
        $this->assertNotNull($discount);
        $this->assertEquals('25.00', $discount->value);

        $resUpdate = $this->actingAsAdmin()->put("/school/fees/discounts/{$discount->id}", [
            'fee_category_id'  => $this->tuitionCategory->id,
            'academic_year_id' => $this->academicYear->id,
            'title'            => 'Sibling Concession (Updated)',
            'type'             => 'percentage',
            'value'            => 30.00,
            'is_active'        => true,
        ]);
        $resUpdate->assertSessionHas('success');
        $discount->refresh();
        $this->assertEquals('30.00', $discount->value);

        $resDelete = $this->actingAsAdmin()->delete("/school/fees/discounts/{$discount->id}");
        $resDelete->assertSessionHas('success');
        $this->assertNull(StudentFeeDiscount::find($discount->id));
    }

    public function test_payment_idempotency_prevents_duplicate_collection(): void
    {
        FeeStructure::create([
            'school_id'        => $this->school->id,
            'fee_category_id'  => $this->tuitionCategory->id,
            'class_id'         => $this->class->id,
            'academic_year'    => '2026-2027',
            'amount'           => 1000.00,
            'frequency'        => 'monthly',
            'is_active'        => true,
        ]);

        $challan = FeeBillingService::createChallan($this->student, $this->academicYear, Carbon::create(2026, 9, 1));
        $this->assertNotNull($challan);
        $this->assertEquals('1000.00', $challan->total_payable);

        $idempotencyKey = (string) Str::uuid();

        // First payment
        $response1 = $this->actingAsAdmin()->post('/school/fees/payments', [
            'student_id'      => $this->student->id,
            'fee_challan_id'  => $challan->id,
            'amount_paid'     => 1000.00,
            'payment_date'    => '2026-09-05',
            'method'          => 'cash',
            'idempotency_key' => $idempotencyKey,
        ]);

        $response1->assertSessionHas('success');
        $payment = FeePayment::where('idempotency_key', $idempotencyKey)->first();
        $this->assertNotNull($payment);
        $this->assertEquals('1000.00', $payment->amount_paid);

        $challan->refresh();
        $this->assertEquals('1000.00', $challan->paid_amount);
        $this->assertEquals('paid', $challan->status);
        $this->assertEquals('0.00', $challan->balance);

        // Second payment with identical idempotency key (duplicate submission)
        $response2 = $this->actingAsAdmin()->post('/school/fees/payments', [
            'student_id'      => $this->student->id,
            'fee_challan_id'  => $challan->id,
            'amount_paid'     => 1000.00,
            'payment_date'    => '2026-09-05',
            'method'          => 'cash',
            'idempotency_key' => $idempotencyKey,
        ]);

        // Should redirect to existing receipt without error or double payment
        $response2->assertRedirect(route('school.fees.payments.show', $payment->id));
        $response2->assertSessionHas('info');

        // Verify only 1 payment was created and challan was not double-credited
        $this->assertEquals(1, FeePayment::where('student_id', $this->student->id)->count());
        $challan->refresh();
        $this->assertEquals('1000.00', $challan->paid_amount);
        $this->assertEquals('0.00', $challan->balance);
    }

    public function test_different_idempotency_keys_permit_partial_payments(): void
    {
        FeeStructure::create([
            'school_id'        => $this->school->id,
            'fee_category_id'  => $this->tuitionCategory->id,
            'class_id'         => $this->class->id,
            'academic_year'    => '2026-2027',
            'amount'           => 1000.00,
            'frequency'        => 'monthly',
            'is_active'        => true,
        ]);

        $challan = FeeBillingService::createChallan($this->student, $this->academicYear, Carbon::create(2026, 9, 1));
        $this->assertNotNull($challan);

        $key1 = (string) Str::uuid();
        $key2 = (string) Str::uuid();

        // Payment 1: 400.00
        $res1 = $this->actingAsAdmin()->post('/school/fees/payments', [
            'student_id'      => $this->student->id,
            'fee_challan_id'  => $challan->id,
            'amount_paid'     => 400.00,
            'payment_date'    => '2026-09-05',
            'method'          => 'cash',
            'idempotency_key' => $key1,
        ]);
        $res1->assertSessionHas('success');

        $challan->refresh();
        $this->assertEquals('400.00', $challan->paid_amount);
        $this->assertEquals('partial', $challan->status);
        $this->assertEquals('600.00', $challan->balance);

        // Payment 2: 600.00 with distinct key
        $res2 = $this->actingAsAdmin()->post('/school/fees/payments', [
            'student_id'      => $this->student->id,
            'fee_challan_id'  => $challan->id,
            'amount_paid'     => 600.00,
            'payment_date'    => '2026-09-06',
            'method'          => 'cash',
            'idempotency_key' => $key2,
        ]);
        $res2->assertSessionHas('success');

        $challan->refresh();
        $this->assertEquals('1000.00', $challan->paid_amount);
        $this->assertEquals('paid', $challan->status);
        $this->assertEquals('0.00', $challan->balance);
        $this->assertEquals(2, FeePayment::where('student_id', $this->student->id)->count());
    }

    public function test_quarterly_billing_late_generation_and_duplicate_prevention(): void
    {
        // Academic year is Sep 2026 to Aug 2027.
        // Q1 = Sep-Nov (months 9, 10, 11).
        FeeStructure::create([
            'school_id'        => $this->school->id,
            'fee_category_id'  => $this->tuitionCategory->id,
            'class_id'         => $this->class->id,
            'academic_year'    => '2026-2027',
            'amount'           => 1000.00,
            'frequency'        => 'monthly',
            'is_active'        => true,
        ]);

        $labCategory = FeeCategory::create([
            'school_id' => $this->school->id,
            'name'      => 'Lab Charges',
            'type'      => 'other',
            'is_active' => true,
        ]);

        FeeStructure::create([
            'school_id'        => $this->school->id,
            'fee_category_id'  => $labCategory->id,
            'class_id'         => $this->class->id,
            'academic_year'    => '2026-2027',
            'amount'           => 1500.00,
            'frequency'        => 'quarterly',
            'is_active'        => true,
        ]);

        // Student was NOT billed in September.
        // First generation happens in October (month 2 of Q1).
        $octChallan = FeeBillingService::createChallan($this->student, $this->academicYear, Carbon::create(2026, 10, 15));
        $this->assertNotNull($octChallan);

        // Quarterly fee MUST be included in the late October challan
        $labItem = $octChallan->items->firstWhere('fee_head_name', 'Lab Charges');
        $this->assertNotNull($labItem, 'Quarterly fee should be billed in October if missed in September.');
        $this->assertEquals('1500.00', $labItem->net_amount);

        // Now generate November challan (month 3 of Q1)
        $novChallan = FeeBillingService::createChallan($this->student, $this->academicYear, Carbon::create(2026, 11, 15));
        $this->assertNotNull($novChallan);

        // November challan MUST include monthly tuition fee but NOT duplicate the Q1 quarterly fee
        $tuitionItem = $novChallan->items->firstWhere('fee_head_name', 'Tuition Fee');
        $this->assertNotNull($tuitionItem);
        $labItemNov = $novChallan->items->firstWhere('fee_head_name', 'Lab Charges');
        $this->assertNull($labItemNov, 'Quarterly fee should not be billed again in same quarter once billed.');
    }

    public function test_annual_fee_late_first_challan_and_no_duplication(): void
    {
        FeeStructure::create([
            'school_id'        => $this->school->id,
            'fee_category_id'  => $this->tuitionCategory->id,
            'class_id'         => $this->class->id,
            'academic_year'    => '2026-2027',
            'amount'           => 1000.00,
            'frequency'        => 'monthly',
            'is_active'        => true,
        ]);

        $annualCategory = FeeCategory::create([
            'school_id' => $this->school->id,
            'name'      => 'Annual Development Fund',
            'type'      => 'other',
            'is_active' => true,
        ]);

        FeeStructure::create([
            'school_id'        => $this->school->id,
            'fee_category_id'  => $annualCategory->id,
            'class_id'         => $this->class->id,
            'academic_year'    => '2026-2027',
            'amount'           => 5000.00,
            'frequency'        => 'annual',
            'is_active'        => true,
        ]);

        // Student was admitted or first billed in November (month 3 of academic year)
        $novChallan = FeeBillingService::createChallan($this->student, $this->academicYear, Carbon::create(2026, 11, 1));
        $this->assertNotNull($novChallan);

        $annualItem = $novChallan->items->firstWhere('fee_head_name', 'Annual Development Fund');
        $this->assertNotNull($annualItem, 'Annual fee must be charged on student first eligible challan of the year.');
        $this->assertEquals('5000.00', $annualItem->net_amount);

        // Subsequent December challan
        $decChallan = FeeBillingService::createChallan($this->student, $this->academicYear, Carbon::create(2026, 12, 1));
        $this->assertNotNull($decChallan);

        $annualItemDec = $decChallan->items->firstWhere('fee_head_name', 'Annual Development Fund');
        $this->assertNull($annualItemDec, 'Annual fee must not be billed again in the same academic year.');
        $tuitionItemDec = $decChallan->items->firstWhere('fee_head_name', 'Tuition Fee');
        $this->assertNotNull($tuitionItemDec);
    }

    public function test_document_sequence_first_row_initialization_and_increment(): void
    {
        $num1 = DocumentSequenceService::nextChallanNumber($this->school->id, 2026);
        $this->assertEquals('CHL-2026-00001', $num1);

        $num2 = DocumentSequenceService::nextChallanNumber($this->school->id, 2026);
        $this->assertEquals('CHL-2026-00002', $num2);

        $receipt1 = DocumentSequenceService::nextReceiptNumber($this->school->id, 2026);
        $this->assertEquals('RCP-2026-00001', $receipt1);
    }

    public function test_duplicate_challan_generation_returns_null_gracefully(): void
    {
        FeeStructure::create([
            'school_id'        => $this->school->id,
            'fee_category_id'  => $this->tuitionCategory->id,
            'class_id'         => $this->class->id,
            'academic_year'    => '2026-2027',
            'amount'           => 1000.00,
            'frequency'        => 'monthly',
            'is_active'        => true,
        ]);

        $ch1 = FeeBillingService::createChallan($this->student, $this->academicYear, Carbon::create(2026, 9, 1));
        $this->assertNotNull($ch1);

        // Duplicate generation for same student & period returns null gracefully without exception
        $ch2 = FeeBillingService::createChallan($this->student, $this->academicYear, Carbon::create(2026, 9, 1));
        $this->assertNull($ch2);
    }

    public function test_modern_challan_payment_without_idempotency_key_is_rejected(): void
    {
        FeeStructure::create([
            'school_id'        => $this->school->id,
            'fee_category_id'  => $this->tuitionCategory->id,
            'class_id'         => $this->class->id,
            'academic_year'    => '2026-2027',
            'amount'           => 1000.00,
            'frequency'        => 'monthly',
            'is_active'        => true,
        ]);

        $challan = FeeBillingService::createChallan($this->student, $this->academicYear, Carbon::create(2026, 9, 1));
        $this->assertNotNull($challan);

        // Attempt modern challan payment WITHOUT idempotency_key
        $res = $this->actingAsAdmin()->post('/school/fees/payments', [
            'student_id'     => $this->student->id,
            'fee_challan_id' => $challan->id,
            'amount_paid'    => 1000.00,
            'payment_date'   => '2026-09-05',
            'method'         => 'cash',
        ]);

        $res->assertSessionHasErrors(['idempotency_key']);
        $this->assertEquals(0, FeePayment::where('fee_challan_id', $challan->id)->count());

        $challan->refresh();
        $this->assertEquals('unpaid', $challan->status);
        $this->assertEquals('0.00', $challan->paid_amount);
    }

    public function test_legacy_payment_without_idempotency_key_is_accepted(): void
    {
        // Legacy payment has NO fee_challan_id
        $res = $this->actingAsAdmin()->post('/school/fees/payments', [
            'student_id'   => $this->student->id,
            'amount_paid'  => 500.00,
            'payment_date' => '2026-09-05',
            'method'       => 'cash',
        ]);

        $res->assertSessionHasNoErrors();
        $payment = FeePayment::where('student_id', $this->student->id)->latest()->first();
        $this->assertNotNull($payment);
        $this->assertNull($payment->fee_challan_id);
        $this->assertNull($payment->idempotency_key);
        $this->assertEquals('500.00', $payment->amount_paid);
    }

    public function test_concurrent_duplicate_idempotency_race_resolves_to_existing_receipt(): void
    {
        FeeStructure::create([
            'school_id'        => $this->school->id,
            'fee_category_id'  => $this->tuitionCategory->id,
            'class_id'         => $this->class->id,
            'academic_year'    => '2026-2027',
            'amount'           => 1000.00,
            'frequency'        => 'monthly',
            'is_active'        => true,
        ]);

        $challan = FeeBillingService::createChallan($this->student, $this->academicYear, Carbon::create(2026, 9, 1));
        $this->assertNotNull($challan);

        $idempotencyKey = (string) Str::uuid();

        // Simulate existing payment with this idempotency key already created in DB
        $existing = FeePayment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student->id,
            'fee_challan_id'   => $challan->id,
            'receipt_no'       => 'RCP-2026-99999',
            'amount_due'       => '1000.00',
            'amount_paid'      => '1000.00',
            'discount'         => '0.00',
            'fine'             => '0.00',
            'balance_snapshot' => '0.00',
            'payment_date'     => '2026-09-05',
            'method'           => 'cash',
            'idempotency_key'  => $idempotencyKey,
            'status'           => 'paid',
        ]);

        // Submit payment with that same key
        $res = $this->actingAsAdmin()->post('/school/fees/payments', [
            'student_id'      => $this->student->id,
            'fee_challan_id'  => $challan->id,
            'amount_paid'     => 1000.00,
            'payment_date'    => '2026-09-05',
            'method'          => 'cash',
            'idempotency_key' => $idempotencyKey,
        ]);

        // Resolves cleanly to existing receipt without HTTP 500
        $res->assertRedirect(route('school.fees.payments.show', $existing->id));
        $res->assertSessionHas('info');
        $this->assertEquals(1, FeePayment::where('idempotency_key', $idempotencyKey)->count());
    }
}
