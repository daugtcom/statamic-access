<?php

namespace Daugt\Access\Tests;

use Daugt\Access\ServiceProvider;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $fixturesPath = __DIR__ . '/__fixtures__';

        $app['config']->set('statamic.system.blueprints_path', $fixturesPath . '/blueprints');
        $app['config']->set('statamic.users.repositories.file.paths.roles', $fixturesPath . '/users/roles.yaml');
        $app['config']->set('statamic.users.repositories.file.paths.groups', $fixturesPath . '/users/groups.yaml');
    }
}
