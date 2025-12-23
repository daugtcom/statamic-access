<?php

namespace Daugtcom\Access\Tests;

use Daugtcom\Access\ServiceProvider;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;
}
