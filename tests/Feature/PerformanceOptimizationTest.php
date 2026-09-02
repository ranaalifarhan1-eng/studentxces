<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\FeeCategory;
use App\Models\FeePayment;
use App\Models\FeeStructure;
use App\Models\PlatformSetting;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\SchoolDomain;
use App\Models\Student;
use App\Models\User;
use App\Services\ActiveSchoolContext;
use App\Services\SchoolEntitlementResolver;
use App\Services\TenantDomainResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PerformanceOptimizationTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected User $schoolAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('tenancy.platform_base_domain', 'edusystem.store');
        Config::set('tenancy.tenant_base_domain', 'edusystem.store');
        Config::set('tenancy.cname_target', 'tenants.edusystem.store');
        Config::set('tenancy.platform_admin_host', 'console.edusystem.store');
        Config::set('tenancy.development_hosts', ['localhost', '127.0.0.1', '::1']);

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);

        $this->school = School::create([
            'name'     => 'Lahore Cambridge School',
            'slug'     => 'lahore-cambridge-school',
            'status'   => 'active',
            'currency' => 'PKR',
            'timezone' => 'Asia/Karachi',
        ]);

        $pkg = \App\Models\Package::firstOrCreate(
            ['slug' => 'legacy-all-access'],
            [
                'name'          => 'Legacy All Access',
                'currency'      => 'PKR',
                'price_monthly' => 0,
                'is_active'     => false,
                'is_internal'   => true,
            ]
        );

        \App\Models\SchoolSubscription::create([
            'school_id'   => $this->school->id,
            'package_id'  => $pkg->id,
            'start_date'  => now()->subDay(),
            'end_date'    => now()->addYear(),
            'status'      => 'active',
            'amount_paid' => 0,
        ]);

        SchoolDomain::create([
            'school_id'   => $this->school->id,
            'hostname'    => 'app.lahorecambridge.com',
            'type'        => 'custom',
            'status'      => SchoolDomain::STATUS_ACTIVE,
            'ssl_status'  => SchoolDomain::SSL_ACTIVE,
            'is_primary'  => true,
            'verified_at' => now(),
        ]);

        $this->schoolAdmin = User::create([
            'name'      => 'Shahzia Dar',
            'email'     => 'admin@lahorecambridge.com',
            'password'  => Hash::make('Password123!'),
            'school_id' => $this->school->id,
            'status'    => 'active',
        ]);
        $this->schoolAdmin->assignRole('school-admin');
    }

    public function test_tenant_domain_resolver_memoizes_db_lookups_per_instance(): void
    {
        $resolver = app(TenantDomainResolver::class);
        $resolver->clearCache();

        DB::enableQueryLog();

        // First resolution: hits DB
        $school1 = $resolver->resolveFromHost('app.lahorecambridge.com');
        $this->assertNotNull($school1);
        $this->assertEquals($this->school->id, $school1->id);
        $initialQueryCount = count(DB::getQueryLog());
        $this->assertGreaterThanOrEqual(1, $initialQueryCount);

        // Subsequent resolutions: memoized in memory, 0 new queries
        $school2 = $resolver->resolveFromHost('app.lahorecambridge.com');
        $this->assertEquals($this->school->id, $school2->id);

        $school3 = $resolver->resolveFromHost('APP.LAHORECAMBRIDGE.COM:443');
        $this->assertEquals($this->school->id, $school3->id);

        $finalQueryCount = count(DB::getQueryLog());
        $this->assertEquals($initialQueryCount, $finalQueryCount, 'Expected zero additional queries on repeated host resolution.');
    }

    public function test_tenant_domain_resolver_memoizes_null_for_unknown_hosts_without_cross_tenant_leakage(): void
    {
        $resolver = app(TenantDomainResolver::class);
        $resolver->clearCache();

        DB::enableQueryLog();

        $null1 = $resolver->resolveFromHost('unknown-school.com');
        $this->assertNull($null1);
        $queriesAfterUnknown = count(DB::getQueryLog());

        // Repeated unknown host
        $null2 = $resolver->resolveFromHost('unknown-school.com');
        $this->assertNull($null2);
        $this->assertEquals($queriesAfterUnknown, count(DB::getQueryLog()));

        // Console platform host
        $consoleResult = $resolver->resolveFromHost('console.edusystem.store');
        $this->assertNull($consoleResult);

        // Valid tenant host still resolves correctly
        $tenantResult = $resolver->resolveFromHost('app.lahorecambridge.com');
        $this->assertNotNull($tenantResult);
        $this->assertEquals($this->school->id, $tenantResult->id);
    }

    public function test_platform_setting_memoizes_get_queries(): void
    {
        PlatformSetting::flushCache();
        PlatformSetting::set('platform_name', 'StudentXces');

        DB::enableQueryLog();

        $val1 = PlatformSetting::get('platform_name');
        $this->assertEquals('StudentXces', $val1);
        $queries = count(DB::getQueryLog());

        // Subsequent calls do not query DB
        $val2 = PlatformSetting::get('platform_name');
        $val3 = PlatformSetting::get('platform_name');
        $this->assertEquals('StudentXces', $val2);
        $this->assertEquals('StudentXces', $val3);
        $this->assertEquals($queries, count(DB::getQueryLog()));
    }

    public function test_school_dashboard_produces_consistent_attendance_and_fee_charts(): void
    {
        $class = SchoolClass::create([
            'school_id' => $this->school->id,
            'name'      => 'Grade 10',
        ]);

        $feeCat = FeeCategory::create([
            'school_id' => $this->school->id,
            'name'      => 'Tuition Fee',
        ]);

        $feeStructure = FeeStructure::create([
            'school_id'       => $this->school->id,
            'class_id'        => $class->id,
            'fee_category_id' => $feeCat->id,
            'academic_year'   => '2026-2027',
            'amount'          => 5000,
        ]);

        $student = Student::create([
            'school_id'       => $this->school->id,
            'class_id'        => $class->id,
            'admission_no'    => 'ADM-001',
            'first_name'      => 'Ahmad',
            'last_name'       => 'Khan',
            'admission_date'  => now()->toDateString(),
            'gender'          => 'male',
            'status'          => 'active',
        ]);

        FeePayment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $student->id,
            'fee_structure_id' => $feeStructure->id,
            'amount_paid'      => 5000,
            'amount_due'       => 5000,
            'payment_date'     => now()->toDateString(),
            'status'           => 'paid',
        ]);

        FeePayment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $student->id,
            'fee_structure_id' => $feeStructure->id,
            'amount_paid'      => 3500,
            'amount_due'       => 3500,
            'payment_date'     => now()->subMonths(2)->startOfMonth()->toDateString(),
            'status'           => 'paid',
        ]);

        $this->actingAs($this->schoolAdmin);

        $response = $this->get('/school/reports/dashboard');
        $response->assertStatus(200);

        $props = $response->original->getData()['page']['props'];

        $this->assertArrayHasKey('feeChart', $props);
        $this->assertCount(6, $props['feeChart']);
        $this->assertEquals(5000, $props['feeChart'][5]['amount']); // current month (last item)

        $this->assertArrayHasKey('attChart', $props);
        $this->assertCount(7, $props['attChart']);
    }
}