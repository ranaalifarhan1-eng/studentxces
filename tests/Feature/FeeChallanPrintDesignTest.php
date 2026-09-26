<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\FeeCategory;
use App\Models\FeeChallan;
use App\Models\FeeChallanAdjustment;
use App\Models\FeeChallanItem;
use App\Models\FeePayment;
use App\Models\Guardian;
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
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FeeChallanPrintDesignTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected User $adminUser;
    protected AcademicYear $academicYear;
    protected SchoolClass $class;
    protected Section $section;
    protected Guardian $guardian;
    protected Student $student;
    protected FeeCategory $tuitionCategory;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed permissions
        $pCollect = Permission::firstOrCreate(['name' => 'fees.collect', 'guard_name' => 'web']);
        $pView = Permission::firstOrCreate(['name' => 'fees.view', 'guard_name' => 'web']);
        $pStructure = Permission::firstOrCreate(['name' => 'fees.structure', 'guard_name' => 'web']);
        $pAssign = Permission::firstOrCreate(['name' => 'fees.assign', 'guard_name' => 'web']);
        $pDiscount = Permission::firstOrCreate(['name' => 'fees.discount', 'guard_name' => 'web']);
        $pAdjustment = Permission::firstOrCreate(['name' => 'fees.adjustment', 'guard_name' => 'web']);

        $adminRole = Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions([$pCollect, $pView, $pStructure, $pAssign, $pDiscount, $pAdjustment]);

        $this->school = School::create([
            'name'       => 'Lahore Cambridge School',
            'slug'       => 'lcs-' . uniqid(),
            'email'      => 'info@lahorecambridge.test',
            'address'    => 'Main Boulevard, Gulberg III, Lahore',
            'phone'      => '042-35870000',
            'status'     => 'active',
        ]);

        $package = Package::create([
            'name'          => 'Pro Plan',
            'slug'          => 'pro-' . uniqid(),
            'price_monthly' => 50,
            'price_yearly'  => 500,
            'max_students'  => 1000,
            'max_staff'     => 100,
            'storage_gb'    => 50,
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
            'name'              => 'Principal LCS',
            'email'             => 'admin-' . uniqid() . '@lcs.test',
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
            'name'         => 'Class 9',
            'numeric_name' => 9,
        ]);

        $this->section = Section::create([
            'school_id' => $this->school->id,
            'class_id'  => $this->class->id,
            'name'      => 'A',
        ]);

        $this->guardian = Guardian::create([
            'school_id' => $this->school->id,
            'name'      => 'Muhammad Azam Riaz',
            'relation'  => 'father',
            'phone'     => '0300-1234567',
        ]);

        $this->student = Student::create([
            'school_id'      => $this->school->id,
            'class_id'       => $this->class->id,
            'section_id'     => $this->section->id,
            'guardian_id'    => $this->guardian->id,
            'first_name'     => 'Abdullah Azam',
            'last_name'      => 'Dar',
            'admission_no'   => 'LCS-1059',
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

    protected function createChallan(array $overrides = []): FeeChallan
    {
        $gross = $overrides['gross_amount'] ?? 5000.00;
        $discount = $overrides['discount_amount'] ?? 0.00;
        $adjustment = $overrides['adjustment_amount'] ?? 0.00;
        $paid = $overrides['paid_amount'] ?? 0.00;
        $payable = $overrides['total_payable'] ?? max(0.0, $gross - $discount - $adjustment);

        return FeeChallan::create(array_merge([
            'school_id'                     => $this->school->id,
            'student_id'                    => $this->student->id,
            'class_id'                      => $this->class->id,
            'section_id'                    => $this->section->id,
            'academic_year_id'              => $this->academicYear->id,
            'student_name'                  => $this->student->first_name . ' ' . $this->student->last_name,
            'admission_no'                  => $this->student->admission_no,
            'class_name'                    => $this->class->name,
            'section_name'                  => $this->section->name,
            'academic_year_name'            => $this->academicYear->name,
            'challan_no'                    => 'CHL-2026-' . rand(10000, 99999),
            'billing_period_key'            => '2026-09',
            'issue_date'                    => '2026-09-01',
            'due_date'                      => '2026-09-15',
            'billing_period_month'          => 9,
            'billing_period_year'           => 2026,
            'gross_amount'                  => $gross,
            'discount_amount'               => $discount,
            'discount_title'                => null,
            'fine_amount'                   => 0.00,
            'adjustment_amount'             => $adjustment,
            'total_payable'                 => $payable,
            'paid_amount'                   => $paid,
            'previous_outstanding_snapshot' => 0.00,
            'status'                        => 'unpaid',
        ], $overrides));
    }

    protected function createChallanItem(FeeChallan $challan, array $overrides = []): FeeChallanItem
    {
        return FeeChallanItem::create(array_merge([
            'school_id'         => $this->school->id,
            'fee_challan_id'    => $challan->id,
            'student_id'        => $this->student->id,
            'charge_period_key' => '2026-09',
            'fee_head_name'     => 'Monthly Tuition Fee - September 2026',
            'gross_amount'      => 4000.00,
            'discount_amount'   => 0.00,
            'net_amount'        => 4000.00,
        ], $overrides));
    }

    public function test_unpaid_tuition_challan_view_renders_expected_allied_props(): void
    {
        $challan = $this->createChallan(['gross_amount' => 4000.00]);
        $this->createChallanItem($challan);

        $response = $this->actingAs($this->adminUser)
            ->get(route('school.fees.challans.show', $challan->id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Fees/Challan')
            ->where('challan.id', $challan->id)
            ->where('challan.challan_no', $challan->challan_no)
            ->where('challan.student_name', 'Abdullah Azam Dar')
            ->where('challan.guardian_name', 'Muhammad Azam Riaz')
            ->where('challan.status', 'unpaid')
            ->where('school.name', 'Lahore Cambridge School')
            ->has('challan.items', 1)
        );
    }

    public function test_partial_payment_challan_preserves_paid_amount_and_balance(): void
    {
        $challan = $this->createChallan([
            'gross_amount'  => 3500.00,
            'total_payable' => 3500.00,
            'paid_amount'   => 500.00,
            'status'        => 'partial',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->get(route('school.fees.challans.show', $challan->id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Fees/Challan')
            ->where('challan.total_payable', '3500.00')
            ->where('challan.paid_amount', '500.00')
            ->where('challan.status', 'partial')
        );
    }

    public function test_concession_discount_challan_passes_discount_info(): void
    {
        $challan = $this->createChallan([
            'gross_amount'    => 1200.00,
            'discount_amount' => 240.00,
            'discount_title'  => 'Sibling Concession 20%',
            'total_payable'   => 960.00,
            'status'          => 'unpaid',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->get(route('school.fees.challans.show', $challan->id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Fees/Challan')
            ->where('challan.gross_amount', '1200.00')
            ->where('challan.discount_amount', '240.00')
            ->where('challan.discount_title', 'Sibling Concession 20%')
            ->where('challan.total_payable', '960.00')
        );
    }

    public function test_collection_adjustment_challan_loads_adjustments_and_settlement_classification(): void
    {
        $challan = $this->createChallan([
            'due_date'          => '2026-09-25',
            'gross_amount'      => 2000.00,
            'total_payable'     => 1500.00,
            'paid_amount'       => 1500.00,
            'adjustment_amount' => 500.00,
            'status'            => 'paid',
        ]);

        FeeChallanAdjustment::create([
            'school_id'         => $this->school->id,
            'fee_challan_id'    => $challan->id,
            'student_id'        => $this->student->id,
            'created_by'        => $this->adminUser->id,
            'adjustment_type'   => 'fixed',
            'value'             => '500.00',
            'adjustment_amount' => 500.00,
            'previous_balance'  => 500.00,
            'new_balance'       => 0.00,
            'reason'            => 'Principal Discretion',
            'notes'             => 'Approved waiver',
            'created_at'        => '2026-09-25 10:00:00',
        ]);

        FeePayment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student->id,
            'fee_challan_id'   => $challan->id,
            'receipt_no'       => 'RCP-2026-' . rand(1000, 9999),
            'amount_due'       => 1500.00,
            'amount_paid'      => 1500.00,
            'discount'         => 0.00,
            'fine'             => 0.00,
            'balance_snapshot' => 0.00,
            'payment_date'     => '2026-09-26',
            'status'           => 'paid',
            'method'           => 'cash',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->get(route('school.fees.challans.show', $challan->id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Fees/Challan')
            ->where('challan.settlement_classification', 'Paid + Adjusted')
            ->where('challan.settlement_date', '2026-09-26')
            ->has('challan.adjustments', 1)
        );
    }

    public function test_fully_paid_challan_retains_paid_status(): void
    {
        $challan = $this->createChallan([
            'due_date'      => '2026-09-15',
            'gross_amount'  => 2500.00,
            'total_payable' => 2500.00,
            'paid_amount'   => 2500.00,
            'status'        => 'paid',
        ]);

        FeePayment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student->id,
            'fee_challan_id'   => $challan->id,
            'receipt_no'       => 'RCP-2026-' . rand(1000, 9999),
            'amount_due'       => 2500.00,
            'amount_paid'      => 2500.00,
            'discount'         => 0.00,
            'fine'             => 0.00,
            'balance_snapshot' => 0.00,
            'payment_date'     => '2026-09-26',
            'status'           => 'paid',
            'method'           => 'cash',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->get(route('school.fees.challans.show', $challan->id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Fees/Challan')
            ->where('challan.status', 'paid')
            ->where('challan.settlement_classification', 'Paid')
            ->where('challan.settlement_date', '2026-09-26')
        );
    }

    public function test_waived_fully_adjusted_challan_has_waived_classification(): void
    {
        $challan = $this->createChallan([
            'due_date'          => '2026-09-15',
            'gross_amount'      => 3000.00,
            'total_payable'     => 0.00,
            'paid_amount'       => 0.00,
            'adjustment_amount' => 3000.00,
            'status'            => 'paid',
        ]);

        $adj = FeeChallanAdjustment::create([
            'school_id'         => $this->school->id,
            'fee_challan_id'    => $challan->id,
            'student_id'        => $this->student->id,
            'created_by'        => $this->adminUser->id,
            'adjustment_type'   => 'percentage',
            'value'             => '100',
            'adjustment_amount' => 3000.00,
            'previous_balance'  => 3000.00,
            'new_balance'       => 0.00,
            'reason'            => 'Orphan 100% Scholarship',
        ]);
        $adj->created_at = Carbon::parse('2026-09-28 12:00:00');
        $adj->save();

        $response = $this->actingAs($this->adminUser)
            ->get(route('school.fees.challans.show', $challan->id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Fees/Challan')
            ->where('challan.display_status', 'waived')
            ->where('challan.settlement_classification', 'Settled — Adjusted')
            ->where('challan.settlement_date', '2026-09-28')
        );
    }

    public function test_settlement_date_picks_later_adjustment_when_payment_is_earlier(): void
    {
        // Net obligation PKR 3000: Payment PKR 2000 on 20-09-2026, Adjustment PKR 1000 on 22-09-2026
        $challan = $this->createChallan([
            'due_date'          => '2026-09-15',
            'gross_amount'      => 3000.00,
            'total_payable'     => 2000.00,
            'paid_amount'       => 2000.00,
            'adjustment_amount' => 1000.00,
            'status'            => 'paid',
        ]);

        FeePayment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student->id,
            'fee_challan_id'   => $challan->id,
            'receipt_no'       => 'RCP-2026-' . rand(1000, 9999),
            'amount_due'       => 3000.00,
            'amount_paid'      => 2000.00,
            'discount'         => 0.00,
            'fine'             => 0.00,
            'balance_snapshot' => 1000.00,
            'payment_date'     => '2026-09-20',
            'status'           => 'partial',
            'method'           => 'cash',
        ]);

        $adj = FeeChallanAdjustment::create([
            'school_id'         => $this->school->id,
            'fee_challan_id'    => $challan->id,
            'student_id'        => $this->student->id,
            'created_by'        => $this->adminUser->id,
            'adjustment_type'   => 'fixed',
            'value'             => '1000.00',
            'adjustment_amount' => 1000.00,
            'previous_balance'  => 1000.00,
            'new_balance'       => 0.00,
            'reason'            => 'Remaining balance waived by management',
        ]);
        $adj->created_at = Carbon::parse('2026-09-22 15:30:00');
        $adj->save();

        $this->assertEquals('2026-09-22', $challan->fresh()->settlement_date);

        $response = $this->actingAs($this->adminUser)
            ->get(route('school.fees.challans.show', $challan->id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Fees/Challan')
            ->where('challan.settlement_date', '2026-09-22')
            ->where('challan.settlement_classification', 'Paid + Adjusted')
        );
    }

    public function test_settlement_date_picks_later_payment_when_adjustment_is_earlier(): void
    {
        // Net obligation PKR 3000: Adjustment PKR 1000 on 20-09-2026, Payment PKR 2000 on 25-09-2026
        $challan = $this->createChallan([
            'due_date'          => '2026-09-15',
            'gross_amount'      => 3000.00,
            'total_payable'     => 2000.00,
            'paid_amount'       => 2000.00,
            'adjustment_amount' => 1000.00,
            'status'            => 'paid',
        ]);

        $adj = FeeChallanAdjustment::create([
            'school_id'         => $this->school->id,
            'fee_challan_id'    => $challan->id,
            'student_id'        => $this->student->id,
            'created_by'        => $this->adminUser->id,
            'adjustment_type'   => 'fixed',
            'value'             => '1000.00',
            'adjustment_amount' => 1000.00,
            'previous_balance'  => 3000.00,
            'new_balance'       => 2000.00,
            'reason'            => 'Pre-payment discount concession',
        ]);
        $adj->created_at = Carbon::parse('2026-09-20 11:00:00');
        $adj->save();

        FeePayment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student->id,
            'fee_challan_id'   => $challan->id,
            'receipt_no'       => 'RCP-2026-' . rand(1000, 9999),
            'amount_due'       => 2000.00,
            'amount_paid'      => 2000.00,
            'discount'         => 0.00,
            'fine'             => 0.00,
            'balance_snapshot' => 0.00,
            'payment_date'     => '2026-09-25',
            'status'           => 'paid',
            'method'           => 'cash',
        ]);

        $this->assertEquals('2026-09-25', $challan->fresh()->settlement_date);

        $response = $this->actingAs($this->adminUser)
            ->get(route('school.fees.challans.show', $challan->id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Fees/Challan')
            ->where('challan.settlement_date', '2026-09-25')
            ->where('challan.settlement_classification', 'Paid + Adjusted')
        );
    }

    public function test_multiple_fee_items_are_loaded_in_challan_view(): void
    {
        $challan = $this->createChallan(['gross_amount' => 6000.00]);

        $examCat = FeeCategory::create([
            'school_id' => $this->school->id,
            'name'      => 'Exam Fee',
            'type'      => 'exam',
            'is_active' => true,
        ]);

        $this->createChallanItem($challan, [
            'fee_head_name' => 'Tuition Fee - Sep 2026',
            'gross_amount'  => 5000.00,
            'net_amount'    => 5000.00,
        ]);

        $this->createChallanItem($challan, [
            'fee_head_name' => 'Mid-Term Exam Fee',
            'gross_amount'  => 1000.00,
            'net_amount'    => 1000.00,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->get(route('school.fees.challans.show', $challan->id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Fees/Challan')
            ->has('challan.items', 2)
            ->where('challan.items.0.fee_head_name', 'Tuition Fee - Sep 2026')
            ->where('challan.items.1.fee_head_name', 'Mid-Term Exam Fee')
        );
    }

    public function test_long_student_and_guardian_names_do_not_error(): void
    {
        $longStudentName = 'Muhammad Abdullah Hashim Ali Al-Hussaini Junior bin Farhan';
        $longGuardianName = 'Al-Hajj Sheikh Muhammad Azam Riaz Ahmad Khan Bahadur Sahib';

        $this->guardian->update(['name' => $longGuardianName]);
        $this->student->update(['first_name' => $longStudentName, 'last_name' => '']);

        $challan = $this->createChallan([
            'student_name' => $longStudentName,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->get(route('school.fees.challans.show', $challan->id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Fees/Challan')
            ->where('challan.student_name', $longStudentName)
            ->where('challan.guardian_name', $longGuardianName)
        );
    }

    public function test_missing_cnic_handled_gracefully(): void
    {
        $challan = $this->createChallan();

        $response = $this->actingAs($this->adminUser)
            ->get(route('school.fees.challans.show', $challan->id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Fees/Challan')
            ->where('challan.guardian_cnic', null)
        );
    }

    public function test_missing_school_logo_handled_gracefully(): void
    {
        $this->school->update(['logo' => null]);
        $challan = $this->createChallan();

        $response = $this->actingAs($this->adminUser)
            ->get(route('school.fees.challans.show', $challan->id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Fees/Challan')
            ->where('school.logo', null)
        );
    }

    public function test_bank_details_rendered_only_when_configured(): void
    {
        $challan = $this->createChallan();

        // 1. Without bank details
        $resWithoutBank = $this->actingAs($this->adminUser)
            ->get(route('school.fees.challans.show', $challan->id));
        $resWithoutBank->assertOk();
        $resWithoutBank->assertInertia(fn (Assert $page) => $page
            ->where('bankConfig', null)
        );

        // 2. With real bank details configured
        $this->school->update([
            'settings' => [
                'bank_name'       => 'Habib Bank Limited (HBL)',
                'bank_account_no' => '0123-45678901-03',
                'bank_branch'     => 'Gulberg Branch Lahore',
                'bank_iban'       => 'PK36HABB0000123456789010',
            ],
        ]);

        $resWithBank = $this->actingAs($this->adminUser)
            ->get(route('school.fees.challans.show', $challan->id));
        $resWithBank->assertOk();
        $resWithBank->assertInertia(fn (Assert $page) => $page
            ->where('bankConfig.bank_name', 'Habib Bank Limited (HBL)')
            ->where('bankConfig.account_no', '0123-45678901-03')
            ->where('bankConfig.branch', 'Gulberg Branch Lahore')
            ->where('bankConfig.iban', 'PK36HABB0000123456789010')
        );
    }

    public function test_bulk_print_endpoint_loads_allied_slip_dependencies(): void
    {
        $challan1 = $this->createChallan([
            'gross_amount' => 4500.00,
            'status'       => 'unpaid',
        ]);
        $this->createChallanItem($challan1, [
            'fee_head_name' => 'Tuition Fee - Sep 2026',
            'gross_amount'  => 4500.00,
            'net_amount'    => 4500.00,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->get(route('school.fees.challans.print-bulk', [
                'class_id' => $this->class->id,
                'month'    => '2026-09',
            ]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Fees/BulkChallans')
            ->has('challans', 1)
            ->where('challans.0.student_name', 'Abdullah Azam Dar')
            ->where('challans.0.guardian_name', 'Muhammad Azam Riaz')
            ->has('challans.0.items', 1)
        );
    }

    public function test_three_copy_a4_landscape_format_and_cutting_guides_present(): void
    {
        $cssContent = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('size: A4 landscape;', $cssContent);
        $this->assertStringContainsString('margin: 5mm;', $cssContent);
        $this->assertStringContainsString('max-width: 287mm !important;', $cssContent);
        $this->assertStringNotContainsString('min-height: 210mm !important;', $cssContent);

        $challanViewContent = file_get_contents(resource_path('js/Pages/SchoolAdmin/Fees/Challan.tsx'));
        $this->assertStringContainsString('SCHOOL / OFFICE COPY', $challanViewContent);
        $this->assertStringContainsString('BANK COPY', $challanViewContent);
        $this->assertStringContainsString('STUDENT / PARENT COPY', $challanViewContent);
        $this->assertStringContainsString('max-w-[287mm]', $challanViewContent);
        $this->assertStringContainsString('border-dashed border-neutral-400', $challanViewContent);
        $this->assertStringContainsString('✂', $challanViewContent);

        $bulkViewContent = file_get_contents(resource_path('js/Pages/SchoolAdmin/Fees/BulkChallans.tsx'));
        $this->assertStringContainsString('SCHOOL / OFFICE COPY', $bulkViewContent);
        $this->assertStringContainsString('BANK COPY', $bulkViewContent);
        $this->assertStringContainsString('STUDENT / PARENT COPY', $bulkViewContent);
        $this->assertStringContainsString('max-w-[287mm]', $bulkViewContent);
        $this->assertStringContainsString('✂', $bulkViewContent);

        $slipComponentContent = file_get_contents(resource_path('js/Pages/SchoolAdmin/Fees/Components/AlliedFeeChallanSlip.tsx'));
        $this->assertStringNotContainsString("minHeight: '210mm'", $slipComponentContent);
    }
}
