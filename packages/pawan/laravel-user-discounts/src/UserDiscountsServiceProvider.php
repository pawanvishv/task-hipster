<?php

namespace Pawan\UserDiscounts;

use Illuminate\Support\ServiceProvider;
use Pawan\UserDiscounts\Services\DiscountService;
use Pawan\UserDiscounts\Contracts\DiscountServiceInterface;

class UserDiscountsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Merge package config
        $this->mergeConfigFrom(
            __DIR__ . '/Config/user-discounts.php',
            'user-discounts'
        );

        // Bind service interface to implementation
        $this->app->singleton(DiscountServiceInterface::class, DiscountService::class);
    }
    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/Config/user-discounts.php' => config_path('user-discounts.php'),
        ], 'user-discounts-config');

        // Publish migrations
        $this->publishes([
            __DIR__ . '/Database/Migrations/' => database_path('migrations'),
        ], 'user-discounts-migrations');

        // Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/Database/Migrations');
    }
}
