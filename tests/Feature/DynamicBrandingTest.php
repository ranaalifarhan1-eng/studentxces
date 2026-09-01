<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DynamicBrandingTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;
    protected School $schoolB;
    protected User $superAdmin;
    protected User $schoolAAdmin;
    protected User $schoolBAdmin;

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
            'name'     => 'Lahore Cambridge School',
            'slug'     => 'lahore-cambridge-school',
            'logo'     => 'schools/119/branding/logo.png',
            'status'   => 'active',
            'country'  => 'PK',
            'timezone' => 'Asia/Karachi',
        ]);

        $this->schoolB = School::create([
            'name'     => 'Greenfield Academy',
            'slug'     => 'greenfield-academy',
            'logo'     => null,
            'status'   => 'active',
            'country'  => 'PK',
            'timezone' => 'Asia/Karachi',
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
            'name'      => 'Shahzia Dar',
            'email'     => 'admin@lahorecambridge.com',
            'password'  => Hash::make('Password123!'),
            'school_id' => $this->schoolA->id,
            'status'    => 'active',
        ]);
        $this->schoolAAdmin->assignRole('school-admin');

        // Create School B Admin
        $this->schoolBAdmin = User::create([
            'name'      => 'Admin Beta',
            'email'     => 'admin@greenfield.test',
            'password'  => Hash::make('Password123!'),
            'school_id' => $this->schoolB->id,
            'status'    => 'active',
        ]);
        $this->schoolBAdmin->assignRole('school-admin');
    }

    public function test_school_admin_receives_own_school_branding(): void
    {
        $this->actingAs($this->schoolAAdmin);

        $response = $this->get(route('school.reports.dashboard'));
        $response->assertStatus(200);

        $props = $response->viewData('page')['props'];

        $this->assertEquals('Lahore Cambridge School', $props['branding']['app_name']);
        $this->assertEquals('Lahore Cambridge School', $props['branding']['tenant_name']);
        $this->assertEquals($this->schoolA->id, $props['branding']['active_school_id']);
        $this->assertTrue($props['branding']['is_tenant_context']);
        $this->assertEquals('Lahore Cambridge School', $props['active_school']['name']);
    }

    public function test_school_a_never_receives_school_b_branding(): void
    {
        // School A requests dashboard
        $this->actingAs($this->schoolAAdmin);
        $resA = $this->get(route('school.reports.dashboard'));
        $propsA = $resA->viewData('page')['props'];

        $this->assertEquals('Lahore Cambridge School', $propsA['branding']['tenant_name']);
        $this->assertNotEquals('Greenfield Academy', $propsA['branding']['tenant_name']);

        // School B requests dashboard
        $this->actingAs($this->schoolBAdmin);
        $resB = $this->get(route('school.reports.dashboard'));
        $propsB = $resB->viewData('page')['props'];

        $this->assertEquals('Greenfield Academy', $propsB['branding']['tenant_name']);
        $this->assertNotEquals('Lahore Cambridge School', $propsB['branding']['tenant_name']);
    }

    public function test_super_admin_global_page_receives_studentxces_branding(): void
    {
        $this->actingAs($this->superAdmin);

        $response = $this->get(route('super-admin.dashboard'));
        $response->assertStatus(200);

        $props = $response->viewData('page')['props'];

        $this->assertEquals('StudentXces', $props['branding']['platform_name']);
        $this->assertNull($props['branding']['tenant_name']);
        $this->assertNull($props['branding']['active_school_id']);
        $this->assertFalse($props['branding']['is_tenant_context']);
        $this->assertNull($props['active_school']);
    }

    public function test_super_admin_inside_active_tenant_context_receives_tenant_operational_branding(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->withSession(['active_school_id' => $this->schoolA->id])
            ->get(route('school.reports.dashboard'));

        $response->assertStatus(200);

        $props = $response->viewData('page')['props'];

        $this->assertEquals('Lahore Cambridge School', $props['branding']['app_name']);
        $this->assertEquals('Lahore Cambridge School', $props['branding']['tenant_name']);
        $this->assertEquals($this->schoolA->id, $props['branding']['active_school_id']);
        $this->assertTrue($props['branding']['is_tenant_context']);
        $this->assertEquals('Lahore Cambridge School', $props['active_school']['name']);
    }

    public function test_guest_no_active_school_falls_back_to_studentxces(): void
    {
        $response = $this->get(route('login'));
        $response->assertStatus(200);

        $props = $response->viewData('page')['props'];

        $this->assertEquals('StudentXces', $props['branding']['platform_name']);
        $this->assertNull($props['branding']['tenant_name']);
        $this->assertFalse($props['branding']['is_tenant_context']);
    }

    public function test_tenant_with_logo_receives_correct_logo_url(): void
    {
        $this->actingAs($this->schoolAAdmin);

        $response = $this->get(route('school.reports.dashboard'));
        $response->assertStatus(200);

        $props = $response->viewData('page')['props'];

        $this->assertNotNull($props['branding']['logo_url']);
        $this->assertStringContainsString('schools/119/branding/logo.png', $props['branding']['logo_url']);
        $this->assertStringContainsString('schools/119/branding/logo.png', $props['active_school']['logo_url']);
    }

    public function test_tenant_without_logo_receives_null_logo_url(): void
    {
        $this->actingAs($this->schoolBAdmin);

        $response = $this->get(route('school.reports.dashboard'));
        $response->assertStatus(200);

        $props = $response->viewData('page')['props'];

        $this->assertNull($props['branding']['logo_url']);
        $this->assertNull($props['active_school']['logo_url']);
    }

    public function test_no_user_visible_genius_sms_in_responses(): void
    {
        // Login page response
        $loginRes = $this->get(route('login'));
        $loginContent = $loginRes->getContent();
        $this->assertStringNotContainsString('Genius SMS', $loginContent);

        // School Admin dashboard
        $this->actingAs($this->schoolAAdmin);
        $schoolRes = $this->get(route('school.reports.dashboard'));
        $schoolContent = $schoolRes->getContent();
        $this->assertStringNotContainsString('Genius SMS', $schoolContent);

        // Super Admin dashboard
        $this->actingAs($this->superAdmin);
        $superRes = $this->get(route('super-admin.dashboard'));
        $superContent = $superRes->getContent();
        $this->assertStringNotContainsString('Genius SMS', $superContent);
    }
}
