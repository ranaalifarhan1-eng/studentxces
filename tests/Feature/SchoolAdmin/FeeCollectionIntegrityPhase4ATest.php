<?php

namespace Tests\Feature\SchoolAdmin;

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
use App\Models\User;
use App\Services\StudentFinancialLedgerService;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FeeCollectionIntegrityPhase4ATest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected User $adminUser;
    protected AcademicYear $academicYear;
    protected SchoolClass $class;
    protected Section $section;
    protected Student $student;
    protected FeeCategory $tuitionCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $pView = Permission::firstOrCreate(['name' => 'fees.view', 'guard_name' => 'web']);
        $pCollect = Permission::firstOrCreate(['name' => 'fees.collect', 'guard_name' => 'web']);
        $pAdjust = Permission::firstOrCreate(['name' => 'fees.adjustment', 'guard_name' => 'web']);
        $adminRole = Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions([$pView, $pCollect, $pAdjust]);

        $this->school = School::create([
            'name'      => 'Phase 4A Safety Academy',
            'slug'      => 'phase-4a-safety',
            'is_active' => true,
            'status'    => 'active',
        ]);

        $package = Package::create([
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
        PackageModule::create(['package_id' => $package->id, 'module_slug' => 'fees']);

        SchoolSubscription::create([
            'school_id'   => $this->school->id,
            'package_id'  => $package->id,
            'status'      => 'active',
            'start_date'  => Carbon::now()->subDays(5),
            'end_date'    => Carbon::now()->addDays(25),
        ]);

        $this->adminUser = User::factory()->create([
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
            'name'         => 'Grade 9',
            'numeric_name' => 9,
        ]);

        $this->section = Section::create([
            'school_id' => $this->school->id,
            'class_id'  => $this->class->id,
            'name'      => 'Alpha',
        ]);

        $this->student = Student::create([
            'school_id'      => $this->school->id,
            'class_id'       => $this->class->id,
            'section_id'     => $this->section->id,
            'first_name'     => 'Hamza',
            'last_name'      => 'Ahmed',
            'admission_no'   => 'SEC-2026-042',
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
    }

    protected function actingAsAdmin(): self
    {
        return $this->actingAs($this->adminUser)
            ->withSession(['current_school_id' => $this->school->id]);
    }

    protected function createChallan(Student $student, string $periodKey, float $amount, string $status = 'unpaid', ?string $dueDate = null): FeeChallan
    {
        $year = (int) date('Y');
        $randomSeq = str_pad((string) mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
        $challanNo = "CHL-{$year}-{$randomSeq}";

        $challan = FeeChallan::create([
            'school_id'          => $student->school_id,
            'student_id'         => $student->id,
            'class_id'           => $student->class_id,
            'section_id'         => $student->section_id,
            'academic_year_id'   => $this->academicYear->id,
            'challan_no'         => $challanNo,
            'billing_period_key' => $periodKey,
            'student_name'       => trim("{$student->first_name} {$student->last_name}"),
            'admission_no'       => $student->admission_no,
            'class_name'         => $this->class->name,
            'section_name'       => $this->section->name,
            'academic_year_name' => $this->academicYear->name,
            'issue_date'         => '2026-09-01',
            'due_date'           => $dueDate ?? '2026-09-15',
            'gross_amount'       => number_format($amount, 2, '.', ''),
            'discount_amount'    => '0.00',
            'fine_amount'        => '0.00',
            'adjustment_amount'  => '0.00',
            'total_payable'      => number_format($amount, 2, '.', ''),
            'paid_amount'        => '0.00',
            'status'             => $status,
        ]);

        FeeChallanItem::create([
            'school_id'         => $student->school_id,
            'student_id'        => $student->id,
            'fee_challan_id'    => $challan->id,
            'charge_period_key' => $periodKey,
            'fee_head_name'     => 'Tuition Fee',
            'gross_amount'      => number_format($amount, 2, '.', ''),
            'discount_amount'   => '0.00',
            'net_amount'        => number_format($amount, 2, '.', ''),
        ]);

        return $challan;
    }

    public function test_missing_fee_challan_id_is_strictly_rejected_when_student_has_payable_challan(): void
    {
        $challan = $this->createChallan($this->student, '2026-09', 10000.00, 'unpaid');

        $initialPaymentCount = FeePayment::count();
        $initialLedgerCount = StudentFinancialLedgerService::ledgerEntries($this->student)->count();
        $initialReceiptSeq = (int) (DB::table('school_document_sequences')
            ->where('school_id', $this->school->id)
            ->where('document_type', 'fee_receipt')
            ->value('current_number') ?? 0);

        // 1. Omit fee_challan_id entirely
        $response1 = $this->actingAsAdmin()->post('/school/fees/payments', [
            'student_id'      => $this->student->id,
            'amount_paid'     => 10000.00,
            'payment_date'    => '2026-09-05',
            'method'          => 'cash',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response1->assertSessionHasErrors(['fee_challan_id']);
        $this->assertEquals($initialPaymentCount, FeePayment::count(), 'Zero FeePayment rows must be created on missing challan.');

        $challan->refresh();
        $this->assertEquals('unpaid', $challan->status);
        $this->assertEquals('0.00', $challan->paid_amount);

        // Verify ledger and receipt sequence delta is zero
        $this->assertEquals($initialLedgerCount, StudentFinancialLedgerService::ledgerEntries($this->student)->count(), 'Ledger delta must be zero.');
        $currentReceiptSeq1 = (int) (DB::table('school_document_sequences')
            ->where('school_id', $this->school->id)
            ->where('document_type', 'fee_receipt')
            ->value('current_number') ?? 0);
        $this->assertEquals($initialReceiptSeq, $currentReceiptSeq1, 'Receipt sequence delta must be zero.');

        // 2. Submit empty string fee_challan_id
        $response2 = $this->actingAsAdmin()->post('/school/fees/payments', [
            'student_id'      => $this->student->id,
            'fee_challan_id'  => '',
            'amount_paid'     => 5000.00,
            'payment_date'    => '2026-09-05',
            'method'          => 'cash',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response2->assertSessionHasErrors(['fee_challan_id']);
        $this->assertEquals($initialPaymentCount, FeePayment::count(), 'Zero FeePayment rows must be created on blank challan ID.');
        $challan->refresh();
        $this->assertEquals('0.00', $challan->paid_amount);
        $this->assertEquals($initialLedgerCount, StudentFinancialLedgerService::ledgerEntries($this->student)->count(), 'Ledger delta must be zero.');
        $currentReceiptSeq2 = (int) (DB::table('school_document_sequences')
            ->where('school_id', $this->school->id)
            ->where('document_type', 'fee_receipt')
            ->value('current_number') ?? 0);
        $this->assertEquals($initialReceiptSeq, $currentReceiptSeq2, 'Receipt sequence delta must be zero.');
    }

    public function test_wrong_student_challan_mismatch_is_rejected_with_zero_financial_writes(): void
    {
        $studentOther = Student::create([
            'school_id'      => $this->school->id,
            'class_id'       => $this->class->id,
            'section_id'     => $this->section->id,
            'first_name'     => 'Zain',
            'last_name'      => 'Malik',
            'admission_no'   => 'SEC-2026-099',
            'gender'         => 'male',
            'admission_date' => '2026-09-01',
            'status'         => 'active',
        ]);

        $challanOther = $this->createChallan($studentOther, '2026-09', 8000.00, 'unpaid');
        $initialPaymentCount = FeePayment::count();

        $response = $this->actingAsAdmin()->post('/school/fees/payments', [
            'student_id'      => $this->student->id, // Student A
            'fee_challan_id'  => $challanOther->id,  // Student B's challan
            'amount_paid'     => 8000.00,
            'payment_date'    => '2026-09-05',
            'method'          => 'cash',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertSessionHasErrors(['fee_challan_id']);
        $this->assertEquals($initialPaymentCount, FeePayment::count());
        $challanOther->refresh();
        $this->assertEquals('0.00', $challanOther->paid_amount);
        $this->assertEquals('unpaid', $challanOther->status);
    }

    public function test_cross_school_challan_is_rejected_with_zero_financial_writes(): void
    {
        $otherSchool = School::create([
            'name'      => 'Other External Academy',
            'slug'      => 'other-external',
            'is_active' => true,
            'status'    => 'active',
        ]);

        $otherClass = SchoolClass::create([
            'school_id'    => $otherSchool->id,
            'name'         => 'Grade 10',
            'numeric_name' => 10,
        ]);

        $otherStudent = Student::create([
            'school_id'      => $otherSchool->id,
            'class_id'       => $otherClass->id,
            'first_name'     => 'Farhan',
            'last_name'      => 'Ali',
            'admission_no'   => 'EXT-001',
            'gender'         => 'male',
            'admission_date' => '2026-09-01',
            'status'         => 'active',
        ]);

        $year = (int) date('Y');
        $foreignChallan = FeeChallan::create([
            'school_id'          => $otherSchool->id,
            'student_id'         => $otherStudent->id,
            'class_id'           => $otherClass->id,
            'academic_year_id'   => $this->academicYear->id,
            'challan_no'         => "CHL-{$year}-99988",
            'billing_period_key' => '2026-09',
            'student_name'       => trim("{$otherStudent->first_name} {$otherStudent->last_name}"),
            'admission_no'       => $otherStudent->admission_no,
            'class_name'         => $otherClass->name,
            'academic_year_name' => $this->academicYear->name,
            'issue_date'         => '2026-09-01',
            'due_date'           => '2026-09-15',
            'gross_amount'       => '5000.00',
            'discount_amount'    => '0.00',
            'fine_amount'        => '0.00',
            'total_payable'      => '5000.00',
            'paid_amount'        => '0.00',
            'status'             => 'unpaid',
        ]);

        $initialPaymentCount = FeePayment::count();

        $response = $this->actingAsAdmin()->post('/school/fees/payments', [
            'student_id'      => $this->student->id,
            'fee_challan_id'  => $foreignChallan->id,
            'amount_paid'     => 5000.00,
            'payment_date'    => '2026-09-05',
            'method'          => 'cash',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertSessionHasErrors(['fee_challan_id']);
        $this->assertEquals($initialPaymentCount, FeePayment::count());
        $foreignChallan->refresh();
        $this->assertEquals('0.00', $foreignChallan->paid_amount);
    }

    public function test_void_challan_is_rejected_with_zero_financial_writes(): void
    {
        $voidChallan = $this->createChallan($this->student, '2026-09', 6000.00, 'void');
        $initialPaymentCount = FeePayment::count();

        $response = $this->actingAsAdmin()->post('/school/fees/payments', [
            'student_id'      => $this->student->id,
            'fee_challan_id'  => $voidChallan->id,
            'amount_paid'     => 6000.00,
            'payment_date'    => '2026-09-05',
            'method'          => 'cash',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertSessionHasErrors(['fee_challan_id']);
        $this->assertEquals($initialPaymentCount, FeePayment::count());
        $voidChallan->refresh();
        $this->assertEquals('void', $voidChallan->status);
        $this->assertEquals('0.00', $voidChallan->paid_amount);
    }

    public function test_partial_payment_and_subsequent_settlement(): void
    {
        // Obligation: 10,000
        $challan = $this->createChallan($this->student, '2026-09', 10000.00, 'unpaid');

        // Initial ledger check
        $initialLedger = StudentFinancialLedgerService::ledgerEntries($this->student);
        $this->assertEquals(1, $initialLedger->count());
        $this->assertEquals(1000000, $initialLedger->last()->running_balance_cents);

        // Payment 1: 4,000
        $key1 = (string) Str::uuid();
        $res1 = $this->actingAsAdmin()->post('/school/fees/payments', [
            'student_id'      => $this->student->id,
            'fee_challan_id'  => $challan->id,
            'amount_paid'     => 4000.00,
            'payment_date'    => '2026-09-05',
            'method'          => 'cash',
            'idempotency_key' => $key1,
        ]);

        $res1->assertSessionHasNoErrors();
        $challan->refresh();
        $this->assertEquals('partial', $challan->status);
        $this->assertEquals('4000.00', $challan->paid_amount);
        $this->assertEquals(600000, Money::toCents($challan->total_payable) - Money::toCents($challan->paid_amount));

        $payment1 = FeePayment::where('idempotency_key', $key1)->first();
        $this->assertNotNull($payment1);
        $this->assertEquals($challan->id, $payment1->fee_challan_id);
        $this->assertEquals('4000.00', $payment1->amount_paid);
        $this->assertEquals('6000.00', $payment1->balance_snapshot);

        // Mid ledger check: exactly 2 entries, running balance 6,000
        $midLedger = StudentFinancialLedgerService::ledgerEntries($this->student);
        $this->assertEquals(2, $midLedger->count());
        $this->assertEquals(600000, $midLedger->last()->running_balance_cents);

        // Payment 2: 6,000 (Remaining balance)
        $key2 = (string) Str::uuid();
        $res2 = $this->actingAsAdmin()->post('/school/fees/payments', [
            'student_id'      => $this->student->id,
            'fee_challan_id'  => $challan->id,
            'amount_paid'     => 6000.00,
            'payment_date'    => '2026-09-10',
            'method'          => 'bank_transfer',
            'idempotency_key' => $key2,
        ]);

        $res2->assertSessionHasNoErrors();
        $challan->refresh();
        $this->assertEquals('paid', $challan->status);
        $this->assertEquals('10000.00', $challan->paid_amount);
        $this->assertEquals(0, Money::toCents($challan->total_payable) - Money::toCents($challan->paid_amount));

        $payment2 = FeePayment::where('idempotency_key', $key2)->first();
        $this->assertNotNull($payment2);
        $this->assertEquals($challan->id, $payment2->fee_challan_id);
        $this->assertEquals('6000.00', $payment2->amount_paid);
        $this->assertEquals('0.00', $payment2->balance_snapshot);

        // Final ledger check: exactly 3 entries, running balance 0
        $finalLedger = StudentFinancialLedgerService::ledgerEntries($this->student);
        $this->assertEquals(3, $finalLedger->count());
        $this->assertEquals(0, $finalLedger->last()->running_balance_cents);

        $this->assertEquals(2, FeePayment::where('fee_challan_id', $challan->id)->count());
    }

    public function test_duplicate_retry_with_same_idempotency_key_does_not_create_duplicate_payment(): void
    {
        $challan = $this->createChallan($this->student, '2026-09', 10000.00, 'unpaid');
        $idempotencyKey = (string) Str::uuid();

        // 1st submission (Payment #1: 4,000)
        $res1 = $this->actingAsAdmin()->post('/school/fees/payments', [
            'student_id'      => $this->student->id,
            'fee_challan_id'  => $challan->id,
            'amount_paid'     => 4000.00,
            'payment_date'    => '2026-09-05',
            'method'          => 'cash',
            'idempotency_key' => $idempotencyKey,
        ]);
        $res1->assertSessionHasNoErrors();
        $this->assertEquals(1, FeePayment::where('idempotency_key', $idempotencyKey)->count());

        $firstPayment = FeePayment::where('idempotency_key', $idempotencyKey)->first();
        $challan->refresh();
        $this->assertEquals('4000.00', $challan->paid_amount);

        $ledgerCountBeforeReplay = StudentFinancialLedgerService::ledgerEntries($this->student)->count();

        // 2nd identical retry with same idempotency key
        $res2 = $this->actingAsAdmin()->post('/school/fees/payments', [
            'student_id'      => $this->student->id,
            'fee_challan_id'  => $challan->id,
            'amount_paid'     => 4000.00,
            'payment_date'    => '2026-09-05',
            'method'          => 'cash',
            'idempotency_key' => $idempotencyKey,
        ]);

        $res2->assertRedirect(route('school.fees.payments.show', $firstPayment->id));
        $res2->assertSessionHas('info');
        $this->assertEquals(1, FeePayment::where('idempotency_key', $idempotencyKey)->count(), 'Idempotency must prevent second payment creation.');

        $challan->refresh();
        $this->assertEquals('4000.00', $challan->paid_amount, 'Challan paid_amount must be unchanged after replay.');

        $ledgerCountAfterReplay = StudentFinancialLedgerService::ledgerEntries($this->student)->count();
        $this->assertEquals($ledgerCountBeforeReplay, $ledgerCountAfterReplay, 'Ledger entries must be unchanged after idempotency replay.');

        // 3rd submission with a NEW UUID for legitimate second partial payment (6,000)
        $newKey = (string) Str::uuid();
        $res3 = $this->actingAsAdmin()->post('/school/fees/payments', [
            'student_id'      => $this->student->id,
            'fee_challan_id'  => $challan->id,
            'amount_paid'     => 6000.00,
            'payment_date'    => '2026-09-10',
            'method'          => 'cash',
            'idempotency_key' => $newKey,
        ]);

        $res3->assertSessionHasNoErrors();
        $this->assertEquals(2, FeePayment::where('fee_challan_id', $challan->id)->count(), 'New UUID must succeed in creating legitimate second payment.');
        $challan->refresh();
        $this->assertEquals('paid', $challan->status);
        $this->assertEquals('10000.00', $challan->paid_amount);
    }

    public function test_multiple_challans_presented_in_collect_page_without_automatic_silent_selection(): void
    {
        // Synthetic student with September and October unpaid challans
        $challanSep = $this->createChallan($this->student, '2026-09', 7000.00, 'unpaid', '2026-09-15');
        $challanOct = $this->createChallan($this->student, '2026-10', 7000.00, 'unpaid', '2026-10-15');

        $response = $this->actingAsAdmin()->get("/school/fees/payments/collect?student_id={$this->student->id}");
        $response->assertStatus(200);

        $response->assertInertia(fn ($page) => $page
            ->component('SchoolAdmin/Fees/Collect')
            ->where('student.id', $this->student->id)
            ->has('activeChallans', 2)
            ->where('activeChallans.0.id', $challanOct->id)
            ->where('activeChallans.1.id', $challanSep->id)
        );
    }

    public function test_receipt_show_endpoint_returns_isolated_print_data(): void
    {
        $challan = $this->createChallan($this->student, '2026-09', 5000.00, 'unpaid');
        $key = (string) Str::uuid();

        $this->actingAsAdmin()->post('/school/fees/payments', [
            'student_id'      => $this->student->id,
            'fee_challan_id'  => $challan->id,
            'amount_paid'     => 5000.00,
            'payment_date'    => '2026-09-05',
            'method'          => 'cash',
            'idempotency_key' => $key,
        ]);

        $payment = FeePayment::where('idempotency_key', $key)->first();
        $this->assertNotNull($payment);

        $response = $this->actingAsAdmin()->get("/school/fees/payments/{$payment->id}");
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('SchoolAdmin/Fees/Receipt')
            ->where('payment.id', $payment->id)
            ->where('payment.receipt_no', $payment->receipt_no)
            ->where('payment.amount_paid', '5000.00')
            ->where('payment.balance_snapshot', '0.00')
            ->has('payment.student')
            ->has('payment.fee_challan')
        );
    }
}
