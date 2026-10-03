<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\FeeCategory;
use App\Models\FeeStructure;
use App\Models\Package;
use App\Models\PackageModule;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\SchoolSubscription;
use App\Models\Student;
use App\Models\StudentFeeAssignment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StudentAdmissionFeeStructureLookupTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected School $otherSchool;
    protected User $adminUser;
    protected AcademicYear $academicYear;
    protected SchoolClass $classGrade6;
    protected SchoolClass $classGrade7;
    protected FeeCategory $tuitionCategory;
    protected FeeCategory $libraryCategory;
    protected FeeCategory $examCategory;

    protected FeeStructure $matchingTuition;
    protected FeeStructure $matchingOptionalLibrary;
    protected FeeStructure $matchingExcludedExam;
    protected FeeStructure $wrongClassStructure;
    protected FeeStructure $wrongYearStructure;
    protected FeeStructure $inactiveStructure;
    protected FeeStructure $softDeletedStructure;
    protected FeeStructure $otherSchoolStructure;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Roles & Permissions
        $permissions = [
            'students.view', 'students.create', 'students.edit',
            'fees.view', 'fees.collect', 'fees.structure', 'fees.assign', 'fees.discount',
        ];
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $adminRole = Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions($permissions);

        // 2. Schools
        $this->school = School::create([
            'name'      => 'Demo Academy',
            'slug'      => 'demo-academy-' . uniqid(),
            'is_active' => true,
        ]);

        $this->otherSchool = School::create([
            'name'      => 'Foreign Academy',
            'slug'      => 'foreign-academy-' . uniqid(),
            'is_active' => true,
        ]);

        // 3. Entitlement / Subscription
        $package = Package::create([
            'name'          => 'Enterprise',
            'slug'          => 'enterprise-' . uniqid(),
            'price_monthly' => 100,
            'price_yearly'  => 1000,
            'max_students'  => 1000,
            'max_staff'     => 100,
            'storage_gb'    => 100,
            'is_active'     => true,
            'is_internal'   => false,
        ]);
        PackageModule::create(['package_id' => $package->id, 'module_slug' => 'students']);
        PackageModule::create(['package_id' => $package->id, 'module_slug' => 'fees']);

        SchoolSubscription::create([
            'school_id'  => $this->school->id,
            'package_id' => $package->id,
            'status'     => 'active',
            'start_date' => Carbon::now()->subDays(5),
            'end_date'   => Carbon::now()->addDays(60),
        ]);

        // 4. Admin User
        $this->adminUser = User::create([
            'name'      => 'School Admin',
            'email'     => 'admin@demo-' . uniqid() . '.test',
            'password'  => bcrypt('password'),
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
        $this->adminUser->assignRole('school-admin');

        // 5. Academic Year: "Academic Year 2026-27"
        $this->academicYear = AcademicYear::create([
            'school_id'  => $this->school->id,
            'name'       => 'Academic Year 2026-27',
            'start_date' => '2026-08-01',
            'end_date'   => '2027-06-30',
            'is_current' => true,
        ]);

        // 6. Classes
        $this->classGrade6 = SchoolClass::create([
            'school_id'    => $this->school->id,
            'name'         => 'Grade 6',
            'numeric_name' => 6,
        ]);

        $this->classGrade7 = SchoolClass::create([
            'school_id'    => $this->school->id,
            'name'         => 'Grade 7',
            'numeric_name' => 7,
        ]);

        // 7. Categories
        $this->tuitionCategory = FeeCategory::create([
            'school_id' => $this->school->id,
            'name'      => 'Tuition Fee',
            'type'      => 'tuition',
        ]);
        $this->libraryCategory = FeeCategory::create([
            'school_id' => $this->school->id,
            'name'      => 'Library Fee',
            'type'      => 'other',
        ]);
        $this->examCategory = FeeCategory::create([
            'school_id' => $this->school->id,
            'name'      => 'Exam Fee',
            'type'      => 'other',
        ]);

        // 8. Fee Structures
        // A. Matching required Tuition Fee with academic_year = '2026-2027'
        $this->matchingTuition = FeeStructure::create([
            'school_id'                => $this->school->id,
            'class_id'                 => $this->classGrade6->id,
            'fee_category_id'          => $this->tuitionCategory->id,
            'academic_year'            => '2026-2027',
            'amount'                   => 5000.00,
            'frequency'                => 'monthly',
            'is_optional'              => false,
            'admission_voucher_policy' => 'required',
            'is_active'                => true,
        ]);

        // B. Matching optional Library Fee with academic_year = '2026-27'
        $this->matchingOptionalLibrary = FeeStructure::create([
            'school_id'                => $this->school->id,
            'class_id'                 => $this->classGrade6->id,
            'fee_category_id'          => $this->libraryCategory->id,
            'academic_year'            => '2026-27',
            'amount'                   => 500.00,
            'frequency'                => 'monthly',
            'is_optional'              => true,
            'admission_voucher_policy' => 'optional',
            'is_active'                => true,
        ]);

        // C. Matching structure excluded from first admission voucher
        $this->matchingExcludedExam = FeeStructure::create([
            'school_id'                => $this->school->id,
            'class_id'                 => $this->classGrade6->id,
            'fee_category_id'          => $this->examCategory->id,
            'academic_year'            => '2026/2027',
            'amount'                   => 1200.00,
            'frequency'                => 'quarterly',
            'is_optional'              => false,
            'admission_voucher_policy' => 'excluded',
            'is_active'                => true,
        ]);

        // D. Wrong Class structure (Grade 7)
        $this->wrongClassStructure = FeeStructure::create([
            'school_id'                => $this->school->id,
            'class_id'                 => $this->classGrade7->id,
            'fee_category_id'          => $this->tuitionCategory->id,
            'academic_year'            => '2026-2027',
            'amount'                   => 5500.00,
            'frequency'                => 'monthly',
            'is_optional'              => false,
            'admission_voucher_policy' => 'required',
            'is_active'                => true,
        ]);

        // E. Wrong Academic Year structure (2025-2026)
        $this->wrongYearStructure = FeeStructure::create([
            'school_id'                => $this->school->id,
            'class_id'                 => $this->classGrade6->id,
            'fee_category_id'          => $this->tuitionCategory->id,
            'academic_year'            => '2025-2026',
            'amount'                   => 4500.00,
            'frequency'                => 'monthly',
            'is_optional'              => false,
            'admission_voucher_policy' => 'required',
            'is_active'                => true,
        ]);

        // F. Inactive structure
        $this->inactiveStructure = FeeStructure::create([
            'school_id'                => $this->school->id,
            'class_id'                 => $this->classGrade6->id,
            'fee_category_id'          => $this->tuitionCategory->id,
            'academic_year'            => '2026-2027',
            'amount'                   => 9999.00,
            'frequency'                => 'monthly',
            'is_optional'              => false,
            'admission_voucher_policy' => 'required',
            'is_active'                => false,
        ]);

        // G. Soft-deleted structure
        $this->softDeletedStructure = FeeStructure::create([
            'school_id'                => $this->school->id,
            'class_id'                 => $this->classGrade6->id,
            'fee_category_id'          => $this->tuitionCategory->id,
            'academic_year'            => '2026-2027',
            'amount'                   => 8888.00,
            'frequency'                => 'monthly',
            'is_optional'              => false,
            'admission_voucher_policy' => 'required',
            'is_active'                => true,
        ]);
        $this->softDeletedStructure->delete();

        // H. Other School structure (tenant isolation)
        $this->otherSchoolStructure = FeeStructure::create([
            'school_id'                => $this->otherSchool->id,
            'class_id'                 => $this->classGrade6->id,
            'fee_category_id'          => $this->tuitionCategory->id,
            'academic_year'            => '2026-2027',
            'amount'                   => 7777.00,
            'frequency'                => 'monthly',
            'is_optional'              => false,
            'admission_voucher_policy' => 'required',
            'is_active'                => true,
        ]);
    }

    public function test_admission_page_provides_academic_year_aliases(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/school/students/create');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Students/Create')
            ->has('academicYears')
            ->where('currentAcademicYear.name', 'Academic Year 2026-27')
            ->where('currentAcademicYear.year_aliases', fn ($aliases) =>
                collect($aliases)->contains('2026-2027') &&
                collect($aliases)->contains('2026-27') &&
                collect($aliases)->contains('Academic Year 2026-27') &&
                ! collect($aliases)->contains('2026')
            )
        );
    }

    public function test_fee_structures_endpoint_matches_equivalent_academic_year(): void
    {
        $response = $this->actingAs($this->adminUser)->getJson(
            '/school/students/fee-structures?class_id=' . $this->classGrade6->id . '&academic_year_id=' . $this->academicYear->id
        );

        $response->assertStatus(200);
        $data = $response->json();
        $ids = collect($data)->pluck('id')->all();

        // Must include structures with safe equivalent year spans
        $this->assertContains($this->matchingTuition->id, $ids);
        $this->assertContains($this->matchingOptionalLibrary->id, $ids);
        $this->assertContains($this->matchingExcludedExam->id, $ids);

        // Must exclude wrong class, wrong year, inactive, soft-deleted, and foreign school
        $this->assertNotContains($this->wrongClassStructure->id, $ids);
        $this->assertNotContains($this->wrongYearStructure->id, $ids);
        $this->assertNotContains($this->inactiveStructure->id, $ids);
        $this->assertNotContains($this->softDeletedStructure->id, $ids);
        $this->assertNotContains($this->otherSchoolStructure->id, $ids);
    }

    public function test_fee_structures_endpoint_backward_compatibility_with_academic_year_string(): void
    {
        // Calling with exact name string
        $response = $this->actingAs($this->adminUser)->getJson(
            '/school/students/fee-structures?class_id=' . $this->classGrade6->id . '&academic_year=Academic%20Year%202026-27'
        );

        $response->assertStatus(200);
        $ids = collect($response->json())->pluck('id')->all();

        $this->assertContains($this->matchingTuition->id, $ids);
        $this->assertContains($this->matchingOptionalLibrary->id, $ids);
        $this->assertContains($this->matchingExcludedExam->id, $ids);
    }

    public function test_student_admission_with_equivalent_fee_structure_succeeds(): void
    {
        $payload = [
            'first_name'                  => 'Hamza',
            'last_name'                   => 'Khan',
            'gender'                      => 'male',
            'date_of_birth'               => '2012-05-15',
            'category'                    => 'general',
            'status'                      => 'active',
            'class_id'                    => $this->classGrade6->id,
            'guardian'                    => [
                'name'     => 'Tariq Khan',
                'relation' => 'Father',
                'phone'    => '03001234567',
            ],
            'initialize_fees'             => true,
            'academic_year_id'            => $this->academicYear->id,
            'fee_structure_ids'           => [$this->matchingTuition->id, $this->matchingOptionalLibrary->id],
            'first_voucher_structure_ids' => [$this->matchingTuition->id],
            'generate_first_challan'      => true,
            'billing_start_month'         => '2026-08',
            'due_date'                    => '2026-08-15',
        ];

        $response = $this->actingAs($this->adminUser)->post('/school/students', $payload);

        $student = Student::where('school_id', $this->school->id)->where('first_name', 'Hamza')->first();
        $this->assertNotNull($student);

        $response->assertRedirect(route('school.students.show', $student));
        $response->assertSessionHas('success');

        // Verify fee assignments were created without throwing year mismatch exception
        // Server-authoritative union assigns both mandatory structures (Tuition + Exam) + submitted optional (Library)
        $assignments = StudentFeeAssignment::where('student_id', $student->id)->get();
        $this->assertSame(3, $assignments->count());
        $this->assertTrue($assignments->contains('fee_structure_id', $this->matchingTuition->id));
        $this->assertTrue($assignments->contains('fee_structure_id', $this->matchingOptionalLibrary->id));
        $this->assertTrue($assignments->contains('fee_structure_id', $this->matchingExcludedExam->id));
    }
}
