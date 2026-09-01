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

];
