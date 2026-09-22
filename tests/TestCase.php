<?php

namespace OcGlobalTech\CashierFiuu\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use OcGlobalTech\CashierFiuu\Billable;
use OcGlobalTech\CashierFiuu\Cashier;
use OcGlobalTech\CashierFiuu\CashierServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Cashier::useCustomerModel(User::class);

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();
        });

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function getPackageProviders($app): array
    {
        return [CashierServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('cashier.merchant_id', 'ACME');
        $app['config']->set('cashier.verify_key', 'f5bb0c8de146c67b44babbf4e6584cc0');
        $app['config']->set('cashier.secret_key', 'top-secret-key');
        $app['config']->set('cashier.currency', 'MYR');
        $app['config']->set('cashier.currency_locale', 'en_MY');
    }

    protected function createUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Ali Muhammad',
            'email' => 'ali@example.com',
            'phone' => '0163331111',
        ], $attributes));
    }
}

class User extends Authenticatable
{
    use Billable;

    protected $table = 'users';

    protected $guarded = [];

    protected $casts = [
        'trial_ends_at' => 'datetime',
    ];
}
