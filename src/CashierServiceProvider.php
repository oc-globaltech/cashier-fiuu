<?php

namespace OcGlobalTech\CashierFiuu;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use OcGlobalTech\CashierFiuu\Console\ChargeRenewalsCommand;
use OcGlobalTech\CashierFiuu\Console\CheckCommand;
use OcGlobalTech\CashierFiuu\Http\Middleware\Subscribed;

class CashierServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cashier.php', 'cashier');

        $this->app->singleton(Fiuu::class, fn () => new Fiuu);
    }

    public function boot(): void
    {
        $this->registerRoutes();
        $this->registerMiddleware();
        $this->registerMigrations();
        $this->registerPublishing();
        $this->registerCommands();
    }

    /**
     * Fiuu's webhooks are posted by Fiuu, not by a browser, so these routes are
     * registered without the web middleware group and never see a CSRF token.
     */
    protected function registerRoutes(): void
    {
        if (! Cashier::$registersRoutes) {
            return;
        }

        Route::group([], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        });
    }

    protected function registerMiddleware(): void
    {
        Route::aliasMiddleware('subscribed', Subscribed::class);
    }

    protected function registerMigrations(): void
    {
        if (Cashier::$runsMigrations && $this->app->runningInConsole()) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }

    protected function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/cashier.php' => $this->app->configPath('cashier.php'),
        ], 'cashier-fiuu-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
        ], 'cashier-fiuu-migrations');
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ChargeRenewalsCommand::class, CheckCommand::class]);
        }
    }
}
