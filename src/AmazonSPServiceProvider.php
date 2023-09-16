<?php

namespace AntiHeroGuy\AmazonSP;

use Illuminate\Support\ServiceProvider;

class AmazonSPServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../config/amazon-sp.php' => config_path('amazon-sp.php'),
        ], 'amazon-sp');
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/amazon-sp.php',
            'amazon-sp'
        );
    }
}
