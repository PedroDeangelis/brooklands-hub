<?php

namespace App\Providers;

use App\BusinessCentral\AccessTokenProvider;
use App\BusinessCentral\BusinessCentralClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AccessTokenProvider::class);
        $this->app->singleton(BusinessCentralClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
