<?php

namespace Daugt\Access;

use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{

    protected $vite = [
        'input' => [
          'resources/js/addon.js',
          'resources/css/addon.css',
        ],
        'publicDirectory' => 'resources/dist',
    ];

    public function register() {
        parent::register();

        $this->mergeConfigFrom(
            __DIR__ . '/../config/statamic/daugt-access.php',
            'statamic.daugt-access'
        );

        $this->registerServices();

    }

    public function boot() {
        parent::boot();
    }

    public function bootAddon()
    {
        parent::bootAddon();
        $this->loadJsonTranslationsFrom(__DIR__ . '/../lang');
        $this->publishes([
            __DIR__ . '/../config/statamic/daugt-access.php' => config_path('statamic/daugt-access.php'),
        ], 'daugt-access-config');
    }

    private function registerServices(): void {
        $this->app->singleton(AccessService::class, fn () => new AccessService());
    }
}
