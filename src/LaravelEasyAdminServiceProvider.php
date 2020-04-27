<?php
namespace Raysirsharp\LaravelEasyAdmin;

use Illuminate\Support\ServiceProvider;

class LaravelEasyAdminServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
        
        $this->loadMigrationsFrom(__DIR__.'/Migrations/3000_01_01_000000_add_easy_admin_to_users_table.php');
        
        $this->publishes([
            __DIR__.'/Assets' => public_path('raysirsharp/LaravelEasyAdmin'),
        ], 'public');
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->make('Raysirsharp\LaravelEasyAdmin\Controllers\AdminController');

        $this->loadViewsFrom(__DIR__.'/Views', 'easy-admin');
    }
}