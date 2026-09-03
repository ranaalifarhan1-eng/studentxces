<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform Base Domain
    |--------------------------------------------------------------------------
    |
    | Base domain used for platform infrastructure.
    | Default is 'edusystem.store', migratable to 'studentxces.com'.
    |
    */
    'platform_base_domain' => env('PLATFORM_BASE_DOMAIN', 'edusystem.store'),

    /*
    |--------------------------------------------------------------------------
    | Tenant Base Domain
    |--------------------------------------------------------------------------
    |
    | Base domain used to generate default platform subdomains for tenants.
    | E.g. {slug}.edusystem.store
    |
    */
    'tenant_base_domain' => env('TENANT_BASE_DOMAIN', 'edusystem.store'),

    /*
    |--------------------------------------------------------------------------
    | Tenant CNAME Target
    |--------------------------------------------------------------------------
    |
    | The canonical CNAME destination that customer custom domains must point to.
    | E.g. app.lahorecambridge.com -> tenants.edusystem.store
    |
    */
    'cname_target' => env('TENANT_CNAME_TARGET', 'tenants.edusystem.store'),

    /*
    |--------------------------------------------------------------------------
    | Accepted Legacy CNAME Targets (Migration Support)
    |--------------------------------------------------------------------------
    |
    | Explicitly configured legacy CNAME targets accepted during domain verification.
    | Defaults to empty. During a base domain migration, previous targets can be listed here.
    | E.g. TENANT_LEGACY_CNAME_TARGETS=tenants.edusystem.store
    |
    */
    'legacy_cname_targets' => array_filter(array_map('trim', explode(',', env('TENANT_LEGACY_CNAME_TARGETS', '')))),

    /*
    |--------------------------------------------------------------------------
    | Allow Verified Domains in Non-Production (Testing/Local Override)
    |--------------------------------------------------------------------------
    |
    | When false (default for production), only domains with status=active AND
    | ssl_status=active are resolvable. When true (testing/local only),
    | domains with status=verified are also resolvable.
    |
    */
    'allow_verified_domains' => env('TENANCY_ALLOW_VERIFIED_DOMAINS', false),

    /*
    |--------------------------------------------------------------------------
    | Platform Admin Host
    |--------------------------------------------------------------------------
    |
    | The dedicated host for global platform administration.
    |
    */
    'platform_admin_host' => env('PLATFORM_ADMIN_HOST', 'admin.edusystem.store'),

    /*
    |--------------------------------------------------------------------------
    | Reserved Subdomains
    |--------------------------------------------------------------------------
    |
    | Subdomain labels under the platform base domain that cannot be claimed
    | by individual tenants.
    |
    */
    'reserved_subdomains' => [
        'www',
        'admin',
        'api',
        'app',
        'tenants',
        'mail',
        'smtp',
        'support',
        'status',
        'assets',
        'cdn',
        'static',
        'portal',
        'auth',
        'login',
        'billing',
        'docs',
        'dashboard',
        'root',
    ],

    /*
    |--------------------------------------------------------------------------
    | Local Development Hosts
    |--------------------------------------------------------------------------
    |
    | Hostnames treated as local development environments, exempt from strict
    | host-to-tenant resolution requirements.
    |
    */
    'development_hosts' => [
        'localhost',
        '127.0.0.1',
        '::1',
    ],

    /*
    |--------------------------------------------------------------------------
    | Protected Provisioning Hosts
    |--------------------------------------------------------------------------
    |
    | Hostnames that must never be provisioned or mutated by tenant automation.
    | Combines hardcoded platform hosts with configurable additions.
    |
    */
    'protected_hosts' => array_values(array_unique(array_filter(array_merge(
        [
            'console.edusystem.store',
            'tenants.edusystem.store',
            'admin.edusystem.store',
            'edusystem.store',
            'app.wamanager.io',
            'app.lahorecambridge.com',
            'app.academyofmodernsciences.com',
        ],
        array_map('trim', explode(',', env('DOMAIN_PROVISIONING_PROTECTED_HOSTS', '')))
    )))),

];

