<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantAuditAndRoleProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;
    protected School $schoolB;
    protected User $superAdmin;
    protected User $schoolAAdmin;
    protected User $schoolBAdmin;
    protected User $schoolATeacher;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed Spatie roles
        $roles = [
            'super-admin', 'school-admin', 'principal', 'teacher',
            'accountant', 'librarian', 'receptionist', 'driver',
            'warden', 'store-manager', 'student', 'parent',
        ];
        foreach ($roles as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }

        // Create Schools
        $this->schoolA = School::create([
            'name'     => 'School Alpha',
            'slug'     => 'school-alpha',
            'status'   => 'active',
            'country'  => 'PK',
            'timezone' => 'Asia/Karachi',
        ]);

        $this->schoolB = School::create([
            'name'     => 'School Beta',
            'slug'     => 'school-beta',
            'status'   => 'active',
            'country'  => 'PK',
            'timezone' => 'Asia/Karachi',
        ]);

        $pkg = \App\Models\Package::firstOrCreate(
            ['slug' => 'test-all-access-pkg'],
            [
                'name'          => 'Test All Access',
                'currency'      => 'PKR',
                'price_monthly' => 100,
                'is_active'     => true,
                'is_internal'   => false,
            ]
        );

        \App\Models\SchoolSubscription::create([
            'school_id'   => $this->schoolA->id,
            'package_id'  => $pkg->id,
            'start_date'  => now()->subDay(),
            'end_date'    => now()->addYear(),
            'status'      => 'active',
            'amount_paid' => 100,
        ]);

        \App\Models\SchoolSubscription::create([
            'school_id'   => $this->schoolB->id,
            'package_id'  => $pkg->id,
            'start_date'  => now()->subDay(),
            'end_date'    => now()->addYear(),
            'status'      => 'active',
            'amount_paid' => 100,
        ]);

        // Create Super Admin
        $this->superAdmin = User::create([
            'name'      => 'Super Admin',
            'email'     => 'superadmin@test.com',
            'password'  => Hash::make('Password123!'),
            'school_id' => null,
            'status'    => 'active',
        ]);
        $this->superAdmin->assignRole('super-admin');

        // Create School A Admin
        $this->schoolAAdmin = User::create([
            'name'      => 'Admin Alpha',
            'email'     => 'admin@alpha.test',
            'password'  => Hash::make('Password123!'),
            'school_id' => $this->schoolA->id,
            'status'    => 'active',
        ]);
        $this->schoolAAdmin->assignRole('school-admin');

        // Create School A Teacher
        $this->schoolATeacher = User::create([
            'name'      => 'Teacher Alpha',
            'email'     => 'teacher@alpha.test',
            'password'  => Hash::make('Password123!'),
            'school_id' => $this->schoolA->id,
            'status'    => 'active',
        ]);
        $this->schoolATeacher->assignRole('teacher');

        // Create School B Admin
        $this->schoolBAdmin = User::create([
            'name'      => 'Admin Beta',
            'email'     => 'admin@beta.test',
            'password'  => Hash::make('Password123!'),
            'school_id' => $this->schoolB->id,
            'status'    => 'active',
        ]);
        $this->schoolBAdmin->assignRole('school-admin');
    }

    public function test_tenant_audit_log_sees_own_school_user_activity(): void
    {
        // Activity caused by School A Admin
        activity()
            ->causedBy($this->schoolAAdmin)
            ->withProperties(['school_id' => $this->schoolA->id])
            ->log('Admin Alpha updated settings');

        // Activity caused by School A Teacher
        activity()
            ->causedBy($this->schoolATeacher)
            ->withProperties(['school_id' => $this->schoolA->id])
            ->log('Teacher Alpha submitted attendance');

        $this->actingAs($this->schoolAAdmin);

        $response = $this->get(route('school.reports.audit-log'));
        $response->assertStatus(200);

        $logs = $response->viewData('page')['props']['logs']['data'];
        $descriptions = array_column($logs, 'description');

        $this->assertContains('Admin Alpha updated settings', $descriptions);
        $this->assertContains('Teacher Alpha submitted attendance', $descriptions);
    }

    public function test_tenant_audit_log_hides_super_admin_activity(): void
    {
        // Super Admin action with School A context / property
        activity()
            ->causedBy($this->superAdmin)
            ->performedOn($this->schoolA)
            ->withProperties(['school_id' => $this->schoolA->id])
            ->log('Super Admin selected school context [School Alpha]');

        // School A Admin action
        activity()
            ->causedBy($this->schoolAAdmin)
            ->withProperties(['school_id' => $this->schoolA->id])
            ->log('Admin Alpha logged in');

        $this->actingAs($this->schoolAAdmin);

        $response = $this->get(route('school.reports.audit-log'));
        $response->assertStatus(200);

        $logs = $response->viewData('page')['props']['logs']['data'];
        $descriptions = array_column($logs, 'description');

        $this->assertContains('Admin Alpha logged in', $descriptions);
        $this->assertNotContains('Super Admin selected school context [School Alpha]', $descriptions);
    }

    public function test_tenant_audit_log_hides_system_platform_lifecycle_events(): void
    {
        // System activity without causer (causer = null)
        activity()
            ->performedOn($this->schoolA)
            ->withProperties(['school_id' => $this->schoolA->id])
            ->log('Tenant foundation created: School [School Alpha]');

        // School A Admin action
        activity()
            ->causedBy($this->schoolAAdmin)
            ->withProperties(['school_id' => $this->schoolA->id])
            ->log('Admin Alpha viewed reports');

        $this->actingAs($this->schoolAAdmin);

        $response = $this->get(route('school.reports.audit-log'));
        $response->assertStatus(200);

        $logs = $response->viewData('page')['props']['logs']['data'];
        $descriptions = array_column($logs, 'description');

        $this->assertContains('Admin Alpha viewed reports', $descriptions);
        $this->assertNotContains('Tenant foundation created: School [School Alpha]', $descriptions);
    }

    public function test_tenant_audit_log_hides_other_school_user_activity(): void
    {
        // School B Admin action
        activity()
            ->causedBy($this->schoolBAdmin)
            ->withProperties(['school_id' => $this->schoolB->id])
            ->log('Admin Beta updated fee structure');

        // School A Admin action
        activity()
            ->causedBy($this->schoolAAdmin)
            ->withProperties(['school_id' => $this->schoolA->id])
            ->log('Admin Alpha updated fee structure');

        $this->actingAs($this->schoolAAdmin);

        $response = $this->get(route('school.reports.audit-log'));
        $response->assertStatus(200);

        $logs = $response->viewData('page')['props']['logs']['data'];
        $descriptions = array_column($logs, 'description');

        $this->assertContains('Admin Alpha updated fee structure', $descriptions);
        $this->assertNotContains('Admin Beta updated fee structure', $descriptions);
    }

    public function test_dashboard_recent_activity_uses_same_privacy_rule(): void
    {
        // 1. Super Admin action
        activity()
            ->causedBy($this->superAdmin)
            ->performedOn($this->schoolA)
            ->withProperties(['school_id' => $this->schoolA->id])
            ->log('Super Admin switched context');

        // 2. System lifecycle action
        activity()
            ->performedOn($this->schoolA)
            ->withProperties(['school_id' => $this->schoolA->id])
            ->log('System backup completed');

        // 3. School A Admin action
        activity()
            ->causedBy($this->schoolAAdmin)
            ->withProperties(['school_id' => $this->schoolA->id])
            ->log('Admin Alpha created class');

        $this->actingAs($this->schoolAAdmin);

        $response = $this->get(route('school.reports.dashboard'));
        $response->assertStatus(200);

        $recentActivity = $response->viewData('page')['props']['recentActivity'];
        $descriptions = collect($recentActivity)->pluck('description')->toArray();

        $this->assertContains('Admin Alpha created class', $descriptions);
        $this->assertNotContains('Super Admin switched context', $descriptions);
        $this->assertNotContains('System backup completed', $descriptions);
    }

    public function test_super_admin_retains_platform_events(): void
    {
        activity()
            ->causedBy($this->superAdmin)
            ->performedOn($this->schoolA)
            ->withProperties(['school_id' => $this->schoolA->id])
            ->log('Super Admin selected school context [School Alpha]');

        activity()
            ->performedOn($this->schoolA)
            ->withProperties(['school_id' => $this->schoolA->id])
            ->log('Tenant foundation created: School [School Alpha]');

        $response = $this->actingAs($this->superAdmin)
            ->withSession(['active_school_id' => $this->schoolA->id])
            ->get(route('school.reports.audit-log'));
        $response->assertStatus(200);

        $logs = $response->viewData('page')['props']['logs']['data'];
        $descriptions = array_column($logs, 'description');

        $this->assertContains('Super Admin selected school context [School Alpha]', $descriptions);
        $this->assertContains('Tenant foundation created: School [School Alpha]', $descriptions);
    }

    public function test_tenant_role_selector_does_not_expose_super_admin_but_exposes_school_admin(): void
    {
        $this->actingAs($this->schoolAAdmin);

        $response = $this->get(route('school.settings.admins'));
        $response->assertStatus(200);

        $roles = $response->viewData('page')['props']['roles'];

        $this->assertNotContains('super-admin', $roles);
        $this->assertContains('school-admin', $roles);
        $this->assertContains('principal', $roles);
        $this->assertContains('accountant', $roles);
        $this->assertContains('teacher', $roles);
    }

    public function test_crafted_create_request_with_super_admin_role_is_rejected(): void
    {
        $this->actingAs($this->schoolAAdmin);

        $initialSuperAdminCount = User::role('super-admin')->count();

        $response = $this->post(route('school.settings.admins.store'), [
            'name'     => 'Hacker User',
            'email'    => 'hacker@alpha.test',
            'phone'    => '03001234567',
            'password' => 'Password123!',
            'role'     => 'super-admin',
            'status'   => 'active',
        ]);

        // Validation fails
        $response->assertSessionHasErrors(['role']);
        $this->assertEquals($initialSuperAdminCount, User::role('super-admin')->count());
        $this->assertDatabaseMissing('users', ['email' => 'hacker@alpha.test']);
    }

    public function test_crafted_update_request_to_super_admin_role_is_rejected(): void
    {
        $this->actingAs($this->schoolAAdmin);

        $initialSuperAdminCount = User::role('super-admin')->count();

        $response = $this->put(route('school.settings.admins.update', $this->schoolATeacher), [
            'name'     => 'Teacher Alpha Promoted',
            'email'    => 'teacher@alpha.test',
            'phone'    => '03001234567',
            'role'     => 'super-admin',
            'status'   => 'active',
        ]);

        $response->assertSessionHasErrors(['role']);
        $this->assertEquals($initialSuperAdminCount, User::role('super-admin')->count());
        $this->assertFalse($this->schoolATeacher->fresh()->hasRole('super-admin'));
        $this->assertTrue($this->schoolATeacher->fresh()->hasRole('teacher'));
    }

    public function test_school_admin_can_legitimately_create_another_school_admin_for_same_school(): void
    {
        $this->actingAs($this->schoolAAdmin);

        $response = $this->post(route('school.settings.admins.store'), [
            'name'     => 'Second Admin Alpha',
            'email'    => 'admin2@alpha.test',
            'phone'    => '03009999999',
            'password' => 'Password123!',
            'role'     => 'school-admin',
            'status'   => 'active',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $newAdmin = User::where('email', 'admin2@alpha.test')->first();
        $this->assertNotNull($newAdmin);
        $this->assertEquals($this->schoolA->id, $newAdmin->school_id);
        $this->assertTrue($newAdmin->hasRole('school-admin'));
        $this->assertFalse($newAdmin->hasRole('super-admin'));
    }

    public function test_cross_school_id_injection_is_blocked_on_create(): void
    {
        $this->actingAs($this->schoolAAdmin);

        // Attempt to create user with school_id = School B
        $response = $this->post(route('school.settings.admins.store'), [
            'name'      => 'Injected Admin',
            'email'     => 'injected@test.com',
            'password'  => 'Password123!',
            'role'      => 'school-admin',
            'school_id' => $this->schoolB->id, // Malicious injection!
            'status'    => 'active',
        ]);

        $response->assertRedirect();

        $user = User::where('email', 'injected@test.com')->first();
        $this->assertNotNull($user);

        // Server must enforce School A ID, NOT School B!
        $this->assertEquals($this->schoolA->id, $user->school_id);
        $this->assertNotEquals($this->schoolB->id, $user->school_id);
    }

    public function test_tenant_admin_cannot_target_super_admin_or_cross_school_user(): void
    {
        $this->actingAs($this->schoolAAdmin);

        // 1. Attempt to update Super Admin
        $resUpdateSuper = $this->put(route('school.settings.admins.update', $this->superAdmin), [
            'name'   => 'Tampered Super Admin',
            'email'  => 'superadmin@test.com',
            'role'   => 'teacher',
            'status' => 'active',
        ]);
        $this->assertEquals(403, $resUpdateSuper->getStatusCode());

        // 2. Attempt to delete Super Admin
        $resDeleteSuper = $this->delete(route('school.settings.admins.destroy', $this->superAdmin));
        $this->assertEquals(403, $resDeleteSuper->getStatusCode());

        // 3. Attempt to update School B Admin
        $resUpdateSchoolB = $this->put(route('school.settings.admins.update', $this->schoolBAdmin), [
            'name'   => 'Tampered Beta Admin',
            'email'  => 'admin@beta.test',
            'role'   => 'teacher',
            'status' => 'active',
        ]);
        $this->assertEquals(403, $resUpdateSchoolB->getStatusCode());

        // 4. Attempt to delete School B Admin
        $resDeleteSchoolB = $this->delete(route('school.settings.admins.destroy', $this->schoolBAdmin));
        $this->assertEquals(403, $resDeleteSchoolB->getStatusCode());
    }

    public function test_normal_principal_and_accountant_creation_still_works(): void
    {
        $this->actingAs($this->schoolAAdmin);

        // Create Principal
        $this->post(route('school.settings.admins.store'), [
            'name'     => 'Principal Tariq',
            'email'    => 'principal@alpha.test',
            'password' => 'Password123!',
            'role'     => 'principal',
            'status'   => 'active',
        ])->assertRedirect();

        $principal = User::where('email', 'principal@alpha.test')->first();
        $this->assertNotNull($principal);
        $this->assertTrue($principal->hasRole('principal'));
        $this->assertEquals($this->schoolA->id, $principal->school_id);

        // Create Accountant
        $this->post(route('school.settings.admins.store'), [
            'name'     => 'Accountant Asif',
            'email'    => 'accountant@alpha.test',
            'password' => 'Password123!',
            'role'     => 'accountant',
            'status'   => 'active',
        ])->assertRedirect();

        $accountant = User::where('email', 'accountant@alpha.test')->first();
        $this->assertNotNull($accountant);
        $this->assertTrue($accountant->hasRole('accountant'));
        $this->assertEquals($this->schoolA->id, $accountant->school_id);
    }
}
