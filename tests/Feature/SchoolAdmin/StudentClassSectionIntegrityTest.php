<?php

namespace Tests\Feature\SchoolAdmin;

use App\Models\AcademicYear;
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
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StudentClassSectionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected School $schoolB;
    protected User $adminUser;
    protected SchoolClass $classA;
    protected SchoolClass $classB;
    protected Section $sectionA;
    protected Section $sectionB;
    protected Section $sectionSchoolB;
    protected AcademicYear $academicYear;

    protected function setUp(): void
    {
        parent::setUp();

        $pView = Permission::firstOrCreate(['name' => 'students.view', 'guard_name' => 'web']);
        $pCreate = Permission::firstOrCreate(['name' => 'students.create', 'guard_name' => 'web']);
        $pEdit = Permission::firstOrCreate(['name' => 'students.edit', 'guard_name' => 'web']);
        $adminRole = Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions([$pView, $pCreate, $pEdit]);

        $this->school = School::create([
            'name'     => 'School A',
            'slug'     => 'school-a-' . uniqid(),
            'email'    => 'admin-' . uniqid() . '@a.test',
            'status'   => 'active',
            'timezone' => 'Asia/Karachi',
        ]);

        $this->schoolB = School::create([
            'name'     => 'School B',
            'slug'     => 'school-b-' . uniqid(),
            'email'    => 'admin-' . uniqid() . '@b.test',
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
        PackageModule::create(['package_id' => $package->id, 'module_slug' => 'students']);

        SchoolSubscription::create([
            'school_id'   => $this->school->id,
            'package_id'  => $package->id,
            'status'      => 'active',
            'start_date'  => Carbon::now()->subDays(10),
            'end_date'    => Carbon::now()->addDays(30),
        ]);

        $this->adminUser = User::create([
            'name'              => 'Admin User',
            'email'             => 'admin-' . uniqid() . '@a.test',
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

        $this->classA = SchoolClass::create([
            'school_id'    => $this->school->id,
            'name'         => 'Class 9',
            'numeric_name' => 9,
        ]);

        $this->classB = SchoolClass::create([
            'school_id'    => $this->school->id,
            'name'         => 'Class 10',
            'numeric_name' => 10,
        ]);

        $this->sectionA = Section::create([
            'school_id' => $this->school->id,
            'class_id'  => $this->classA->id,
            'name'      => 'Section 9-A',
            'capacity'  => 30,
        ]);

        $this->sectionB = Section::create([
            'school_id' => $this->school->id,
            'class_id'  => $this->classB->id,
            'name'      => 'Section 10-A',
            'capacity'  => 30,
        ]);

        $classSchoolB = SchoolClass::create([
            'school_id'    => $this->schoolB->id,
            'name'         => 'Class 9 Other',
            'numeric_name' => 9,
        ]);

        $this->sectionSchoolB = Section::create([
            'school_id' => $this->schoolB->id,
            'class_id'  => $classSchoolB->id,
            'name'      => 'Section Other-A',
            'capacity'  => 30,
        ]);
    }

    public function test_student_creation_succeeds_with_matching_class_and_section(): void
    {
        $payload = [
            'first_name'     => 'Hamza',
            'gender'         => 'male',
            'category'       => 'general',
            'status'         => 'active',
            'class_id'       => $this->classA->id,
            'section_id'     => $this->sectionA->id,
            'guardian'       => [
                'name'     => 'Father Name',
                'relation' => 'Father',
            ],
            'date_of_birth'  => '2012-04-10',
            'admission_date' => '2026-08-01',
        ];

        $response = $this->actingAs($this->adminUser)->post('/school/students', $payload);
        $response->assertSessionHasNoErrors();

        $student = Student::where('first_name', 'Hamza')->first();
        $this->assertNotNull($student);
        $this->assertEquals($this->classA->id, $student->class_id);
        $this->assertEquals($this->sectionA->id, $student->section_id);
    }

    public function test_student_creation_rejected_when_section_belongs_to_different_class(): void
    {
        $studentCountBefore = Student::count();

        // Class A with Section B (which belongs to Class B)
        $payload = [
            'first_name'     => 'Mismatched',
            'gender'         => 'male',
            'category'       => 'general',
            'status'         => 'active',
            'class_id'       => $this->classA->id,
            'section_id'     => $this->sectionB->id,
            'guardian'       => [
                'name'     => 'Father Name',
                'relation' => 'Father',
            ],
            'date_of_birth'  => '2012-04-10',
            'admission_date' => '2026-08-01',
        ];

        $response = $this->actingAs($this->adminUser)->post('/school/students', $payload);
        $response->assertSessionHasErrors(['section_id']);
        $this->assertEquals($studentCountBefore, Student::count(), 'Zero students must be created.');
    }

    public function test_student_creation_rejected_when_section_belongs_to_different_school(): void
    {
        $studentCountBefore = Student::count();

        $payload = [
            'first_name'     => 'CrossSchool',
            'gender'         => 'male',
            'category'       => 'general',
            'status'         => 'active',
            'class_id'       => $this->classA->id,
            'section_id'     => $this->sectionSchoolB->id,
            'guardian'       => [
                'name'     => 'Father Name',
                'relation' => 'Father',
            ],
            'date_of_birth'  => '2012-04-10',
            'admission_date' => '2026-08-01',
        ];

        $response = $this->actingAs($this->adminUser)->post('/school/students', $payload);
        $response->assertSessionHasErrors(['section_id']);
        $this->assertEquals($studentCountBefore, Student::count());
    }

    public function test_student_creation_succeeds_with_null_section(): void
    {
        $payload = [
            'first_name'     => 'NoSection',
            'gender'         => 'female',
            'category'       => 'general',
            'status'         => 'active',
            'class_id'       => $this->classA->id,
            'section_id'     => null,
            'guardian'       => [
                'name'     => 'Mother Name',
                'relation' => 'Mother',
            ],
            'date_of_birth'  => '2012-04-10',
            'admission_date' => '2026-08-01',
        ];

        $response = $this->actingAs($this->adminUser)->post('/school/students', $payload);
        $response->assertSessionHasNoErrors();

        $student = Student::where('first_name', 'NoSection')->first();
        $this->assertNotNull($student);
        $this->assertNull($student->section_id);
    }

    public function test_student_creation_rejected_for_future_date_of_birth(): void
    {
        $futureDate = Carbon::now()->addYear()->format('Y-m-d');

        $payload = [
            'first_name'     => 'FutureDOB',
            'gender'         => 'male',
            'category'       => 'general',
            'status'         => 'active',
            'class_id'       => $this->classA->id,
            'section_id'     => $this->sectionA->id,
            'guardian'       => [
                'name'     => 'Father Name',
                'relation' => 'Father',
            ],
            'date_of_birth'  => $futureDate,
            'admission_date' => '2026-08-01',
        ];

        $response = $this->actingAs($this->adminUser)->post('/school/students', $payload);
        $response->assertSessionHasErrors(['date_of_birth']);
    }

    public function test_student_update_enforces_class_section_relational_integrity(): void
    {
        $student = Student::create([
            'school_id'      => $this->school->id,
            'class_id'       => $this->classA->id,
            'section_id'     => $this->sectionA->id,
            'admission_no'   => 'LCS-UPDATE-001',
            'first_name'     => 'Original',
            'last_name'      => 'Student',
            'gender'         => 'male',
            'status'         => 'active',
            'date_of_birth'  => '2012-01-01',
            'admission_date' => '2026-08-01',
        ]);

        // Attempt invalid update: Class A with Section B (Class 10)
        $invalidPayload = [
            'first_name'     => 'UpdatedName',
            'gender'         => 'male',
            'category'       => 'general',
            'status'         => 'active',
            'class_id'       => $this->classA->id,
            'section_id'     => $this->sectionB->id,
            'guardian'       => [
                'name'     => 'Guardian Name',
                'relation' => 'Father',
            ],
            'date_of_birth'  => '2012-01-01',
        ];

        $response = $this->actingAs($this->adminUser)->put("/school/students/{$student->id}", $invalidPayload);
        $response->assertSessionHasErrors(['section_id']);
        $this->assertEquals('Original', $student->fresh()->first_name);

        // Valid update: Class B with Section B
        $validPayload = [
            'first_name'     => 'UpdatedName',
            'gender'         => 'male',
            'category'       => 'general',
            'status'         => 'active',
            'class_id'       => $this->classB->id,
            'section_id'     => $this->sectionB->id,
            'guardian'       => [
                'name'     => 'Guardian Name',
                'relation' => 'Father',
            ],
            'date_of_birth'  => '2012-01-01',
        ];

        $validRes = $this->actingAs($this->adminUser)->put("/school/students/{$student->id}", $validPayload);
        $validRes->assertSessionHasNoErrors();
        $this->assertEquals('UpdatedName', $student->fresh()->first_name);
        $this->assertEquals($this->classB->id, $student->fresh()->class_id);
        $this->assertEquals($this->sectionB->id, $student->fresh()->section_id);
    }

    public function test_existing_guardian_link_bypasses_new_guardian_required_fields(): void
    {
        $existingGuardian = Guardian::create([
            'school_id' => $this->school->id,
            'name'      => 'Existing Father',
            'relation'  => 'Father',
            'phone'     => '03001234567',
        ]);

        $payload = [
            'first_name'     => 'Sibling',
            'gender'         => 'female',
            'category'       => 'general',
            'status'         => 'active',
            'class_id'       => $this->classA->id,
            'section_id'     => $this->sectionA->id,
            'guardian_id'    => $existingGuardian->id,
            'date_of_birth'  => '2013-05-10',
            'admission_date' => '2026-08-01',
        ];

        $response = $this->actingAs($this->adminUser)->post('/school/students', $payload);
        $response->assertSessionHasNoErrors();

        $student = Student::where('first_name', 'Sibling')->first();
        $this->assertNotNull($student);
        $this->assertEquals($existingGuardian->id, $student->guardian_id);
    }
}
