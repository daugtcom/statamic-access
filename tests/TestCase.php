<?php

namespace Daugt\Access\Tests;

use Daugt\Access\ServiceProvider;
use Statamic\Facades\CP\Nav;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected function setUp(): void
    {
        parent::setUp();

        // allows "clearCachedUrls" to be called during tests in the facade
        Nav::shouldReceive('clearCachedUrls')->zeroOrMoreTimes();
        $this->addToAssertionCount(-1);
    }

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $fixturesPath = __DIR__ . '/__fixtures__';

        $app['config']->set('statamic.system.blueprints_path', $fixturesPath . '/blueprints');
        $app['config']->set('statamic.users.repositories.file.paths.roles', $fixturesPath . '/users/roles.yaml');
        $app['config']->set('statamic.users.repositories.file.paths.groups', $fixturesPath . '/users/groups.yaml');
    }
}
