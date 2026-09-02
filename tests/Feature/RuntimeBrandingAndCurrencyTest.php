<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RuntimeBrandingAndCurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolPKR;
    protected School $schoolUSD;
    protected School $schoolGBP;
    protected User $superAdmin;
    protected User $schoolPKRAdmin;
    protected User $schoolUSDAdmin;
    protected User $schoolGBPAdmin;
    protected User $studentPKR;
    protected User $parentPKR;

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

        // School with PKR
        $this->schoolPKR = School::create([
            'name'     => 'Lahore Cambridge School',
            'slug'     => 'lahore-cambridge-school',
            'currency' => 'PKR',
            'timezone' => 'Asia/Karachi',
            'language' => 'en',
            'status'   => 'active',
            'country'  => 'PK',
        ]);

        // School with USD
        $this->schoolUSD = School::create([
            'name'     => 'American International Academy',
            'slug'     => 'american-international-academy',
            'currency' => 'USD',
            'timezone' => 'America/New_York',
            'language' => 'en',
            'status'   => 'active',
            'country'  => 'US',
        ]);

        // School with GBP
        $this->schoolGBP = School::create([
            'name'     => 'London Grammar School',
            'slug'     => 'london-grammar-school',
            'currency' => 'GBP',
            'timezone' => 'Europe/London',
            'language' => 'en',
            'status'   => 'active',
            'country'  => 'GB',
        ]);

        // Super Admin (no school)
        $this->superAdmin = User::create([
            'name'      => 'Super Admin',
            'email'     => 'superadmin@studentxces.test',
            'password'  => Hash::make('Password123!'),
            'school_id' => null,
            'status'    => 'active',
        ]);
        $this->superAdmin->assignRole('super-admin');

        // PKR School Admin
        $this->schoolPKRAdmin = User::create([
            'name'      => 'Shahzia Dar',
            'email'     => 'shahzia@lahorecambridge.test',
            'password'  => Hash::make('Password123!'),
            'school_id' => $this->schoolPKR->id,
            'status'    => 'active',
        ]);
        $this->schoolPKRAdmin->assignRole('school-admin');

        // USD School Admin
        $this->schoolUSDAdmin = User::create([
            'name'      => 'John Smith',
            'email'     => 'john@aia.test',
            'password'  => Hash::make('Password123!'),
            'school_id' => $this->schoolUSD->id,
            'status'    => 'active',
        ]);
        $this->schoolUSDAdmin->assignRole('school-admin');

        // GBP School Admin
        $this->schoolGBPAdmin = User::create([
            'name'      => 'Arthur Pendelton',
            'email'     => 'arthur@lgs.test',
            'password'  => Hash::make('Password123!'),
            'school_id' => $this->schoolGBP->id,
            'status'    => 'active',
        ]);
        $this->schoolGBPAdmin->assignRole('school-admin');

        // Student for PKR School
        $this->studentPKR = User::create([
            'name'      => 'Ali Dar',
            'email'     => 'ali@student.lahorecambridge.test',
            'password'  => Hash::make('Password123!'),
            'school_id' => $this->schoolPKR->id,
            'status'    => 'active',
        ]);
        $this->studentPKR->assignRole('student');

        // Parent for PKR School
        $this->parentPKR = User::create([
            'name'      => 'Parent Dar',
            'email'     => 'parent@lahorecambridge.test',
            'password'  => Hash::make('Password123!'),
            'school_id' => $this->schoolPKR->id,
            'status'    => 'active',
        ]);
        $this->parentPKR->assignRole('parent');
    }

    public function test_tenant_admin_receives_school_name_and_pkr_currency(): void
    {
        $this->actingAs($this->schoolPKRAdmin);

        $response = $this->get(route('school.reports.dashboard'));
        $response->assertStatus(200);

        $props = $response->viewData('page')['props'];

        $this->assertEquals('Lahore Cambridge School', $props['branding']['app_name']);
        $this->assertEquals('Lahore Cambridge School', $props['branding']['tenant_name']);
        $this->assertEquals('PKR', $props['branding']['currency']);
        $this->assertTrue($props['branding']['is_tenant_context']);

        $this->assertEquals('Lahore Cambridge School', $props['active_school']['name']);
        $this->assertEquals('PKR', $props['active_school']['currency']);
        $this->assertEquals('Asia/Karachi', $props['active_school']['timezone']);

        $this->assertEquals('PKR', $props['locale']['currency_code']);
        $this->assertEquals('Asia/Karachi', $props['locale']['timezone']);
        $this->assertEquals('en', $props['locale']['language']);
    }

    public function test_multi_currency_isolation_between_schools(): void
    {
        // Test PKR School
        $this->actingAs($this->schoolPKRAdmin);
        $resPKR = $this->get(route('school.reports.dashboard'));
        $propsPKR = $resPKR->viewData('page')['props'];
        $this->assertEquals('PKR', $propsPKR['active_school']['currency']);
        $this->assertEquals('PKR', $propsPKR['locale']['currency_code']);

        // Test USD School
        $this->actingAs($this->schoolUSDAdmin);
        $resUSD = $this->get(route('school.reports.dashboard'));
        $propsUSD = $resUSD->viewData('page')['props'];
        $this->assertEquals('American International Academy', $propsUSD['branding']['app_name']);
        $this->assertEquals('USD', $propsUSD['active_school']['currency']);
        $this->assertEquals('USD', $propsUSD['locale']['currency_code']);
        $this->assertEquals('America/New_York', $propsUSD['locale']['timezone']);

        // Test GBP School
        $this->actingAs($this->schoolGBPAdmin);
        $resGBP = $this->get(route('school.reports.dashboard'));
        $propsGBP = $resGBP->viewData('page')['props'];
        $this->assertEquals('London Grammar School', $propsGBP['branding']['app_name']);
        $this->assertEquals('GBP', $propsGBP['active_school']['currency']);
        $this->assertEquals('GBP', $propsGBP['locale']['currency_code']);
        $this->assertEquals('Europe/London', $propsGBP['locale']['timezone']);
    }

    public function test_super_admin_receives_studentxces_branding_and_no_active_school(): void
    {
        $this->actingAs($this->superAdmin);

        $response = $this->get(route('super-admin.dashboard'));
        $response->assertStatus(200);

        $props = $response->viewData('page')['props'];

        $this->assertEquals('StudentXces', $props['branding']['app_name']);
        $this->assertEquals('StudentXces', $props['branding']['platform_name']);
        $this->assertFalse($props['branding']['is_tenant_context']);
        $this->assertNull($props['branding']['tenant_name']);
        $this->assertNull($props['active_school']);
    }

    public function test_guest_login_page_renders_studentxces_and_no_genius_sms(): void
    {
        $response = $this->get('/login');
        $response->assertStatus(200);

        $content = $response->getContent();
        $this->assertStringNotContainsString('Genius SMS', $content);
        $this->assertStringContainsString('StudentXces', $content);
    }

    public function test_student_and_parent_receive_school_branding_and_currency(): void
    {
        // Student
        $this->actingAs($this->studentPKR);
        $resStudent = $this->get(route('student.dashboard'));
        $resStudent->assertStatus(200);
        $propsStudent = $resStudent->viewData('page')['props'];
        $this->assertEquals('Lahore Cambridge School', $propsStudent['branding']['app_name']);
        $this->assertEquals('PKR', $propsStudent['locale']['currency_code']);

        // Parent
        $this->actingAs($this->parentPKR);
        $resParent = $this->get(route('parent.dashboard'));
        $resParent->assertStatus(200);
        $propsParent = $resParent->viewData('page')['props'];
        $this->assertEquals('Lahore Cambridge School', $propsParent['branding']['app_name']);
        $this->assertEquals('PKR', $propsParent['locale']['currency_code']);
    }

    public function test_school_settings_currency_change_updates_rendered_props(): void
    {
        $this->actingAs($this->schoolPKRAdmin);

        // Initially PKR
        $response1 = $this->get(route('school.reports.dashboard'));
        $props1 = $response1->viewData('page')['props'];
        $this->assertEquals('PKR', $props1['active_school']['currency']);
        $this->assertEquals('PKR', $props1['locale']['currency_code']);

        // Update school currency to AED
        $this->schoolPKR->update([
            'currency' => 'AED',
            'timezone' => 'Asia/Dubai',
        ]);

        // Next request reflects updated currency and timezone dynamically
        $response2 = $this->get(route('school.reports.dashboard'));
        $props2 = $response2->viewData('page')['props'];
        $this->assertEquals('AED', $props2['active_school']['currency']);
        $this->assertEquals('AED', $props2['locale']['currency_code']);
        $this->assertEquals('Asia/Dubai', $props2['locale']['timezone']);
    }
}
