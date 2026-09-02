<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\School;
use App\Models\SchoolDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LoginBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_generic_login_page_renders_successfully_with_platform_branding(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Login')
            ->has('branding', fn (Assert $branding) => $branding
                ->where('platform_name', 'StudentXces')
                ->where('app_name', 'StudentXces')
                ->where('tenant_name', null)
                ->where('is_tenant_context', false)
                ->etc()
            )
        );
    }

    public function test_platform_host_login_renders_platform_branding_without_crashing(): void
    {
        PlatformSetting::set('platform_name', 'StudentXces Platform');

        $response = $this->withHeaders([
            'Host' => 'console.edusystem.store',
        ])->get('/login');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Login')
            ->has('branding', fn (Assert $branding) => $branding
                ->where('platform_name', 'StudentXces Platform')
                ->where('app_name', 'StudentXces Platform')
                ->where('is_tenant_context', false)
                ->etc()
            )
        );
    }

    public function test_tenant_domain_login_renders_tenant_branding_with_safe_logo_fallback(): void
    {
        $school = School::create([
            'name'     => 'Lahore Cambridge School',
            'slug'     => 'lahore-cambridge-school',
            'currency' => 'PKR',
            'timezone' => 'Asia/Karachi',
            'language' => 'en',
            'status'   => 'active',
            'country'  => 'PK',
            'logo'     => null, // No logo uploaded
        ]);

        SchoolDomain::create([
            'school_id'   => $school->id,
            'hostname'    => 'app.lahorecambridge.com',
            'domain_type' => 'custom',
            'status'      => 'active',
            'ssl_status'  => 'active',
            'is_primary'  => true,
        ]);

        $response = $this->get('http://app.lahorecambridge.com/login');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Login')
            ->has('branding', fn (Assert $branding) => $branding
                ->where('tenant_name', 'Lahore Cambridge School')
                ->where('app_name', 'Lahore Cambridge School')
                ->where('is_tenant_context', true)
                ->where('logo_url', null)
                ->etc()
            )
        );
    }
}
