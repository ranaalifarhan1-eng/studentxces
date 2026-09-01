<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\SchoolDomain;
use App\Models\User;
use App\Services\DnsResolverInterface;
use App\Services\DomainService;
use App\Services\HostnameNormalizer;
use App\Services\TenantDomainResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantDomainResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;
    protected School $schoolB;
    protected User $superAdmin;
    protected User $schoolAAdmin;
    protected User $schoolBAdmin;
    protected DomainService $domainService;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('tenancy.platform_base_domain', 'edusystem.store');
        Config::set('tenancy.tenant_base_domain', 'edusystem.store');
        Config::set('tenancy.cname_target', 'tenants.edusystem.store');
        Config::set('tenancy.accepted_cname_targets', ['tenants.edusystem.store', 'tenants.studentxces.com']);
        Config::set('tenancy.platform_admin_host', 'admin.edusystem.store');
        Config::set('tenancy.reserved_subdomains', ['www', 'admin', 'api', 'app', 'tenants', 'mail', 'support']);
        Config::set('tenancy.development_hosts', ['localhost', '127.0.0.1', '::1']);

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

        $this->domainService = app(DomainService::class);
    }

    public function test_hostname_normalization_and_strict_rfc_validation(): void
    {
        $this->assertEquals('app.lahorecambridge.com', HostnameNormalizer::normalize('APP.LahoreCambridge.COM'));
        $this->assertEquals('portal.school.edu.pk', HostnameNormalizer::normalize('portal.school.edu.pk.'));
        $this->assertEquals('lahore-cambridge.edusystem.store', HostnameNormalizer::normalize('  lahore-cambridge.edusystem.store  '));
    }

    public function test_invalid_hostname_inputs_rejected(): void
    {
        $invalidInputs = [
            'https://app.lahorecambridge.com',
            'http://app.lahorecambridge.com',
            'app.lahorecambridge.com/login',
            'app.lahorecambridge.com?param=value',
            'app.lahorecambridge.com#section',
            'user:pass@app.lahorecambridge.com',
            'app.lahorecambridge.com:8000',
            '*.lahorecambridge.com',
            'lahore cambridge.com',
            '',
            'singleword',
            '-invalid.school.com',
            'invalid-.school.com',
        ];

        foreach ($invalidInputs as $input) {
            try {
                HostnameNormalizer::normalize($input);
                $this->fail("Expected InvalidArgumentException for input: {$input}");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_hostname_global_uniqueness(): void
    {
        $this->domainService->addCustomDomain($this->schoolA, 'portal.myschool.com');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The domain 'portal.myschool.com' is already registered.");

        // School B attempts to add the same hostname
        $this->domainService->addCustomDomain($this->schoolB, 'portal.myschool.com');
    }

    public function test_reserved_platform_hostnames_rejected_but_customer_domains_allowed(): void
    {
        // Reserved under platform infrastructure domain
        $reservedExamples = [
            'admin.edusystem.store',
            'www.edusystem.store',
            'api.edusystem.store',
            'app.edusystem.store',
            'tenants.edusystem.store',
            'mail.edusystem.store',
            'support.edusystem.store',
        ];

        foreach ($reservedExamples as $hostname) {
            try {
                $this->domainService->addCustomDomain($this->schoolA, $hostname);
                $this->fail("Expected reserved domain rejection for: {$hostname}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('reserved', $e->getMessage());
            }
        }

        // Legitimate customer custom domains containing 'app', 'admin', 'www' MUST be allowed!
        $customDomain1 = $this->domainService->addCustomDomain($this->schoolA, 'app.lahorecambridge.com');
        $this->assertEquals('app.lahorecambridge.com', $customDomain1->hostname);

        $customDomain2 = $this->domainService->addCustomDomain($this->schoolB, 'admin.custom-school.com');
        $this->assertEquals('admin.custom-school.com', $customDomain2->hostname);

        $customDomain3 = $this->domainService->addCustomDomain($this->schoolA, 'www.myschoolportal.org');
        $this->assertEquals('www.myschoolportal.org', $customDomain3->hostname);
    }

    public function test_school_a_cannot_manage_school_b_domain(): void
    {
        $domainB = $this->domainService->addCustomDomain($this->schoolB, 'portal.greenfield.com');

        // School A Admin attempts to verify School B's domain
        $this->actingAs($this->schoolAAdmin);
        $resVerify = $this->post(route('school.settings.domains.verify', $domainB));
        $resVerify->assertStatus(403);

        // School A Admin attempts to make School B's domain primary
        $resPrimary = $this->patch(route('school.settings.domains.primary', $domainB));
        $resPrimary->assertStatus(403);

        // School A Admin attempts to delete School B's domain
        $resDelete = $this->delete(route('school.settings.domains.destroy', $domainB));
        $resDelete->assertStatus(403);

        // Domain B still belongs to School B
        $this->assertDatabaseHas('school_domains', [
            'id'        => $domainB->id,
            'school_id' => $this->schoolB->id,
        ]);
    }

    public function test_custom_domain_remains_pending_before_verification(): void
    {
        $domain = $this->domainService->addCustomDomain($this->schoolA, 'app.lahorecambridge.com');

        $this->assertEquals(SchoolDomain::STATUS_PENDING, $domain->status);
        $this->assertFalse($domain->is_primary);
        $this->assertNull($domain->verified_at);
        $this->assertNotNull($domain->verification_token);
        $this->assertStringStartsWith('stx_', $domain->verification_token);
        $this->assertEquals(SchoolDomain::SSL_PENDING, $domain->ssl_status);
    }

    public function test_default_domain_lifecycle_state_is_verified_and_ssl_pending(): void
    {
        $defaultDomain = $this->domainService->generateDefaultDomain($this->schoolA);

        $this->assertEquals(SchoolDomain::TYPE_DEFAULT, $defaultDomain->type);
        $this->assertEquals(SchoolDomain::STATUS_VERIFIED, $defaultDomain->status);
        $this->assertEquals(SchoolDomain::SSL_PENDING, $defaultDomain->ssl_status);
        $this->assertNotNull($defaultDomain->verified_at);
        $this->assertTrue($defaultDomain->is_primary);
    }

    public function test_dns_verification_succeeds_with_current_and_legacy_cname_targets(): void
    {
        // 1. Current target: tenants.edusystem.store
        $mockResolver1 = new class implements DnsResolverInterface {
            public function getCnameRecord(string $hostname): ?string { return 'tenants.edusystem.store'; }
            public function getTxtRecords(string $hostname): array { return []; }
        };

        $service1 = new DomainService($mockResolver1);
        $domain1 = $service1->addCustomDomain($this->schoolA, 'app.lahorecambridge.com');

        $this->assertTrue($service1->verifyDomain($domain1));
        $this->assertEquals(SchoolDomain::STATUS_VERIFIED, $domain1->fresh()->status);
        $this->assertEquals(SchoolDomain::SSL_PENDING, $domain1->fresh()->ssl_status);
        $this->assertNotNull($domain1->fresh()->verified_at);

        // 2. Future migration target: tenants.studentxces.com
        $mockResolver2 = new class implements DnsResolverInterface {
            public function getCnameRecord(string $hostname): ?string { return 'tenants.studentxces.com'; }
            public function getTxtRecords(string $hostname): array { return []; }
        };

        $service2 = new DomainService($mockResolver2);
        $domain2 = $service2->addCustomDomain($this->schoolB, 'portal.greenfield.com');

        $this->assertTrue($service2->verifyDomain($domain2));
        $this->assertEquals(SchoolDomain::STATUS_VERIFIED, $domain2->fresh()->status);
        $this->assertEquals(SchoolDomain::SSL_PENDING, $domain2->fresh()->ssl_status);
    }

    public function test_dns_verification_succeeds_with_valid_txt_challenge_evidence(): void
    {
        $service = app(DomainService::class);
        $domain = $service->addCustomDomain($this->schoolA, 'portal.lahorecambridge.com');

        $mockResolver = new class($domain->verification_token) implements DnsResolverInterface {
            public function __construct(private string $token) {}
            public function getCnameRecord(string $hostname): ?string { return null; }
            public function getTxtRecords(string $hostname): array { return [$this->token]; }
        };

        $serviceWithMock = new DomainService($mockResolver);
        $verified = $serviceWithMock->verifyDomain($domain);

        $this->assertTrue($verified);
        $this->assertEquals(SchoolDomain::STATUS_VERIFIED, $domain->fresh()->status);
        $this->assertEquals(SchoolDomain::SSL_PENDING, $domain->fresh()->ssl_status);
        $this->assertNotNull($domain->fresh()->verified_at);
    }

    public function test_bad_dns_verification_fails_closed(): void
    {
        $mockResolver = new class implements DnsResolverInterface {
            public function getCnameRecord(string $hostname): ?string { return 'wrong-cname.unauthorized.com'; }
            public function getTxtRecords(string $hostname): array { return []; }
        };

        $service = new DomainService($mockResolver);
        $domain = $service->addCustomDomain($this->schoolA, 'bad-dns.lahorecambridge.com');

        $verified = $service->verifyDomain($domain);

        $this->assertFalse($verified);
        $this->assertEquals(SchoolDomain::STATUS_FAILED, $domain->fresh()->status);
        $this->assertNull($domain->fresh()->verified_at);
    }

    public function test_host_resolves_exact_tenant_school(): void
    {
        $domain = $this->domainService->addCustomDomain($this->schoolA, 'app.lahorecambridge.com');
        $domain->update(['status' => SchoolDomain::STATUS_ACTIVE, 'verified_at' => now()]);

        $resolver = app(TenantDomainResolver::class);
        $resolvedSchool = $resolver->resolveFromHost('app.lahorecambridge.com');

        $this->assertNotNull($resolvedSchool);
        $this->assertEquals($this->schoolA->id, $resolvedSchool->id);
        $this->assertEquals('Lahore Cambridge School', $resolvedSchool->name);
    }

    public function test_unknown_host_fails_closed(): void
    {
        $resolver = app(TenantDomainResolver::class);
        $resolvedSchool = $resolver->resolveFromHost('unknown-random-school.com');

        $this->assertNull($resolvedSchool);
    }

    public function test_unverified_pending_host_does_not_resolve_tenant(): void
    {
        $domain = $this->domainService->addCustomDomain($this->schoolA, 'pending.lahorecambridge.com');
        $this->assertEquals(SchoolDomain::STATUS_PENDING, $domain->status);

        $resolver = app(TenantDomainResolver::class);
        $resolvedSchool = $resolver->resolveFromHost('pending.lahorecambridge.com');

        $this->assertNull($resolvedSchool);
    }

    public function test_disabled_host_does_not_resolve_tenant(): void
    {
        $domain = $this->domainService->addCustomDomain($this->schoolA, 'disabled.lahorecambridge.com');
        $domain->update(['status' => SchoolDomain::STATUS_DISABLED]);

        $resolver = app(TenantDomainResolver::class);
        $resolvedSchool = $resolver->resolveFromHost('disabled.lahorecambridge.com');

        $this->assertNull($resolvedSchool);
    }

    public function test_tenant_branded_login_resolves_from_host(): void
    {
        $domain = $this->domainService->addCustomDomain($this->schoolA, 'app.lahorecambridge.com');
        $domain->update(['status' => SchoolDomain::STATUS_ACTIVE, 'verified_at' => now()]);

        $response = $this->get('http://app.lahorecambridge.com/login');

        $response->assertStatus(200);

        $props = $response->viewData('page')['props'];

        $this->assertEquals('Lahore Cambridge School', $props['branding']['app_name']);
        $this->assertEquals('Lahore Cambridge School', $props['branding']['tenant_name']);
        $this->assertTrue($props['branding']['is_tenant_context']);
        $this->assertEquals($this->schoolA->id, $props['branding']['active_school_id']);
        $this->assertEquals('Lahore Cambridge School', $props['active_school']['name']);
    }

    public function test_school_a_user_can_login_on_school_a_host(): void
    {
        $domain = $this->domainService->addCustomDomain($this->schoolA, 'app.lahorecambridge.com');
        $domain->update(['status' => SchoolDomain::STATUS_ACTIVE, 'verified_at' => now()]);

        $response = $this->post('http://app.lahorecambridge.com/login', [
            'email'    => 'admin@lahorecambridge.com',
            'password' => 'Password123!',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->schoolAAdmin);
    }

    public function test_school_b_user_login_on_school_a_host_is_rejected(): void
    {
        $domain = $this->domainService->addCustomDomain($this->schoolA, 'app.lahorecambridge.com');
        $domain->update(['status' => SchoolDomain::STATUS_ACTIVE, 'verified_at' => now()]);

        $response = $this->post('http://app.lahorecambridge.com/login', [
            'email'    => 'admin@greenfield.test',
            'password' => 'Password123!',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_authenticated_school_b_user_cannot_access_school_a_host_routes(): void
    {
        $domain = $this->domainService->addCustomDomain($this->schoolA, 'app.lahorecambridge.com');
        $domain->update(['status' => SchoolDomain::STATUS_ACTIVE, 'verified_at' => now()]);

        // Authenticated user belonging to School B attempts to request School A's host
        $response = $this->actingAs($this->schoolBAdmin)
            ->get('http://app.lahorecambridge.com/school/reports/dashboard');

        $response->assertStatus(403);
    }

    public function test_super_admin_login_on_tenant_custom_host_is_rejected(): void
    {
        $domain = $this->domainService->addCustomDomain($this->schoolA, 'app.lahorecambridge.com');
        $domain->update(['status' => SchoolDomain::STATUS_ACTIVE, 'verified_at' => now()]);

        $response = $this->post('http://app.lahorecambridge.com/login', [
            'email'    => 'superadmin@test.com',
            'password' => 'Password123!',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_super_admin_global_routes_remain_global_and_accessible(): void
    {
        $this->actingAs($this->superAdmin);

        $response = $this->get(route('super-admin.dashboard'));
        $response->assertStatus(200);

        $props = $response->viewData('page')['props'];
        $this->assertEquals('StudentXces', $props['branding']['platform_name']);
        $this->assertNull($props['branding']['tenant_name']);
        $this->assertFalse($props['branding']['is_tenant_context']);
    }

    public function test_domains_index_get_request_is_strictly_read_only_and_creates_zero_rows(): void
    {
        $this->actingAs($this->schoolAAdmin);

        $initialCount = SchoolDomain::count();

        $response = $this->get(route('school.settings.domains.index'));
        $response->assertStatus(200);

        $this->assertEquals($initialCount, SchoolDomain::count());
    }

    public function test_exactly_one_primary_domain_per_school_with_concurrency_safety(): void
    {
        $defaultDomain = $this->domainService->generateDefaultDomain($this->schoolA);
        $this->assertTrue($defaultDomain->is_primary);

        $customDomain1 = $this->domainService->addCustomDomain($this->schoolA, 'app.lahorecambridge.com');
        $customDomain1->update(['status' => SchoolDomain::STATUS_VERIFIED]);

        // Make custom domain 1 primary
        $this->domainService->setPrimary($customDomain1);

        $this->assertTrue($customDomain1->fresh()->is_primary);
        $this->assertFalse($defaultDomain->fresh()->is_primary);

        // Delete custom domain 1 -> should safely fall back to default domain
        $this->domainService->deleteDomain($customDomain1);

        $this->assertDatabaseMissing('school_domains', ['id' => $customDomain1->id]);
        $this->assertTrue($defaultDomain->fresh()->is_primary);
    }

    public function test_custom_domain_unaffected_by_base_domain_config_change(): void
    {
        $customDomain = $this->domainService->addCustomDomain($this->schoolA, 'app.lahorecambridge.com');
        $customDomain->update(['status' => SchoolDomain::STATUS_VERIFIED]);

        // Simulate base domain migration in config: edusystem.store -> studentxces.com
        Config::set('tenancy.tenant_base_domain', 'studentxces.com');

        $newDefault = $this->domainService->generateDefaultDomain($this->schoolA);

        $this->assertEquals('lahore-cambridge-school.studentxces.com', $newDefault->hostname);
        $this->assertEquals('app.lahorecambridge.com', $customDomain->fresh()->hostname);
    }

    public function test_provision_default_domain_artisan_command(): void
    {
        // Dry-run (default) -> no DB records created
        $this->artisan('tenancy:provision-default-domain', [
            '--school' => $this->schoolA->id,
        ])->assertExitCode(0);

        $this->assertEquals(0, SchoolDomain::where('school_id', $this->schoolA->id)->count());

        // Execute -> creates default domain
        $this->artisan('tenancy:provision-default-domain', [
            '--school'  => $this->schoolA->id,
            '--execute' => true,
        ])->assertExitCode(0);

        $this->assertEquals(1, SchoolDomain::where('school_id', $this->schoolA->id)->count());
        $domain = SchoolDomain::where('school_id', $this->schoolA->id)->first();
        $this->assertEquals('lahore-cambridge-school.edusystem.store', $domain->hostname);
        $this->assertEquals(SchoolDomain::STATUS_VERIFIED, $domain->status);
        $this->assertEquals(SchoolDomain::SSL_PENDING, $domain->ssl_status);
    }
}
