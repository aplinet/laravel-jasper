<?php

namespace JasperPHP;

use Illuminate\Support\ServiceProvider;

class JasperPHPServiceProvider extends ServiceProvider
{
    const SESSION_HASH = '_JasperPHP';

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('jasperphp', function ($app) {
            return new JasperPHP;
        });
    }

    /**
     * Boot the authentication services for the application.
     *
     * @return void
     */
    public function boot()
    {
    }
}
