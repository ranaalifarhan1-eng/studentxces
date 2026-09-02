<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\SchoolSubscription;
use App\Models\Student;
use App\Models\User;
use App\Rules\SchoolExists;
use App\Services\ActiveSchoolContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SuperAdminActiveSchoolContextTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $schoolAdminA;
    protected User $schoolAdminB;
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

        $this->schoolAdminB = User::factory()->create([
            'school_id' => $this->schoolB->id,
            'email'     => 'adminb@example.com',
        ]);
        $this->schoolAdminB->assignRole('school-admin');
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
        $this->assertEquals($this->schoolA->id, $context->getSelectedSchoolId());
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

        // Access a school route to trigger tenant operational scope
        $this->get('/school/students');

        $class = SchoolClass::create(['name' => 'Grade 11-Auto', 'numeric_name' => 11]);

        $this->assertEquals($this->schoolA->id, $class->school_id);
    }

    public function test_switching_school_context_updates_scoping_correctly(): void
    {
        SchoolClass::create(['school_id' => $this->schoolA->id, 'name' => 'Class A', 'numeric_name' => 1]);
        SchoolClass::create(['school_id' => $this->schoolB->id, 'name' => 'Class B', 'numeric_name' => 2]);

        // In School A context
        $this->actingAs($this->superAdmin)->withSession(['active_school_id' => $this->schoolA->id]);
        $responseA = $this->get('/school/students');
        $responseA->assertStatus(200);

        // Switch to School B
        $this->post('/super-admin/school-context/select', ['school_id' => $this->schoolB->id]);
        $responseB = $this->get('/school/students');
        $responseB->assertStatus(200);
    }

    public function test_clearing_school_context_exits_school_operations(): void
    {
        $this->actingAs($this->superAdmin)->withSession(['active_school_id' => $this->schoolA->id]);

        $response = $this->post('/super-admin/school-context/clear');

        $response->assertRedirect(route('super-admin.dashboard'));
        $this->assertNull(session('active_school_id'));

        // Subsequent /school request is blocked
        $blockedResponse = $this->get('/school/reports/dashboard');
        $blockedResponse->assertRedirect(route('super-admin.schools.index'));
    }

    public function test_super_admin_in_school_mode_accesses_school_settings_for_active_school(): void
    {
        $this->actingAs($this->superAdmin)->withSession(['active_school_id' => $this->schoolA->id]);

        $response = $this->get('/school/settings');
        $response->assertStatus(200);

        $props = $response->viewData('page')['props'] ?? [];
        $this->assertEquals($this->schoolA->id, $props['school']['id']);
        $this->assertEquals('Alpha Academy', $props['school']['name']);
    }

    public function test_super_admin_in_school_mode_accesses_school_domains_for_active_school(): void
    {
        $this->actingAs($this->superAdmin)->withSession(['active_school_id' => $this->schoolA->id]);

        $response = $this->get('/school/settings/domains');
        $response->assertStatus(200);

        $props = $response->viewData('page')['props'] ?? [];
        $this->assertEquals('tenants.edusystem.store', $props['cname_target']);
        $this->assertEquals('edusystem.store', $props['tenant_base_domain']);
    }

    public function test_super_admin_in_school_mode_retains_super_admin_role_identity(): void
    {
        $this->actingAs($this->superAdmin)->withSession(['active_school_id' => $this->schoolA->id]);

        $response = $this->get('/school/reports/dashboard');
        $response->assertStatus(200);

        $props = $response->viewData('page')['props'] ?? [];
        $this->assertEquals($this->superAdmin->id, $props['auth']['user']['id']);
        $this->assertEquals('super-admin', $props['auth']['user']['role']);
        $this->assertEquals($this->schoolA->id, $props['active_school']['id']);
    }

    public function test_super_admin_platform_routes_remain_globally_unscoped_even_with_active_session(): void
    {
        // Super Admin has School A active in session
        $this->actingAs($this->superAdmin)->withSession(['active_school_id' => $this->schoolA->id]);

        // 1. Visit /super-admin/schools -> Must see both School A and School B
        $responseSchools = $this->get('/super-admin/schools');
        $responseSchools->assertStatus(200);
        $schoolsData = $responseSchools->viewData('page')['props']['schools']['data'] ?? [];
        $schoolIds = collect($schoolsData)->pluck('id');
        $this->assertTrue($schoolIds->contains($this->schoolA->id));
        $this->assertTrue($schoolIds->contains($this->schoolB->id));

        // 2. Visit /super-admin/users -> Must see users from School A, School B, and Super Admin
        $responseUsers = $this->get('/super-admin/users');
        $responseUsers->assertStatus(200);
        $usersData = $responseUsers->viewData('page')['props']['users']['data'] ?? [];
        $userIds = collect($usersData)->pluck('id');
        $this->assertTrue($userIds->contains($this->superAdmin->id));
        $this->assertTrue($userIds->contains($this->schoolAdminA->id));
        $this->assertTrue($userIds->contains($this->schoolAdminB->id));
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

    public function test_stale_or_soft_deleted_active_school_session_fails_closed(): void
    {
        // Soft delete School A
        $this->schoolA->delete();

        // Attempting to access /school/* with soft-deleted school in session
        $response = $this->actingAs($this->superAdmin)
            ->withSession(['active_school_id' => $this->schoolA->id])
            ->get('/school/reports/dashboard');

        // Must fail closed, clear invalid session, and redirect
        $response->assertRedirect(route('super-admin.schools.index'));
        $this->assertNull(session('active_school_id'));
    }

    public function test_unauthenticated_user_cannot_switch_or_clear_context(): void
    {
        $selectResponse = $this->post('/super-admin/school-context/select', ['school_id' => $this->schoolA->id]);
        $selectResponse->assertRedirect('/login');

        $clearResponse = $this->post('/super-admin/school-context/clear');
        $clearResponse->assertRedirect('/login');
    }

    public function test_context_switching_updates_inertia_entitlement_diagnostics(): void
    {
        $package = Package::create([
            'name'       => 'Pro Plan',
            'slug'       => 'pro-plan',
            'price'      => 100,
            'is_active'  => true,
            'sort_order' => 1,
        ]);

        SchoolSubscription::create([
            'school_id'  => $this->schoolA->id,
            'package_id' => $package->id,
            'status'     => 'active',
            'start_date' => today()->subMonth(),
            'end_date'   => today()->addYear(),
        ]);

        // School A has active subscription, School B has none
        $this->actingAs($this->superAdmin)->withSession(['active_school_id' => $this->schoolA->id]);
        $responseA = $this->get('/school/reports/dashboard');
        $propsA = $responseA->viewData('page')['props'] ?? [];
        $this->assertEquals($this->schoolA->id, $propsA['active_school']['id']);
        $this->assertTrue($propsA['entitlement']['subscription_active']);

        // Switch to School B (no subscription)
        $this->post('/super-admin/school-context/select', ['school_id' => $this->schoolB->id]);
        $responseB = $this->get('/school/reports/dashboard');
        $propsB = $responseB->viewData('page')['props'] ?? [];
        $this->assertEquals($this->schoolB->id, $propsB['active_school']['id']);
        $this->assertFalse($propsB['entitlement']['subscription_active']);
    }

    public function test_school_exists_validation_rule_respects_active_context(): void
    {
        $classA = SchoolClass::create(['school_id' => $this->schoolA->id, 'name' => 'Class A', 'numeric_name' => 1]);
        $classB = SchoolClass::create(['school_id' => $this->schoolB->id, 'name' => 'Class B', 'numeric_name' => 2]);

        // Super Admin in School A context
        $this->actingAs($this->superAdmin)->withSession(['active_school_id' => $this->schoolA->id]);
        $this->get('/school/students'); // establishes tenant operational scope

        // Validating class_id with School A's class succeeds
        $vA = Validator::make(['class_id' => $classA->id], [
            'class_id' => [SchoolExists::make('classes')],
        ]);
        $this->assertTrue($vA->passes());

        // Validating class_id with School B's class fails
        $vB = Validator::make(['class_id' => $classB->id], [
            'class_id' => [SchoolExists::make('classes')],
        ]);
        $this->assertFalse($vB->passes());
    }
}
