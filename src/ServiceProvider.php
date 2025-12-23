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

    public function boot() {
        parent::boot();
        $this->registerServices();
    }
    public function bootAddon()
    {
        parent::bootAddon();
        $this->mergeConfigFrom(__DIR__.'/../config/access.php', 'statamic.daugt-access');
    }

    private function registerServices(): void {
        $this->app->singleton(AccessService::class, function () {
            $cfg = config('daugt_access.entitlements');

            return new AccessService(
                entitlementsCollection: $cfg['collection'],
                userField: $cfg['fields']['user'],
                targetField: $cfg['fields']['target'],
                validityField: $cfg['fields']['validity'],
            );
        });
    }
}
