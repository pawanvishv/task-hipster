<?php

namespace Pawan\UserDiscounts\Tests;

use Illuminate\Support\Facades\Schema;
use Pawan\UserDiscounts\Tests\TestUser;
use Pawan\UserDiscounts\Models\Discount;
use Illuminate\Database\Schema\Blueprint;
use Orchestra\Testbench\TestCase as Orchestra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Pawan\UserDiscounts\UserDiscountsServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDatabase();
    }

    protected function getPackageProviders($app): array
    {
        return [UserDiscountsServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('auth.providers.users.model', TestUser::class);

        $this->setPackageConfig($app);
    }

    protected function setUpDatabase(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });

        $this->loadMigrationsFrom(__DIR__ . '/../src/Database/Migrations');
    }

    protected function setPackageConfig($app): void
    {
        $config = $app['config'];

        $config->set('user-discounts', [
            'stacking_method' => 'multiplicative',
            'stacking_order' => 'priority_asc',
            'max_percentage_cap' => 50.0,
            'rounding' => [
                'mode' => 'nearest',
                'precision' => 2,
            ],
            'enable_auditing' => true,
            'cache' => [
                'enabled' => true,
                'ttl' => 3600,
            ],
        ]);
    }

    protected function createUser(array $attributes = []): TestUser
    {
        return TestUser::create(array_merge([
            'name' => 'Test User',
            'email' => 'test' . mt_rand(1000, 9999) . '@example.com',
            'password' => bcrypt('password'),
        ], $attributes));
    }

    protected function createDiscount(array $attributes = []): Discount
    {
        return Discount::create(array_merge([
            'code' => 'TEST' . mt_rand(1000, 9999),
            'name' => 'Test Discount',
            'description' => 'A test discount',
            'type' => 'percentage',
            'value' => 10.00,
            'priority' => 0,
            'is_active' => true,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ], $attributes));
    }
}
