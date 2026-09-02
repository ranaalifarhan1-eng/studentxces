<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\SchoolDomain;
use App\Models\User;
use App\Services\DnsResolverInterface;
use App\Services\DomainService;
use App\Services\HttpsProbeInterface;
use App\Services\TenantDomainResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DomainActivationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected User $adminUser;
    protected DomainService $domainService;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('tenancy.platform_base_domain', 'edusystem.store');
        Config::set('tenancy.tenant_base_domain', 'edusystem.store');
        Config::set('tenancy.cname_target', 'tenants.edusystem.store');
        Config::set('tenancy.legacy_cname_targets', []);
        Config::set('tenancy.allow_verified_domains', false);

        // Seed roles
        Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

        $this->school = School::create([
            'name'     => 'Lahore Cambridge School',
            'slug'     => 'lahore-cambridge-school',
            'status'   => 'active',
            'country'  => 'PK',
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

        $this->adminUser = User::create([
            'name'      => 'Shahzia Dar',
            'email'     => 'admin@lahorecambridge.com',
            'password'  => Hash::make('Password123!'),
            'school_id' => $this->school->id,
            'status'    => 'active',
        ]);
        $this->adminUser->assignRole('school-admin');

        $this->domainService = app(DomainService::class);
    }

    public function test_activation_command_dry_run_creates_zero_writes(): void
    {
        $domain = $this->domainService->addCustomDomain($this->school, 'app.lahorecambridge.com');
        $domain->update([
            'status'      => SchoolDomain::STATUS_VERIFIED,
            'ssl_status'  => SchoolDomain::SSL_PENDING,
            'verified_at' => now(),
        ]);

        // Mock DNS and TLS
        $this->app->bind(DnsResolverInterface::class, fn () => new class implements DnsResolverInterface {
            public function getCnameRecord(string $h): ?string { return 'tenants.edusystem.store'; }
            public function getTxtRecords(string $h): array { return []; }
        });

        $this->app->bind(HttpsProbeInterface::class, fn () => new class implements HttpsProbeInterface {
            public function probe(string $h): array {
                return ['success' => true, 'message' => 'Valid TLS certificate', 'issuer' => "Let's Encrypt"];
            }
        });

        $this->artisan('tenancy:activate-domain', [
            '--hostname' => 'app.lahorecambridge.com',
            '--dry-run'  => true,
        ])
        ->expectsOutputToContain('DRY-RUN PASSED')
        ->assertExitCode(0);

        $fresh = $domain->fresh();
        $this->assertEquals(SchoolDomain::STATUS_VERIFIED, $fresh->status);
        $this->assertEquals(SchoolDomain::SSL_PENDING, $fresh->ssl_status);
    }

    public function test_unknown_domain_rejected_for_activation(): void
    {
        $this->artisan('tenancy:activate-domain', [
            '--hostname' => 'unknown.myschool.com',
        ])
        ->expectsOutputToContain('was not found')
        ->assertExitCode(1);
    }

    public function test_pending_domain_rejected_for_activation(): void
    {
        $domain = $this->domainService->addCustomDomain($this->school, 'pending.lahorecambridge.com');
        $this->assertEquals(SchoolDomain::STATUS_PENDING, $domain->status);

        $this->artisan('tenancy:activate-domain', [
            '--hostname' => 'pending.lahorecambridge.com',
        ])
        ->expectsOutputToContain('Precondition Failed: Domain must be in \'verified\' or \'active\' state')
        ->assertExitCode(1);
    }

    public function test_disabled_domain_rejected_for_activation(): void
    {
        $domain = $this->domainService->addCustomDomain($this->school, 'disabled.lahorecambridge.com');
        $domain->update(['status' => SchoolDomain::STATUS_DISABLED]);

        $this->artisan('tenancy:activate-domain', [
            '--hostname' => 'disabled.lahorecambridge.com',
        ])
        ->expectsOutputToContain('Precondition Failed')
        ->assertExitCode(1);
    }

    public function test_dns_verification_failure_blocks_activation(): void
    {
        $domain = $this->domainService->addCustomDomain($this->school, 'app.lahorecambridge.com');
        $domain->update([
            'status'      => SchoolDomain::STATUS_VERIFIED,
            'ssl_status'  => SchoolDomain::SSL_PENDING,
            'verified_at' => now(),
        ]);

        // Mock DNS returning bad record
        $this->app->bind(DnsResolverInterface::class, fn () => new class implements DnsResolverInterface {
            public function getCnameRecord(string $h): ?string { return 'bad-cname.target.com'; }
            public function getTxtRecords(string $h): array { return []; }
        });

        $this->artisan('tenancy:activate-domain', [
            '--hostname' => 'app.lahorecambridge.com',
            '--execute'  => true,
        ])
        ->expectsOutputToContain('DNS Check Failed')
        ->assertExitCode(1);

        $this->assertEquals(SchoolDomain::STATUS_FAILED, $domain->fresh()->status);
    }

    public function test_invalid_tls_probe_blocks_activation(): void
    {
        $domain = $this->domainService->addCustomDomain($this->school, 'app.lahorecambridge.com');
        $domain->update([
            'status'      => SchoolDomain::STATUS_VERIFIED,
            'ssl_status'  => SchoolDomain::SSL_PENDING,
            'verified_at' => now(),
        ]);

        $this->app->bind(DnsResolverInterface::class, fn () => new class implements DnsResolverInterface {
            public function getCnameRecord(string $h): ?string { return 'tenants.edusystem.store'; }
            public function getTxtRecords(string $h): array { return []; }
        });

        // Mock failed TLS handshake
        $this->app->bind(HttpsProbeInterface::class, fn () => new class implements HttpsProbeInterface {
            public function probe(string $h): array {
                return ['success' => false, 'message' => 'TLS connection failed: Connection refused'];
            }
        });

        $this->artisan('tenancy:activate-domain', [
            '--hostname' => 'app.lahorecambridge.com',
            '--execute'  => true,
        ])
        ->expectsOutputToContain('TLS Probe Failed')
        ->assertExitCode(1);

        $this->assertEquals(SchoolDomain::STATUS_VERIFIED, $domain->fresh()->status);
        $this->assertEquals(SchoolDomain::SSL_PENDING, $domain->fresh()->ssl_status);
    }

    public function test_valid_dns_and_tls_probe_activates_verified_domain(): void
    {
        $defaultDomain = $this->domainService->generateDefaultDomain($this->school);
        $this->assertTrue($defaultDomain->is_primary);

        $customDomain = $this->domainService->addCustomDomain($this->school, 'app.lahorecambridge.com');
        $customDomain->update([
            'status'      => SchoolDomain::STATUS_VERIFIED,
            'ssl_status'  => SchoolDomain::SSL_PENDING,
            'verified_at' => now(),
        ]);

        $this->app->bind(DnsResolverInterface::class, fn () => new class implements DnsResolverInterface {
            public function getCnameRecord(string $h): ?string { return 'tenants.edusystem.store'; }
            public function getTxtRecords(string $h): array { return []; }
        });

        $this->app->bind(HttpsProbeInterface::class, fn () => new class implements HttpsProbeInterface {
            public function probe(string $h): array {
                return ['success' => true, 'message' => 'Valid TLS certificate', 'issuer' => "Let's Encrypt", 'valid_to' => '2027-01-01 00:00:00'];
            }
        });

        $this->artisan('tenancy:activate-domain', [
            '--hostname' => 'app.lahorecambridge.com',
            '--execute'  => true,
        ])
        ->expectsOutputToContain('SUCCESS: Domain [app.lahorecambridge.com] is now ACTIVE')
        ->assertExitCode(0);

        $fresh = $customDomain->fresh();
        $this->assertEquals(SchoolDomain::STATUS_ACTIVE, $fresh->status);
        $this->assertEquals(SchoolDomain::SSL_ACTIVE, $fresh->ssl_status);

        // Crucial: Activation does NOT automatically make it primary
        $this->assertFalse($fresh->is_primary);
        $this->assertTrue($defaultDomain->fresh()->is_primary);

        // Host resolution now resolves tenant
        $resolver = app(TenantDomainResolver::class);
        $resolved = $resolver->resolveFromHost('app.lahorecambridge.com');
        $this->assertNotNull($resolved);
        $this->assertEquals($this->school->id, $resolved->id);
    }

    public function test_explicit_primary_switching_after_activation(): void
    {
        $defaultDomain = $this->domainService->generateDefaultDomain($this->school);
        $customDomain = $this->domainService->addCustomDomain($this->school, 'app.lahorecambridge.com');
        $customDomain->update([
            'status'     => SchoolDomain::STATUS_ACTIVE,
            'ssl_status' => SchoolDomain::SSL_ACTIVE,
        ]);

        $this->domainService->setPrimary($customDomain);

        $this->assertTrue($customDomain->fresh()->is_primary);
        $this->assertFalse($defaultDomain->fresh()->is_primary);
    }

    public function test_deactivation_workflow_disables_domain_and_prevents_resolution(): void
    {
        $domain = $this->domainService->addCustomDomain($this->school, 'app.lahorecambridge.com');
        $domain->update([
            'status'     => SchoolDomain::STATUS_ACTIVE,
            'ssl_status' => SchoolDomain::SSL_ACTIVE,
        ]);

        $resolver = app(TenantDomainResolver::class);
        $this->assertNotNull($resolver->resolveFromHost('app.lahorecambridge.com'));

        // Dry-run deactivation
        $this->artisan('tenancy:deactivate-domain', [
            '--hostname' => 'app.lahorecambridge.com',
            '--dry-run'  => true,
        ])
        ->expectsOutputToContain('DRY-RUN PASSED')
        ->assertExitCode(0);

        $this->assertEquals(SchoolDomain::STATUS_ACTIVE, $domain->fresh()->status);

        // Execute deactivation
        $this->artisan('tenancy:deactivate-domain', [
            '--hostname' => 'app.lahorecambridge.com',
            '--execute'  => true,
        ])
        ->expectsOutputToContain('SUCCESS: Domain [app.lahorecambridge.com] is now DISABLED')
        ->assertExitCode(0);

        $this->assertEquals(SchoolDomain::STATUS_DISABLED, $domain->fresh()->status);
        $this->assertFalse($domain->fresh()->is_primary);

        // Immediately removed from tenant resolution
        $this->assertNull($resolver->resolveFromHost('app.lahorecambridge.com'));
    }

    public function test_platform_lifecycle_events_remain_hidden_from_tenant_audit(): void
    {
        $domain = $this->domainService->addCustomDomain($this->school, 'app.lahorecambridge.com');
        $domain->update([
            'status'      => SchoolDomain::STATUS_VERIFIED,
            'ssl_status'  => SchoolDomain::SSL_PENDING,
            'verified_at' => now(),
        ]);

        $this->app->bind(DnsResolverInterface::class, fn () => new class implements DnsResolverInterface {
            public function getCnameRecord(string $h): ?string { return 'tenants.edusystem.store'; }
            public function getTxtRecords(string $h): array { return []; }
        });

        $domainService = app(DomainService::class);

        $mockProbe = new class implements HttpsProbeInterface {
            public function probe(string $h): array {
                return ['success' => true, 'message' => 'Valid TLS', 'issuer' => 'Test CA'];
            }
        };

        $result = $domainService->activateDomain($domain, $mockProbe);
        $this->assertTrue($result['success']);

        // Check activity log has platform entry
        $platformLog = Activity::where('log_name', 'platform')
            ->where('event', null)
            ->orWhere('log_name', 'platform')
            ->latest()
            ->first();

        $this->assertNotNull($platformLog);
        $this->assertStringContainsString('Domain [app.lahorecambridge.com] activated', $platformLog->description);

        // Authenticated School Admin checks tenant-facing audit log
        $this->actingAs($this->adminUser);
        $response = $this->get('/school/reports/audit-log');
        $response->assertStatus(200);

        $props = $response->viewData('page')['props'];
        $activities = $props['activities']['data'] ?? $props['activities'] ?? [];

        // Ensure platform event is NOT leaked into tenant audit
        foreach ($activities as $act) {
            $this->assertNotEquals('platform', $act['log_name'] ?? '');
            $this->assertStringNotContainsString('activated for school', $act['description'] ?? '');
        }
    }

    public function test_health_endpoint_exposes_no_sensitive_data_and_returns_ok(): void
    {
        $response = $this->get('/up');
        $response->assertStatus(200);

        $content = $response->getContent();
        $this->assertStringNotContainsString('password', strtolower($content));
        $this->assertStringNotContainsString('database', strtolower($content));
        $this->assertStringNotContainsString('secret', strtolower($content));
        $this->assertStringNotContainsString('APP_KEY', $content);
    }
}
