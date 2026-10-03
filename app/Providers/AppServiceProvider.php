<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\TenantDomainResolver::class);
        $this->app->singleton(\App\Services\ActiveSchoolContext::class);
        $this->app->singleton(\App\Services\SchoolEntitlementResolver::class);

        $this->app->bind(
            \App\Services\DnsResolverInterface::class,
            \App\Services\SystemDnsResolver::class
        );

        $this->app->bind(
            \App\Services\HttpsProbeInterface::class,
            \App\Services\SystemHttpsProbe::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(function ($user, $ability) {
            return $user->hasRole('super-admin') ? true : null;
        });

        if ($this->app->environment('production') || str_starts_with((string) config('app.url'), 'https://')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }
    }
}

