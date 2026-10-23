<?php

namespace Tests\Feature\SchoolAdmin;

use App\Models\Attendance;
use App\Models\Package;
use App\Models\PackageModule;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\SchoolSubscription;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportReliabilityPhase4CTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected School $otherSchool;
    protected User $adminUser;
    protected SchoolClass $classA;
    protected SchoolClass $classB;
    protected SchoolClass $otherSchoolClass;
    protected Student $studentA;
    protected Student $studentB;
    protected Student $otherStudent;
    protected Staff $staff;
    protected Attendance $studentAttA;
    protected Attendance $studentAttB;
    protected Attendance $staffAtt;
    protected Attendance $otherAtt;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);

        $this->school = School::create([
            'name'       => 'Test School Reports',
            'domain'     => 'testschoolreports',
            'is_active'  => true,
            'created_by' => 1,
        ]);

        $this->otherSchool = School::create([
            'name'       => 'Other School Reports',
            'domain'     => 'otherschoolreports',
            'is_active'  => true,
            'created_by' => 1,
        ]);

        $package = Package::create([
            'name'          => 'Reports Enterprise Plan',
            'slug'          => 'plan-' . uniqid(),
            'price_monthly' => 50,
            'price_yearly'  => 500,
            'max_students'  => 500,
            'max_staff'     => 50,
            'storage_gb'    => 50,
            'is_active'     => true,
            'is_internal'   => false,
        ]);
        PackageModule::create(['package_id' => $package->id, 'module_slug' => 'reports']);

        SchoolSubscription::create([
            'school_id'  => $this->school->id,
            'package_id' => $package->id,
            'status'     => 'active',
            'start_date' => Carbon::now()->subDays(10),
            'end_date'   => Carbon::now()->addDays(50),
        ]);

        SchoolSubscription::create([
            'school_id'  => $this->otherSchool->id,
            'package_id' => $package->id,
            'status'     => 'active',
            'start_date' => Carbon::now()->subDays(10),
            'end_date'   => Carbon::now()->addDays(50),
        ]);

        $this->adminUser = User::create([
            'name'      => 'Report Admin',
            'email'     => 'admin@reports.test',
            'password'  => bcrypt('secret123'),
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
        $this->adminUser->assignRole('school-admin');

        $this->classA = SchoolClass::create([
            'school_id'    => $this->school->id,
            'name'         => 'Grade 10-A',
            'numeric_name' => 10,
        ]);

        $this->classB = SchoolClass::create([
            'school_id'    => $this->school->id,
            'name'         => 'Grade 9-B',
            'numeric_name' => 9,
        ]);

        $this->otherSchoolClass = SchoolClass::create([
            'school_id'    => $this->otherSchool->id,
            'name'         => 'Grade 10-Other',
            'numeric_name' => 10,
        ]);

        $this->studentA = Student::create([
            'school_id'    => $this->school->id,
            'class_id'     => $this->classA->id,
            'admission_no' => 'ADM-001',
            'first_name'   => 'Ali',
            'last_name'    => 'Khan',
            'gender'       => 'male',
            'status'       => 'active',
        ]);

        $this->studentB = Student::create([
            'school_id'    => $this->school->id,
            'class_id'     => $this->classB->id,
            'admission_no' => 'ADM-002',
            'first_name'   => 'Fatima',
            'last_name'    => 'Zahra',
            'gender'       => 'female',
            'status'       => 'active',
        ]);

        $this->otherStudent = Student::create([
            'school_id'    => $this->otherSchool->id,
            'class_id'     => $this->otherSchoolClass->id,
            'admission_no' => 'ADM-OTH-001',
            'first_name'   => 'Cross',
            'last_name'    => 'School',
            'gender'       => 'male',
            'status'       => 'active',
        ]);

        $this->staff = Staff::create([
            'school_id'    => $this->school->id,
            'first_name'   => 'Usman',
            'last_name'    => 'Teacher',
            'email'        => 'usman@test.com',
            'status'       => 'active',
        ]);

        $today = Carbon::today()->toDateString();

        $this->studentAttA = Attendance::create([
            'school_id'       => $this->school->id,
            'attendable_type' => Student::class,
            'attendable_id'   => $this->studentA->id,
            'date'            => $today,
            'status'          => 'present',
            'remarks'         => 'On time',
        ]);

        $this->studentAttB = Attendance::create([
            'school_id'       => $this->school->id,
            'attendable_type' => Student::class,
            'attendable_id'   => $this->studentB->id,
            'date'            => $today,
            'status'          => 'absent',
            'remarks'         => 'Sick leave',
        ]);

        $this->staffAtt = Attendance::create([
            'school_id'       => $this->school->id,
            'attendable_type' => Staff::class,
            'attendable_id'   => $this->staff->id,
            'date'            => $today,
            'status'          => 'present',
            'remarks'         => 'Staff duty',
        ]);

        $this->otherAtt = Attendance::create([
            'school_id'       => $this->otherSchool->id,
            'attendable_type' => Student::class,
            'attendable_id'   => $this->otherStudent->id,
            'date'            => $today,
            'status'          => 'present',
            'remarks'         => 'Other school present',
        ]);
    }

    public function test_attendance_pdf_export_succeeds_without_500(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withSession(['current_school_id' => $this->school->id])
            ->get('/school/reports/attendance/export-pdf');

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
    }

    public function test_custom_report_builder_attendance_returns_json_records_without_500(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withSession(['current_school_id' => $this->school->id])
            ->postJson('/school/reports/custom/run', [
                'entity' => 'attendance',
            ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'date', 'student_name', 'admission_no', 'class', 'status', 'remarks'],
            ],
            'count',
        ]);

        $data = $response->json('data');
        $this->assertCount(2, $data);

        $studentNames = array_column($data, 'student_name');
        $this->assertContains('Ali Khan', $studentNames);
        $this->assertContains('Fatima Zahra', $studentNames);
        $this->assertNotContains('Usman Teacher', $studentNames);
        $this->assertNotContains('Cross School', $studentNames);
    }

    public function test_custom_report_builder_all_classes_does_not_fail_validation_422(): void
    {
        // Test entity: attendance with filters.class_id = 'all'
        $response = $this->actingAs($this->adminUser)
            ->withSession(['current_school_id' => $this->school->id])
            ->postJson('/school/reports/custom/run', [
                'entity'  => 'attendance',
                'filters' => [
                    'class_id' => 'all',
                    'status'   => 'all',
                ],
            ]);

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));

        // Test entity: students with filters.class_id = 'all'
        $studentResponse = $this->actingAs($this->adminUser)
            ->withSession(['current_school_id' => $this->school->id])
            ->postJson('/school/reports/custom/run', [
                'entity'  => 'students',
                'filters' => [
                    'class_id' => 'all',
                ],
            ]);

        $studentResponse->assertOk();
        $this->assertCount(2, $studentResponse->json('data'));
    }

    public function test_attendance_report_page_with_all_filters_does_not_return_empty_set(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withSession(['current_school_id' => $this->school->id])
            ->get('/school/reports/attendance?class_id=all&status=all');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Reports/Attendance')
            ->has('records.data', 2)
            ->where('summary.present', 1)
            ->where('summary.absent', 1)
        );
    }

    public function test_attendance_filters_by_specific_class_and_status(): void
    {
        // Filter by class A
        $resClassA = $this->actingAs($this->adminUser)
            ->withSession(['current_school_id' => $this->school->id])
            ->get('/school/reports/attendance?class_id=' . $this->classA->id);

        $resClassA->assertOk();
        $resClassA->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Reports/Attendance')
            ->has('records.data', 1)
            ->where('records.data.0.name', 'Ali Khan')
        );

        // Filter by status: absent
        $resAbsent = $this->actingAs($this->adminUser)
            ->withSession(['current_school_id' => $this->school->id])
            ->get('/school/reports/attendance?status=absent');

        $resAbsent->assertOk();
        $resAbsent->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Reports/Attendance')
            ->has('records.data', 1)
            ->where('records.data.0.name', 'Fatima Zahra')
        );

        // Custom report filter by class B
        $resCustomClassB = $this->actingAs($this->adminUser)
            ->withSession(['current_school_id' => $this->school->id])
            ->postJson('/school/reports/custom/run', [
                'entity'  => 'attendance',
                'filters' => ['class_id' => (string) $this->classB->id],
            ]);

        $resCustomClassB->assertOk();
        $dataB = $resCustomClassB->json('data');
        $this->assertCount(1, $dataB);
        $this->assertSame('Fatima Zahra', $dataB[0]['student_name']);
    }

    public function test_tenant_isolation_on_attendance_reports_and_pdf(): void
    {
        // School A user should not see other school records
        $response = $this->actingAs($this->adminUser)
            ->withSession(['current_school_id' => $this->school->id])
            ->get('/school/reports/attendance');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Reports/Attendance')
            ->has('records.data', 2)
        );

        // School A user passing cross-school class ID to attendance page returns empty list (no cross-school data leak)
        $resCross = $this->actingAs($this->adminUser)
            ->withSession(['current_school_id' => $this->school->id])
            ->get('/school/reports/attendance?class_id=' . $this->otherSchoolClass->id);

        $resCross->assertOk();
        $resCross->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Reports/Attendance')
            ->has('records.data', 0)
        );

        // School A user passing cross-school class ID to custom report is rejected by SchoolExists rule (422)
        $resCrossCustom = $this->actingAs($this->adminUser)
            ->withSession(['current_school_id' => $this->school->id])
            ->postJson('/school/reports/custom/run', [
                'entity'  => 'attendance',
                'filters' => ['class_id' => (string) $this->otherSchoolClass->id],
            ]);

        $resCrossCustom->assertStatus(422);
    }

    public function test_staff_attendance_is_strictly_excluded_from_student_reports(): void
    {
        // Custom builder attendance entity
        $customRes = $this->actingAs($this->adminUser)
            ->withSession(['current_school_id' => $this->school->id])
            ->postJson('/school/reports/custom/run', ['entity' => 'attendance']);

        $customRes->assertOk();
        foreach ($customRes->json('data') as $row) {
            $this->assertNotSame('Usman Teacher', $row['student_name']);
        }

        // Attendance page
        $pageRes = $this->actingAs($this->adminUser)
            ->withSession(['current_school_id' => $this->school->id])
            ->get('/school/reports/attendance');

        $pageRes->assertOk();
        $pageRes->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Reports/Attendance')
            ->has('records.data', 2)
        );
    }

    public function test_custom_report_export_csv_attendance(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withSession(['current_school_id' => $this->school->id])
            ->get('/school/reports/custom/export-csv?entity=attendance&class_id=all');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $content = $response->getContent();
        $this->assertStringContainsString('student_name', $content);
        $this->assertStringContainsString('Ali Khan', $content);
        $this->assertStringContainsString('Fatima Zahra', $content);
        $this->assertStringNotContainsString('Usman Teacher', $content);
    }

    public function test_completeness_export_with_over_500_records_without_truncation(): void
    {
        $now = now();
        $studentData = [];
        for ($i = 1; $i <= 525; $i++) {
            $studentData[] = [
                'school_id'    => $this->school->id,
                'class_id'     => $this->classA->id,
                'admission_no' => 'COMPL-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'first_name'   => 'BulkStudent' . $i,
                'last_name'    => 'Test',
                'gender'       => 'male',
                'status'       => 'active',
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }
        Student::insert($studentData);

        $bulkStudents = Student::withoutGlobalScopes()
            ->where('school_id', $this->school->id)
            ->where('admission_no', 'like', 'COMPL-%')
            ->get(['id']);

        $attData = [];
        foreach ($bulkStudents as $bs) {
            $attData[] = [
                'school_id'       => $this->school->id,
                'attendable_type' => Student::class,
                'attendable_id'   => $bs->id,
                'date'            => '2026-10-20',
                'status'          => 'present',
                'remarks'         => 'Completeness test row',
                'created_at'      => $now,
                'updated_at'      => $now,
            ];
        }
        Attendance::insert($attData);

        // 1. PDF export includes all rows matching filters without silent truncation
        $pdfRes = $this->actingAs($this->adminUser)
            ->withSession(['current_school_id' => $this->school->id])
            ->get('/school/reports/attendance/export-pdf?from_date=2026-10-20&to_date=2026-10-20');

        $pdfRes->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdfRes->headers->get('content-type'));
        $pdfContent = $pdfRes->getContent();
        $this->assertStringStartsWith('%PDF-', $pdfContent);

        // 2. CSV export includes all 525 matching records without silent 500-cap
        $csvRes = $this->actingAs($this->adminUser)
            ->withSession(['current_school_id' => $this->school->id])
            ->get('/school/reports/custom/export-csv?entity=attendance&from_date=2026-10-20&to_date=2026-10-20');

        $csvRes->assertOk();
        $csvLines = array_filter(explode("\n", trim($csvRes->getContent())));
        $this->assertCount(526, $csvLines); // 1 header + 525 rows

        // 3. Custom Report UI run returns explicit metadata (total_count=525, returned_count=500, is_truncated=true)
        $uiRes = $this->actingAs($this->adminUser)
            ->withSession(['current_school_id' => $this->school->id])
            ->postJson('/school/reports/custom/run', [
                'entity'  => 'attendance',
                'filters' => [
                    'from_date' => '2026-10-20',
                    'to_date'   => '2026-10-20',
                ],
            ]);

        $uiRes->assertOk();
        $uiRes->assertJsonPath('total_count', 525);
        $uiRes->assertJsonPath('returned_count', 500);
        $uiRes->assertJsonPath('is_truncated', true);
        $this->assertCount(500, $uiRes->json('data'));
    }

    public function test_attendance_pdf_pre_render_safety_guard_over_1000_records(): void
    {
        $now = now();
        $studentData = [];
        for ($i = 1; $i <= 1005; $i++) {
            $studentData[] = [
                'school_id'    => $this->school->id,
                'class_id'     => $this->classA->id,
                'admission_no' => 'GUARD-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'first_name'   => 'GuardStudent' . $i,
                'last_name'    => 'Test',
                'gender'       => 'male',
                'status'       => 'active',
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }
        Student::insert($studentData);

        $guardStudents = Student::withoutGlobalScopes()
            ->where('school_id', $this->school->id)
            ->where('admission_no', 'like', 'GUARD-%')
            ->get(['id']);

        $attData = [];
        foreach ($guardStudents as $gs) {
            $attData[] = [
                'school_id'       => $this->school->id,
                'attendable_type' => Student::class,
                'attendable_id'   => $gs->id,
                'date'            => '2026-10-21',
                'status'          => 'present',
                'remarks'         => 'Guard test row',
                'created_at'      => $now,
                'updated_at'      => $now,
            ];
        }
        Attendance::insert($attData);

        // 1. Direct browser GET renders user-visible warning notice (HTTP 200, no 500, no PHP OOM)
        $guardPdfRes = $this->actingAs($this->adminUser)
            ->withSession(['current_school_id' => $this->school->id])
            ->get('/school/reports/attendance/export-pdf?from_date=2026-10-21&to_date=2026-10-21');

        $guardPdfRes->assertOk();
        $guardPdfRes->assertSee('This PDF export exceeds the safe limit of 1,000 attendance records');
        $guardPdfRes->assertSee('found 1,005 records');

        // 2. Inertia request receives redirect / 409 location and flashes error
        $inertiaRes = $this->actingAs($this->adminUser)
            ->withSession(['current_school_id' => $this->school->id])
            ->from('/school/reports/attendance')
            ->withHeaders(['X-Inertia' => 'true'])
            ->get('/school/reports/attendance/export-pdf?from_date=2026-10-21&to_date=2026-10-21');

        $this->assertTrue(in_array($inertiaRes->getStatusCode(), [302, 409]));
        $inertiaRes->assertSessionHas('error');

        $this->flushHeaders();

        // 3. CSV export on the same 1,005 records succeeds completely without truncation
        $csvRes = $this->actingAs($this->adminUser)
            ->withSession(['current_school_id' => $this->school->id])
            ->get('/school/reports/custom/export-csv?entity=attendance&from_date=2026-10-21&to_date=2026-10-21');

        $csvRes->assertOk();
        $csvLines = array_filter(explode("\n", trim($csvRes->getContent())));
        $this->assertCount(1006, $csvLines); // 1 header + 1,005 rows
    }
}
