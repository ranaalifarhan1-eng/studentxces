<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\ActiveSchoolContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SuperAdminActiveSchoolContextTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $schoolAdminA;
    protected School $schoolA;
    protected School $schoolB;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);

        $this->schoolA = School::create([
            'name'   => 'Alpha Academy',
            'slug'   => 'alpha-academy',
            'email'  => 'alpha@example.com',
            'status' => 'active',
        ]);

        $this->schoolB = School::create([
            'name'   => 'Beta College',
            'slug'   => 'beta-college',
            'email'  => 'beta@example.com',
            'status' => 'active',
        ]);

        $this->superAdmin = User::factory()->create([
            'school_id' => null,
            'email'     => 'superadmin@example.com',
        ]);
        $this->superAdmin->assignRole('super-admin');

        $this->schoolAdminA = User::factory()->create([
            'school_id' => $this->schoolA->id,
            'email'     => 'admina@example.com',
        ]);
        $this->schoolAdminA->assignRole('school-admin');
    }

    public function test_super_admin_without_active_school_is_redirected_from_school_routes(): void
    {
        $response = $this->actingAs($this->superAdmin)->get('/school/reports/dashboard');

        $response->assertRedirect(route('super-admin.schools.index'));
        $response->assertSessionHas('warning', 'Please select an active school context before accessing school operations.');
    }

    public function test_super_admin_without_active_school_receives_403_on_json_school_requests(): void
    {
        $response = $this->actingAs($this->superAdmin)->getJson('/school/students');

        $response->assertStatus(403);
        $response->assertJsonFragment([
            'message' => 'No active school context selected. Please select a school to manage.',
        ]);
    }

    public function test_super_admin_can_select_school_context(): void
    {
        $response = $this->actingAs($this->superAdmin)->post('/super-admin/school-context/select', [
            'school_id' => $this->schoolA->id,
        ]);

        $response->assertRedirect(route('school.reports.dashboard'));
        $response->assertSessionHas('active_school_id', $this->schoolA->id);

        $context = app(ActiveSchoolContext::class);
        $this->assertEquals($this->schoolA->id, $context->getActiveSchoolId());
    }

    public function test_super_admin_cannot_select_invalid_or_non_existent_school(): void
    {
        $response = $this->actingAs($this->superAdmin)->post('/super-admin/school-context/select', [
            'school_id' => 99999,
        ]);

        $response->assertSessionHasErrors(['school_id']);
        $this->assertNull(session('active_school_id'));
    }

    public function test_selected_school_scopes_tenant_queries_strictly(): void
    {
        $classA = SchoolClass::create(['school_id' => $this->schoolA->id, 'name' => 'Grade 10-A', 'numeric_name' => 10]);
        $classB = SchoolClass::create(['school_id' => $this->schoolB->id, 'name' => 'Grade 10-B', 'numeric_name' => 10]);

        $studentA = Student::create([
            'school_id'    => $this->schoolA->id,
            'class_id'     => $classA->id,
            'first_name'   => 'Alice',
            'last_name'    => 'Alpha',
            'admission_no' => 'ADM-A-001',
            'status'       => 'active',
        ]);

        $studentB = Student::create([
            'school_id'    => $this->schoolB->id,
            'class_id'     => $classB->id,
            'first_name'   => 'Bob',
            'last_name'    => 'Beta',
            'admission_no' => 'ADM-B-001',
            'status'       => 'active',
        ]);

        // Establish School A context
        $this->actingAs($this->superAdmin)->withSession(['active_school_id' => $this->schoolA->id]);

        $response = $this->get('/school/students');
        $response->assertStatus(200);

        // School A student is in view props, School B student is NOT
        $pageProps = $response->viewData('page')['props'] ?? [];
        $studentData = $pageProps['students']['data'] ?? [];
        $studentIds = collect($studentData)->pluck('id');

        $this->assertTrue($studentIds->contains($studentA->id));
        $this->assertFalse($studentIds->contains($studentB->id));
    }

    public function test_super_admin_cannot_access_foreign_school_record_via_route_model_binding(): void
    {
        $classB = SchoolClass::create(['school_id' => $this->schoolB->id, 'name' => 'Grade 10-B', 'numeric_name' => 10]);
        $studentB = Student::create([
            'school_id'    => $this->schoolB->id,
            'class_id'     => $classB->id,
            'first_name'   => 'Bob',
            'last_name'    => 'Beta',
            'admission_no' => 'ADM-B-001',
            'status'       => 'active',
        ]);

        // Active context is School A
        $this->actingAs($this->superAdmin)->withSession(['active_school_id' => $this->schoolA->id]);

        // Attempting to access School B student must fail with 404 (scoped out)
        $response = $this->get("/school/students/{$studentB->id}");
        $response->assertStatus(404);
    }

    public function test_super_admin_created_model_receives_active_school_id(): void
    {
        $this->actingAs($this->superAdmin)->withSession(['active_school_id' => $this->schoolA->id]);

        $class = SchoolClass::create(['name' => 'Grade 11-Auto', 'numeric_name' => 11]);

        $this->assertEquals($this->schoolA->id, $class->school_id);
    }

    public function test_switching_school_context_updates_scoping_correctly(): void
    {
        $classA = SchoolClass::create(['school_id' => $this->schoolA->id, 'name' => 'Class A', 'numeric_name' => 1]);
        $classB = SchoolClass::create(['school_id' => $this->schoolB->id, 'name' => 'Class B', 'numeric_name' => 2]);

        // In School A context
        $this->actingAs($this->superAdmin)->withSession(['active_school_id' => $this->schoolA->id]);
        $this->assertEquals(1, SchoolClass::count());
        $this->assertEquals('Class A', SchoolClass::first()->name);

        // Switch to School B
        $this->post('/super-admin/school-context/select', ['school_id' => $this->schoolB->id]);
        $this->assertEquals(1, SchoolClass::count());
        $this->assertEquals('Class B', SchoolClass::first()->name);
    }

    public function test_clearing_school_context_exits_school_operations(): void
    {
        $this->actingAs($this->superAdmin)->withSession(['active_school_id' => $this->schoolA->id]);

        $response = $this->post('/super-admin/school-context/clear');

        $response->assertRedirect(route('super-admin.schools.index'));
        $this->assertNull(session('active_school_id'));

        // Subsequent /school request is blocked
        $blockedResponse = $this->get('/school/reports/dashboard');
        $blockedResponse->assertRedirect(route('super-admin.schools.index'));
    }

    public function test_super_admin_platform_routes_remain_global(): void
    {
        // Even without active school context, Super Admin accesses /super-admin/* globally
        $response = $this->actingAs($this->superAdmin)->get('/super-admin/schools');
        $response->assertStatus(200);

        $responseDashboard = $this->actingAs($this->superAdmin)->get('/super-admin/dashboard');
        $responseDashboard->assertStatus(200);
    }

    public function test_normal_school_admin_is_strictly_pinned_to_own_school(): void
    {
        // School Admin A attempts to set session active_school_id to School B
        $this->actingAs($this->schoolAdminA)->withSession(['active_school_id' => $this->schoolB->id]);

        $context = app(ActiveSchoolContext::class);
        $this->assertEquals($this->schoolA->id, $context->getActiveSchoolId(), 'Tenant user must ignore session override.');

        // School Admin A cannot call context selection endpoint (super-admin only)
        $postResponse = $this->post('/super-admin/school-context/select', ['school_id' => $this->schoolB->id]);
        $postResponse->assertStatus(403);
    }
}
