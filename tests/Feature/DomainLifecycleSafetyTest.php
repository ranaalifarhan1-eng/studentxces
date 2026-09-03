<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\School;
use App\Models\SchoolDomain;
use App\Models\SchoolSubscription;
use App\Models\User;
use App\Services\DnsResolverInterface;
use App\Services\DomainService;
use App\Services\HttpsProbeInterface;
use App\Services\SchoolOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DomainLifecycleSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected User $adminUser;
    protected DomainService $domainService;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);

        $pkg = Package::firstOrCreate(
            ['slug' => 'test-standard-pkg'],
            [
                'name'          => 'Standard',
                'currency'      => 'PKR',
                'price_monthly' => 5000,
                'is_active'     => true,
                'is_internal'   => false,
            ]
        );

        $this->school = School::create([
            'name'     => 'Academy Of Modern Sciences',
            'slug'     => 'academy-of-modern-sciences',
            'status'   => 'active',
            'country'  => 'PK',
            'timezone' => 'Asia/Karachi',
        ]);

        SchoolSubscription::create([
            'school_id'   => $this->school->id,
            'package_id'  => $pkg->id,
            'start_date'  => now()->subDay(),
            'end_date'    => now()->addYear(),
            'status'      => 'active',
            'amount_paid' => 28500,
        ]);

        $this->adminUser = User::create([
            'school_id' => $this->school->id,
            'name'      => 'AMS Admin',
            'email'     => 'admin@academyofmodernsciences.com',
            'password'  => bcrypt('Secret123!'),
            'status'    => 'active',
        ]);
        $this->adminUser->assignRole('school-admin');

        $this->domainService = app(DomainService::class);
    }

    public function test_custom_domain_starts_pending_non_primary_with_pending_ssl(): void
    {
        $domain = $this->domainService->addCustomDomain($this->school, 'app.academyofmodernsciences.com');

        $this->assertEquals(SchoolDomain::STATUS_PENDING, $domain->status);
        $this->assertEquals(SchoolDomain::SSL_PENDING, $domain->ssl_status);
        $this->assertFalse($domain->is_primary);
        $this->assertNull($domain->verified_at);
        $this->assertNotNull($domain->verification_token);
    }

    public function test_verify_dns_only_marks_status_verified_and_does_not_falsely_activate(): void
    {
        $domain = $this->domainService->addCustomDomain($this->school, 'app.academyofmodernsciences.com');

        // Mock DNS CNAME resolver
        $this->app->bind(DnsResolverInterface::class, fn () => new class implements DnsResolverInterface {
            public function getCnameRecord(string $h): ?string { return 'tenants.edusystem.store'; }
            public function getTxtRecords(string $h): array { return []; }
        });

        $response = $this->actingAs($this->adminUser)->post(route('school.settings.domains.verify', $domain));
        $response->assertRedirect();
        $response->assertSessionHas('success', "DNS verification successful for 'app.academyofmodernsciences.com'. DNS is confirmed.");

        $domain->refresh();
        $this->assertEquals(SchoolDomain::STATUS_VERIFIED, $domain->status);
        $this->assertEquals(SchoolDomain::SSL_PENDING, $domain->ssl_status);
        $this->assertFalse($domain->is_primary);
        $this->assertNotNull($domain->verified_at);
    }

    public function test_unactivated_or_pending_ssl_domain_cannot_become_primary(): void
    {
        $domain = $this->domainService->addCustomDomain($this->school, 'app.academyofmodernsciences.com');

        // 1. Pending domain cannot become primary
        $this->expectException(\InvalidArgumentException::class);
        $this->domainService->setPrimary($domain);
    }

    public function test_verified_domain_with_pending_ssl_cannot_become_primary(): void
    {
        $domain = $this->domainService->addCustomDomain($this->school, 'app.academyofmodernsciences.com');
        $domain->update(['status' => SchoolDomain::STATUS_VERIFIED, 'ssl_status' => SchoolDomain::SSL_PENDING]);

        $this->expectException(\InvalidArgumentException::class);
        $this->domainService->setPrimary($domain);
    }

    public function test_activation_failure_preserves_verified_status_and_does_not_mark_active(): void
    {
        $domain = $this->domainService->addCustomDomain($this->school, 'app.academyofmodernsciences.com');
        $domain->update(['status' => SchoolDomain::STATUS_VERIFIED, 'ssl_status' => SchoolDomain::SSL_PENDING]);

        // Mock failing TLS probe
        $mockFailingProbe = new class implements HttpsProbeInterface {
            public function probe(string $h): array {
                return ['success' => false, 'message' => 'TLS connection refused'];
            }
        };

        $result = $this->domainService->activateDomain($domain, $mockFailingProbe);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('TLS connection refused', $result['message']);

        $domain->refresh();
        $this->assertEquals(SchoolDomain::STATUS_VERIFIED, $domain->status);
        $this->assertEquals(SchoolDomain::SSL_PENDING, $domain->ssl_status);
    }

    public function test_successful_infrastructure_activation_marks_status_active_and_ssl_active(): void
    {
        $domain = $this->domainService->addCustomDomain($this->school, 'app.academyofmodernsciences.com');
        $domain->update(['status' => SchoolDomain::STATUS_VERIFIED, 'ssl_status' => SchoolDomain::SSL_PENDING]);

        $this->app->bind(DnsResolverInterface::class, fn () => new class implements DnsResolverInterface {
            public function getCnameRecord(string $h): ?string { return 'tenants.edusystem.store'; }
            public function getTxtRecords(string $h): array { return []; }
        });

        $mockPassingProbe = new class implements HttpsProbeInterface {
            public function probe(string $h): array {
                return ['success' => true, 'message' => 'Valid TLS certificate', 'issuer' => "Let's Encrypt"];
            }
        };

        $result = $this->domainService->activateDomain($domain, $mockPassingProbe);
        $this->assertTrue($result['success']);

        $domain->refresh();
        $this->assertEquals(SchoolDomain::STATUS_ACTIVE, $domain->status);
        $this->assertEquals(SchoolDomain::SSL_ACTIVE, $domain->ssl_status);

        // Now that domain is active and SSL is active, it can safely become primary
        $this->domainService->setPrimary($domain);
        $this->assertTrue($domain->fresh()->is_primary);
    }

    public function test_existing_lahore_cambridge_active_domain_remains_unaffected(): void
    {
        $lcs = School::create([
            'name'     => 'Lahore Cambridge School',
            'slug'     => 'lahore-cambridge-school',
            'status'   => 'active',
            'country'  => 'PK',
            'timezone' => 'Asia/Karachi',
        ]);

        $lcsDomain = SchoolDomain::create([
            'school_id'   => $lcs->id,
            'hostname'    => 'app.lahorecambridge.com',
            'type'        => SchoolDomain::TYPE_CUSTOM,
            'status'      => SchoolDomain::STATUS_ACTIVE,
            'ssl_status'  => SchoolDomain::SSL_ACTIVE,
            'is_primary'  => true,
            'verified_at' => now(),
        ]);

        $this->assertEquals(SchoolDomain::STATUS_ACTIVE, $lcsDomain->status);
        $this->assertEquals(SchoolDomain::SSL_ACTIVE, $lcsDomain->ssl_status);
        $this->assertTrue($lcsDomain->is_primary);
    }
}
