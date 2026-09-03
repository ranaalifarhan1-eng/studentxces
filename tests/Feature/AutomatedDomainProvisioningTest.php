<?php

namespace Tests\Feature;

use App\Models\DomainProvisioningRequest;
use App\Models\Package;
use App\Models\School;
use App\Models\SchoolDomain;
use App\Models\SchoolSubscription;
use App\Models\User;
use App\Services\CertbotCommandBuilder;
use App\Services\DomainProvisioningService;
use App\Services\HttpsProbeInterface;
use App\Services\NginxTenantConfigGenerator;
use App\Services\TenantHostnameValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AutomatedDomainProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected User $superAdmin;
    protected User $schoolAdmin;
    protected SchoolDomain $verifiedDomain;
    protected DomainProvisioningService $provisioningService;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);

        $pkg = Package::firstOrCreate(
            ['slug' => 'test-pkg'],
            [
                'name'          => 'Pro',
                'currency'      => 'PKR',
                'price_monthly' => 5000,
                'is_active'     => true,
                'is_internal'   => false,
            ]
        );

        $this->school = School::create([
            'name'     => 'Beacon International School',
            'slug'     => 'beacon-international-school',
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
            'amount_paid' => 50000,
        ]);

        $this->superAdmin = User::create([
            'name'     => 'Global Super Admin',
            'email'    => 'superadmin@edusystem.store',
            'password' => bcrypt('Secret123!'),
            'status'   => 'active',
        ]);
        $this->superAdmin->assignRole('super-admin');

        $this->schoolAdmin = User::create([
            'school_id' => $this->school->id,
            'name'      => 'Beacon Admin',
            'email'     => 'admin@beaconinternational.edu.pk',
            'password'  => bcrypt('Secret123!'),
            'status'    => 'active',
        ]);
        $this->schoolAdmin->assignRole('school-admin');

        $this->verifiedDomain = SchoolDomain::create([
            'school_id'          => $this->school->id,
            'hostname'           => 'app.beaconinternational.edu.pk',
            'type'               => SchoolDomain::TYPE_CUSTOM,
            'status'             => SchoolDomain::STATUS_VERIFIED,
            'ssl_status'         => SchoolDomain::SSL_PENDING,
            'is_primary'         => false,
            'verification_token' => 'token_123',
            'verified_at'        => now(),
        ]);

        $this->provisioningService = app(DomainProvisioningService::class);
    }

    public function test_school_admin_cannot_activate_infrastructure(): void
    {
        $response = $this->actingAs($this->schoolAdmin)
            ->post(route('school.settings.domains.activate', $this->verifiedDomain));

        $response->assertStatus(403);
        $this->assertDatabaseMissing('domain_provisioning_requests', [
            'school_domain_id' => $this->verifiedDomain->id,
        ]);
    }

    public function test_super_admin_can_queue_verified_domain_provisioning(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->withSession(['active_school_id' => $this->school->id])
            ->post(route('school.settings.domains.activate', $this->verifiedDomain));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('domain_provisioning_requests', [
            'school_domain_id' => $this->verifiedDomain->id,
            'requested_by'     => $this->superAdmin->id,
            'status'           => DomainProvisioningRequest::STATUS_QUEUED,
            'attempt_count'    => 0,
        ]);
    }

    public function test_pending_dns_domain_cannot_queue_activation(): void
    {
        $pendingDomain = SchoolDomain::create([
            'school_id'   => $this->school->id,
            'hostname'    => 'portal.beaconinternational.edu.pk',
            'type'        => SchoolDomain::TYPE_CUSTOM,
            'status'      => SchoolDomain::STATUS_PENDING,
            'ssl_status'  => SchoolDomain::SSL_PENDING,
            'is_primary'  => false,
        ]);

        $result = $this->provisioningService->requestProvisioning($pendingDomain, $this->superAdmin);
        $this->assertFalse($result['success']);
        $this->assertEquals('invalid_precondition', $result['code']);
    }

    public function test_already_active_domain_returns_noop(): void
    {
        $this->verifiedDomain->update([
            'status'     => SchoolDomain::STATUS_ACTIVE,
            'ssl_status' => SchoolDomain::SSL_ACTIVE,
        ]);

        $result = $this->provisioningService->requestProvisioning($this->verifiedDomain, $this->superAdmin);
        $this->assertTrue($result['success']);
        $this->assertEquals('already_active', $result['code']);
        $this->assertDatabaseMissing('domain_provisioning_requests', [
            'school_domain_id' => $this->verifiedDomain->id,
        ]);
    }

    public function test_duplicate_queued_or_running_request_is_blocked(): void
    {
        $res1 = $this->provisioningService->requestProvisioning($this->verifiedDomain, $this->superAdmin);
        $this->assertTrue($res1['success']);

        // Attempt second request for the same domain
        $res2 = $this->provisioningService->requestProvisioning($this->verifiedDomain, $this->superAdmin);
        $this->assertFalse($res2['success']);
        $this->assertEquals('request_in_progress', $res2['code']);

        $this->assertEquals(1, DomainProvisioningRequest::where('school_domain_id', $this->verifiedDomain->id)->count());
    }

    public function test_hostname_injection_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TenantHostnameValidator::validate('app.school.com; rm -rf /');
    }

    public function test_protected_hosts_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TenantHostnameValidator::validate('console.edusystem.store');
    }

    public function test_configured_protected_tenant_baselines_are_rejected(): void
    {
        Config::set('tenancy.protected_hosts', [
            'console.edusystem.store',
            'app.lahorecambridge.com',
            'app.academyofmodernsciences.com',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        TenantHostnameValidator::validate('app.lahorecambridge.com');
    }

    public function test_unrelated_future_tenant_domain_is_allowed(): void
    {
        Config::set('tenancy.protected_hosts', [
            'console.edusystem.store',
            'app.lahorecambridge.com',
        ]);

        $normalized = TenantHostnameValidator::validate('app.newschoolsystem.edu.pk');
        $this->assertEquals('app.newschoolsystem.edu.pk', $normalized);
    }

    public function test_inactive_school_domain_is_rejected(): void
    {
        $this->school->update(['status' => 'suspended']);

        $result = $this->provisioningService->requestProvisioning($this->verifiedDomain, $this->superAdmin);
        $this->assertFalse($result['success']);
        $this->assertEquals('invalid_precondition', $result['code']);
    }

    public function test_claim_next_atomically_claims_queued_request(): void
    {
        $this->provisioningService->requestProvisioning($this->verifiedDomain, $this->superAdmin);

        $claimed = $this->provisioningService->claimNextRequest();
        $this->assertNotNull($claimed);
        $this->assertEquals(DomainProvisioningRequest::STATUS_RUNNING, $claimed->status);
        $this->assertEquals(1, $claimed->attempt_count);
        $this->assertNotNull($claimed->started_at);
        $this->assertEquals('app.beaconinternational.edu.pk', $claimed->schoolDomain->hostname);

        // Second claim attempt should find null
        $secondClaim = $this->provisioningService->claimNextRequest();
        $this->assertNull($secondClaim);
    }

    public function test_stale_running_request_becomes_provisioning_timeout(): void
    {
        $this->provisioningService->requestProvisioning($this->verifiedDomain, $this->superAdmin);
        $req = $this->provisioningService->claimNextRequest();

        // Simulate request started 15 minutes ago (exceeding 10m timeout)
        $req->update(['started_at' => now()->subMinutes(15)]);

        $recovered = $this->provisioningService->recoverStaleRequests(10);
        $this->assertEquals(1, $recovered);

        $freshReq = $req->fresh();
        $this->assertEquals(DomainProvisioningRequest::STATUS_FAILED, $freshReq->status);
        $this->assertEquals(DomainProvisioningRequest::ERROR_PROVISIONING_TIMEOUT, $freshReq->safe_error_code);
        $this->assertEquals(SchoolDomain::SSL_FAILED, $this->verifiedDomain->fresh()->ssl_status);
        $this->assertEquals(SchoolDomain::STATUS_VERIFIED, $this->verifiedDomain->fresh()->status);
    }

    public function test_stale_recovery_command_is_idempotent(): void
    {
        $this->artisan('tenancy:provisioning:recover-stale')
            ->assertExitCode(0)
            ->expectsOutputToContain('Zero stale requests found.');
    }

    public function test_retry_cooldown_is_enforced(): void
    {
        Config::set('tenancy.provisioning.retry_cooldown_minutes', 5);

        $this->provisioningService->requestProvisioning($this->verifiedDomain, $this->superAdmin);
        $req = $this->provisioningService->claimNextRequest();

        // Mark failed 1 minute ago (within 5m cooldown)
        $this->provisioningService->markFailed($req->id, $this->verifiedDomain->id, 'certificate_failed');
        $req->fresh()->update(['completed_at' => now()->subMinute()]);

        // Attempt retry during cooldown
        $retryResult = $this->provisioningService->requestProvisioning($this->verifiedDomain, $this->superAdmin);
        $this->assertFalse($retryResult['success']);
        $this->assertEquals('cooldown_active', $retryResult['code']);

        // Advance time past cooldown (6 minutes ago)
        $req->fresh()->update(['completed_at' => now()->subMinutes(6)]);

        $retryAllowed = $this->provisioningService->requestProvisioning($this->verifiedDomain, $this->superAdmin);
        $this->assertTrue($retryAllowed['success']);
        $this->assertEquals('queued', $retryAllowed['code']);
    }

    public function test_max_attempts_is_enforced(): void
    {
        Config::set('tenancy.provisioning.max_attempts', 3);

        $req = DomainProvisioningRequest::create([
            'school_domain_id' => $this->verifiedDomain->id,
            'requested_by'     => $this->superAdmin->id,
            'action'           => DomainProvisioningRequest::ACTION_PROVISION,
            'status'           => DomainProvisioningRequest::STATUS_FAILED,
            'attempt_count'    => 3,
            'safe_error_code'  => 'certificate_failed',
            'completed_at'     => now()->subHours(1),
        ]);

        $result = $this->provisioningService->requestProvisioning($this->verifiedDomain, $this->superAdmin);
        $this->assertFalse($result['success']);
        $this->assertEquals('max_attempts_reached', $result['code']);
    }

    public function test_mismatched_request_and_domain_id_callback_is_rejected(): void
    {
        $this->provisioningService->requestProvisioning($this->verifiedDomain, $this->superAdmin);
        $req = $this->provisioningService->claimNextRequest();

        $mockPassingProbe = new class implements HttpsProbeInterface {
            public function probe(string $h): array { return ['success' => true, 'message' => 'OK']; }
        };

        // Pass invalid domain ID 9999
        $result = $this->provisioningService->markSuccess($req->id, 9999, $mockPassingProbe);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('does not match domain ID', $result['message']);
    }

    public function test_mark_success_revalidates_tls_and_activates_domain(): void
    {
        $this->provisioningService->requestProvisioning($this->verifiedDomain, $this->superAdmin);
        $req = $this->provisioningService->claimNextRequest();

        $mockPassingProbe = new class implements HttpsProbeInterface {
            public function probe(string $h): array {
                return ['success' => true, 'message' => 'Valid Let\'s Encrypt certificate'];
            }
        };

        $this->app->instance(HttpsProbeInterface::class, $mockPassingProbe);

        $this->artisan("tenancy:provisioning:mark-success --request-id={$req->id} --domain-id={$this->verifiedDomain->id}")
            ->assertExitCode(0);

        $this->assertEquals(SchoolDomain::STATUS_ACTIVE, $this->verifiedDomain->fresh()->status);
        $this->assertEquals(SchoolDomain::SSL_ACTIVE, $this->verifiedDomain->fresh()->ssl_status);
        $this->assertEquals(DomainProvisioningRequest::STATUS_SUCCEEDED, $req->fresh()->status);
        $this->assertNotNull($req->fresh()->completed_at);
    }

    public function test_re_entrant_success_after_infrastructure_ready(): void
    {
        $this->provisioningService->requestProvisioning($this->verifiedDomain, $this->superAdmin);
        $req = $this->provisioningService->claimNextRequest();

        $mockPassingProbe = new class implements HttpsProbeInterface {
            public function probe(string $h): array { return ['success' => true, 'message' => 'OK']; }
        };

        $this->provisioningService->markSuccess($req->id, $this->verifiedDomain->id, $mockPassingProbe);

        // Re-invoking markSuccess on already succeeded request is idempotent
        $res = $this->provisioningService->markSuccess($req->id, $this->verifiedDomain->id, $mockPassingProbe);
        $this->assertTrue($res['success']);
        $this->assertStringContainsString('already verified and active', $res['message']);
    }

    public function test_failed_callback_does_not_mark_domain_active_and_records_safe_error(): void
    {
        $this->provisioningService->requestProvisioning($this->verifiedDomain, $this->superAdmin);
        $req = $this->provisioningService->claimNextRequest();

        $this->artisan("tenancy:provisioning:mark-failed --request-id={$req->id} --domain-id={$this->verifiedDomain->id} --error-code=certificate_failed")
            ->assertExitCode(0);

        $freshDomain = $this->verifiedDomain->fresh();
        $this->assertEquals(SchoolDomain::STATUS_VERIFIED, $freshDomain->status);
        $this->assertEquals(SchoolDomain::SSL_FAILED, $freshDomain->ssl_status);

        $freshReq = $req->fresh();
        $this->assertEquals(DomainProvisioningRequest::STATUS_FAILED, $freshReq->status);
        $this->assertEquals('certificate_failed', $freshReq->safe_error_code);
    }

    public function test_generated_nginx_http_and_https_configs(): void
    {
        $httpConfig = NginxTenantConfigGenerator::generateHttpConfig('app.beaconinternational.edu.pk');
        $this->assertStringContainsString('listen 80;', $httpConfig);
        $this->assertStringContainsString('server_name app.beaconinternational.edu.pk;', $httpConfig);
        $this->assertStringContainsString('location /.well-known/acme-challenge/ {', $httpConfig);

        $httpsConfig = NginxTenantConfigGenerator::generateHttpsConfig(10, 'app.beaconinternational.edu.pk');
        $this->assertStringContainsString('listen 443 ssl;', $httpsConfig);
        $this->assertStringContainsString('ssl_certificate /etc/letsencrypt/live/studentxces-tenant-10/fullchain.pem;', $httpsConfig);
        $this->assertStringContainsString('proxy_pass http://127.0.0.1:8082;', $httpsConfig);
    }

    public function test_certbot_command_argv_builder_has_no_shell_interpolation(): void
    {
        $argv = CertbotCommandBuilder::buildIssuanceArgv(5, 'app.beaconinternational.edu.pk');
        $this->assertIsArray($argv);
        $this->assertEquals([
            'certbot',
            'certonly',
            '--webroot',
            '-w',
            '/var/www/html',
            '-d',
            'app.beaconinternational.edu.pk',
            '--cert-name',
            'studentxces-tenant-5',
            '--non-interactive',
            '--agree-tos',
        ], $argv);
    }

    public function test_subscription_suspended_does_not_deprovision_domain(): void
    {
        // When subscription is suspended, domain should remain active and resolvable
        $this->verifiedDomain->update([
            'status'     => SchoolDomain::STATUS_ACTIVE,
            'ssl_status' => SchoolDomain::SSL_ACTIVE,
        ]);

        $sub = SchoolSubscription::where('school_id', $this->school->id)->first();
        $sub->update(['status' => 'suspended']);

        $this->assertTrue($this->verifiedDomain->fresh()->isActive());
        $this->assertTrue($this->verifiedDomain->fresh()->isResolvable());
    }
}
