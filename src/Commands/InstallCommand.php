<?php

namespace Daugt\Access\Commands;

use Daugt\Access\Blueprints\EntitlementBlueprint;
use Daugt\Access\Console\AsciiArt;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;

class InstallCommand extends Command {
    use RunsInPlease;

    protected $signature = 'statamic:daugt-access:install';

    protected $description = 'Installs Access Addon.';

    public function handle(EntitlementBlueprint $entitlementBlueprint): void
    {
        $this->output->write((new AsciiArt())());

        $this->createBlueprints($entitlementBlueprint);
    }


    public function createBlueprints(EntitlementBlueprint $entitlementBlueprint): self {
        $collectionName = config('statamic.daugt-access.entitlements.collection');
        $collection = Collection::make($collectionName);
        $collection->save();

        $blueprint = $entitlementBlueprint();
        $blueprint->setHandle(sprintf('collections/%s/%s', $collectionName, Str::singular($collectionName)));

        Blueprint::save($blueprint);

        $this->info("Blueprints created!");

        return $this;
    }

}
