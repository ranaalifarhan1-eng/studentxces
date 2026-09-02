<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PackageModule;
use App\Models\School;
use App\Models\SchoolSubscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SchoolSubscriptionAccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected Package $standardPkg;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $this->superAdmin = User::factory()->create([
            'name'     => 'Super Admin',
            'email'    => 'superadmin@edusystem.store',
            'status'   => 'active',
            'password' => bcrypt('Password123!'),
        ]);
        $this->superAdmin->assignRole('super-admin');

        $this->standardPkg = Package::create([
            'name'          => 'Standard',
            'slug'          => 'standard',
            'currency'      => 'PKR',
            'price_monthly' => 5000.00,
            'max_students'  => 800,
            'max_staff'     => 80,
            'storage_gb'    => 30,
            'is_active'     => true,
            'is_internal'   => false,
        ]);
    }

    protected function createSchoolTenant(string $name, string $subStatus, ?string $startDate = null, ?string $endDate = null): array
    {
        $school = School::create([
            'name'     => $name,
            'slug'     => \Illuminate\Support\Str::slug($name),
            'country'  => 'PK',
            'timezone' => 'Asia/Karachi',
            'currency' => 'PKR',
            'language' => 'en',
            'status'   => 'active',
            'settings' => ['school_code' => strtoupper(substr(str_replace(' ', '', $name), 0, 5))],
        ]);

        $admin = User::create([
            'school_id' => $school->id,
            'name'      => $name . ' Admin',
            'email'     => 'admin@' . $school->slug . '.test',
            'password'  => bcrypt('Secret123!'),
            'status'    => 'active',
        ]);
        $admin->assignRole('school-admin');

        $sub = SchoolSubscription::create([
            'school_id'           => $school->id,
            'package_id'          => $this->standardPkg->id,
            'billing_term_months' => 6,
            'base_monthly_price'  => 5000.00,
            'discount_percent'    => 5.00,
            'billed_amount'       => 28500.00,
            'amount_paid'         => $subStatus === 'active' ? 28500.00 : 0.00,
            'start_date'          => $startDate ?? Carbon::yesterday()->toDateString(),
            'end_date'            => $endDate ?? Carbon::tomorrow()->addMonths(6)->toDateString(),
            'status'              => $subStatus,
            'is_trial'            => false,
        ]);

        return [$school, $admin, $sub];
    }

    public function test_active_school_admin_can_access_dashboard_and_operational_modules(): void
    {
        Config::set('entitlement.mode', 'off');

        [$school, $admin] = $this->createSchoolTenant('Active Heights Academy', 'active');

        // 1. Core dashboard
        $resDashboard = $this->actingAs($admin)->get('/school/reports/dashboard');
        $resDashboard->assertStatus(200);

        // 2. Operational students module
        $resStudents = $this->actingAs($admin)->get('/school/students');
        $resStudents->assertStatus(200);

        // 3. Visiting subscription notice redirects back to dashboard for active school
        $resNotice = $this->actingAs($admin)->get('/school/subscription-notice');
        $resNotice->assertRedirect(route('school.reports.dashboard'));
    }

    public function test_suspended_school_admin_is_blocked_from_operational_routes_and_redirects_to_notice(): void
    {
        Config::set('entitlement.mode', 'off');

        [$school, $admin, $sub] = $this->createSchoolTenant('Suspended Grammar School', 'suspended');

        // 1. Operational dashboard access redirects to subscription notice
        $resDashboard = $this->actingAs($admin)->get('/school/reports/dashboard');
        $resDashboard->assertRedirect(route('school.subscription.notice'));

        // 2. Operational students route redirects to subscription notice
        $resStudents = $this->actingAs($admin)->get('/school/students');
        $resStudents->assertRedirect(route('school.subscription.notice'));

        // 3. Operational fees route redirects to subscription notice
        $resFees = $this->actingAs($admin)->get('/school/fees/structures');
        $resFees->assertRedirect(route('school.subscription.notice'));

        // 4. JSON / API request receives HTTP 403 Forbidden with clear message
        $resJson = $this->actingAs($admin)->getJson('/school/students');
        $resJson->assertStatus(403);
        $resJson->assertJsonFragment([
            'message'             => 'Subscription Access Suspended. Please contact your administrator.',
            'subscription_status' => 'suspended',
        ]);
    }

    public function test_expired_school_admin_is_blocked_from_operational_routes(): void
    {
        Config::set('entitlement.mode', 'off');

        // End date was last month
        [$school, $admin] = $this->createSchoolTenant(
            'Expired Model School',
            'expired',
            Carbon::now()->subMonths(7)->toDateString(),
            Carbon::now()->subMonth()->toDateString()
        );

        $response = $this->actingAs($admin)->get('/school/reports/dashboard');
        $response->assertRedirect(route('school.subscription.notice'));
    }

    public function test_suspended_school_admin_can_access_notice_screen_without_redirect_loop(): void
    {
        Config::set('entitlement.mode', 'off');

        [$school, $admin] = $this->createSchoolTenant('Loop Proof Academy', 'suspended');

        $response = $this->actingAs($admin)->get('/school/subscription-notice');
        $response->assertStatus(200);
        $response->assertSee('Loop Proof Academy');
        $response->assertSee('suspended');
    }

    public function test_suspended_school_admin_can_logout_cleanly(): void
    {
        Config::set('entitlement.mode', 'off');

        [$school, $admin] = $this->createSchoolTenant('Logout Test School', 'suspended');

        $this->actingAs($admin);
        $this->assertAuthenticatedAs($admin);

        $resLogout = $this->post('/logout');
        $resLogout->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_super_admin_can_still_manage_suspended_school(): void
    {
        Config::set('entitlement.mode', 'off');

        [$school, $admin] = $this->createSchoolTenant('Managed Suspended School', 'suspended');

        // Super Admin selects the suspended school context
        $response = $this->actingAs($this->superAdmin)
            ->withSession(['active_school_id' => $school->id])
            ->get('/school/reports/dashboard');

        // Super Admin is NOT blocked by school subscription suspension
        $response->assertStatus(200);

        // Super Admin can also view settings and students
        $resStudents = $this->actingAs($this->superAdmin)
            ->withSession(['active_school_id' => $school->id])
            ->get('/school/students');
        $resStudents->assertStatus(200);
    }

    public function test_entitlement_mode_off_does_not_bypass_subscription_access_suspension(): void
    {
        // Explicitly enforce ENTITLEMENT_MODE = 'off'
        Config::set('entitlement.mode', 'off');

        [$school, $admin] = $this->createSchoolTenant('Off Mode Suspended School', 'suspended');

        // Normal tenant user must still be blocked when subscription is suspended, even in OFF mode
        $resReports = $this->actingAs($admin)->get('/school/reports/dashboard');
        $resReports->assertRedirect(route('school.subscription.notice'));
    }

    public function test_lahore_cambridge_active_access_remains_unaffected(): void
    {
        Config::set('entitlement.mode', 'off');

        $legacyPkg = Package::create([
            'name'          => 'Legacy All Access',
            'slug'          => 'legacy-all-access',
            'currency'      => 'PKR',
            'price_monthly' => 0.00,
            'max_students'  => 0,
            'max_staff'     => 0,
            'storage_gb'    => 100,
            'is_active'     => false,
            'is_internal'   => true,
        ]);

        $lcs = School::create([
            'name'     => 'Lahore Cambridge School',
            'slug'     => 'lahore-cambridge-school',
            'country'  => 'PK',
            'timezone' => 'Asia/Karachi',
            'currency' => 'PKR',
            'language' => 'en',
            'status'   => 'active',
            'settings' => ['school_code' => 'LCS01'],
        ]);

        $lcsAdmin = User::create([
            'school_id' => $lcs->id,
            'name'      => 'LCS Admin',
            'email'     => 'admin@lahorecambridge.com',
            'password'  => bcrypt('Secret123!'),
            'status'    => 'active',
        ]);
        $lcsAdmin->assignRole('school-admin');

        SchoolSubscription::create([
            'school_id'           => $lcs->id,
            'package_id'          => $legacyPkg->id,
            'billing_term_months' => 12,
            'base_monthly_price'  => 0.00,
            'discount_percent'    => 0.00,
            'billed_amount'       => 0.00,
            'amount_paid'         => 0.00,
            'start_date'          => Carbon::yesterday()->toDateString(),
            'end_date'            => Carbon::tomorrow()->addYear()->toDateString(),
            'status'              => 'active',
        ]);

        $response = $this->actingAs($lcsAdmin)->get('/school/reports/dashboard');
        $response->assertStatus(200);

        $resStudents = $this->actingAs($lcsAdmin)->get('/school/students');
        $resStudents->assertStatus(200);
    }
}
