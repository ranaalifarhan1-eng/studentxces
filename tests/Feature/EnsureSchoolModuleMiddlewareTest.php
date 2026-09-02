<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PackageModule;
use App\Models\School;
use App\Models\SchoolModule;
use App\Models\SchoolSubscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EnsureSchoolModuleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'parent', 'guard_name' => 'web']);
    }

    protected function createSchoolWithSubscription(string $name = 'School MW', array $moduleSlugs = []): array
    {
        $school = School::create([
            'name'   => $name,
            'slug'   => \Illuminate\Support\Str::slug($name . '-' . uniqid()),
            'email'  => strtolower(str_replace(' ', '', $name)) . uniqid() . '@example.com',
            'status' => 'active',
        ]);

        $package = Package::create([
            'name'          => $name . ' Plan',
            'slug'          => \Illuminate\Support\Str::slug($name . '-plan-' . uniqid()),
            'price_monthly' => 20,
            'price_yearly'  => 200,
            'max_students'  => 100,
            'max_staff'     => 20,
            'storage_gb'    => 10,
            'is_active'     => true,
            'is_internal'   => false,
        ]);

        foreach ($moduleSlugs as $slug) {
            PackageModule::create(['package_id' => $package->id, 'module_slug' => $slug]);
        }

        $subscription = SchoolSubscription::create([
            'school_id'   => $school->id,
            'package_id'  => $package->id,
            'start_date'  => Carbon::yesterday(),
            'end_date'    => Carbon::tomorrow(),
            'status'      => 'active',
            'amount_paid' => 20,
        ]);

        $user = User::factory()->create(['school_id' => $school->id]);
        $user->assignRole('school-admin');

        return [$school, $user, $package, $subscription];
    }

    public function test_off_mode_permits_module_route_even_when_not_in_package(): void
    {
        Config::set('entitlement.mode', 'off');

        // School has active subscription, but NO library module in package
        [$school, $user] = $this->createSchoolWithSubscription('School Off Mode', ['students']);

        $response = $this->actingAs($user)->get('/school/library/books');

        // Under OFF mode, module entitlement does not block (HTTP 200)
        $response->assertStatus(200);
    }

    public function test_observe_mode_permits_request_and_logs_would_be_denial(): void
    {
        Config::set('entitlement.mode', 'observe');

        // School has active subscription, but NO library module in package
        [$school, $user] = $this->createSchoolWithSubscription('School Observe Mode', ['students']);

        Log::spy();

        // Request library route under OBSERVE mode
        $response = $this->actingAs($user)->get('/school/library/books');

        // OBSERVE mode does NOT block (HTTP 200)
        $response->assertStatus(200);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function ($message, $context) use ($school, $user) {
                return str_contains($message, '[ENTITLEMENT OBSERVE]')
                    && $context['school_id'] === $school->id
                    && $context['user_id'] === $user->id
                    && $context['module'] === 'library'
                    && $context['reason_code'] === 'MODULE_NOT_IN_PACKAGE';
            });
    }

    public function test_enforce_mode_blocks_unauthorized_module_with_403(): void
    {
        Config::set('entitlement.mode', 'enforce');

        // School has active subscription, but NO library module in package
        [$school, $user] = $this->createSchoolWithSubscription('School Enforce Mode Deny', ['students']);

        $response = $this->actingAs($user)->get('/school/library/books');

        $response->assertStatus(403);
    }

    public function test_enforce_mode_allows_module_when_school_is_entitled(): void
    {
        Config::set('entitlement.mode', 'enforce');

        // School has active subscription with library module included
        [$school, $user] = $this->createSchoolWithSubscription('School Enforce Mode Allow', ['library']);

        $response = $this->actingAs($user)->get('/school/library/books');

        $response->assertStatus(200);
    }

    public function test_direct_post_mutation_passes_in_off_and_blocks_in_enforce(): void
    {
        // 1. OFF Mode: passes through
        Config::set('entitlement.mode', 'off');
        [$schoolOff, $userOff] = $this->createSchoolWithSubscription('School Mutation Off', []);

        $responseOff = $this->actingAs($userOff)->post('/school/transport/vehicles', [
            'registration_no' => 'LEA-1234',
            'type'            => 'bus',
            'capacity'        => 30,
            'driver_name'     => 'John Doe',
            'driver_phone'    => '1234567890',
        ]);
        $responseOff->assertSessionHasNoErrors();
        $this->assertTrue(in_array($responseOff->status(), [200, 302]));

        // 2. ENFORCE Mode: blocked when transport module is not in package
        Config::set('entitlement.mode', 'enforce');
        [$schoolEnforce, $userEnforce] = $this->createSchoolWithSubscription('School Mutation Enforce', []);

        $responseEnforce = $this->actingAs($userEnforce)->post('/school/transport/vehicles', [
            'registration_no' => 'LEA-5678',
            'type'            => 'bus',
            'capacity'        => 30,
            'driver_name'     => 'Jane Doe',
            'driver_phone'    => '0987654321',
        ]);
        $responseEnforce->assertStatus(403);
    }

    public function test_super_admin_bypasses_module_middleware_in_enforce_mode(): void
    {
        Config::set('entitlement.mode', 'enforce');

        $school = School::create([
            'name'   => 'Super Test School',
            'slug'   => 'super-test-school',
            'status' => 'active',
        ]);

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');

        // Super Admin accessing school library in active school context
        $response = $this->actingAs($superAdmin)
            ->withSession(['active_school_id' => $school->id])
            ->get('/school/library/books');

        $response->assertStatus(200);
    }

    public function test_core_dashboard_and_settings_remain_accessible_in_enforce_mode(): void
    {
        Config::set('entitlement.mode', 'enforce');

        [$school, $user] = $this->createSchoolWithSubscription('School Core Accessible', []);

        // Core dashboard landing page
        $resDashboard = $this->actingAs($user)->get('/school/reports/dashboard');
        $resDashboard->assertStatus(200);

        // Core settings
        $resSettings = $this->actingAs($user)->get('/school/settings');
        $resSettings->assertStatus(200);

        // Core integrations
        $resIntegrations = $this->actingAs($user)->get('/school/settings/integrations');
        $resIntegrations->assertStatus(200);
    }

    public function test_spatie_role_restriction_is_enforced_independently_of_school_module(): void
    {
        Config::set('entitlement.mode', 'enforce');

        [$school] = $this->createSchoolWithSubscription('School Role Independent', ['library']);
        $studentUser = User::factory()->create(['school_id' => $school->id]);
        $studentUser->assignRole('student'); // Student role cannot access admin routes

        // Student accessing staff library books route (blocked by Spatie role middleware)
        $response = $this->actingAs($studentUser)->get('/school/library/books');

        $response->assertStatus(403);
    }

    public function test_student_portal_attendance_gated_by_attendance_module_in_enforce_mode(): void
    {
        Config::set('entitlement.mode', 'enforce');

        [$school] = $this->createSchoolWithSubscription('School Portal Gate', ['timetable']);
        $studentUser = User::factory()->create(['school_id' => $school->id]);
        $studentUser->assignRole('student');

        // Student portal dashboard is CORE (ungated)
        $resDashboard = $this->actingAs($studentUser)->get('/school/student/dashboard');
        $resDashboard->assertStatus(200);

        // Student timetable is entitled (in package)
        $resTimetable = $this->actingAs($studentUser)->get('/school/student/timetable');
        $resTimetable->assertStatus(200);

        // Student attendance is denied (not in package)
        $resAttendance = $this->actingAs($studentUser)->get('/school/student/attendance');
        $resAttendance->assertStatus(403);
    }

    public function test_invalid_entitlement_mode_throws_invalid_argument_exception(): void
    {
        Config::set('entitlement.mode', 'enfore'); // Typo / invalid mode

        [$school, $user] = $this->createSchoolWithSubscription('School Invalid Mode', ['library']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid entitlement mode 'enfore'");

        $this->withoutExceptionHandling()
            ->actingAs($user)
            ->get('/school/library/books');
    }

    public function test_invalid_module_slug_in_middleware_throws_invalid_argument_exception(): void
    {
        $middleware = app(\App\Http\Middleware\EnsureSchoolModule::class);
        $request = \Illuminate\Http\Request::create('/school/some-route', 'GET');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown or invalid module slug 'invalid-typo-slug'");

        $middleware->handle($request, function () {
            return response('OK');
        }, 'invalid-typo-slug');
    }
}
