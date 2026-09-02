<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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
        //
    }
}
