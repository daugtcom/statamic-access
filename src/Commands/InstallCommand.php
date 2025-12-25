<?php

namespace Daugt\Access\Commands;

use Daugt\Access\Blueprints\EntitlementBlueprint;
use Daugt\Access\Blueprints\EntitlementCollection;
use Daugt\Access\Console\AsciiArt;
use Daugt\Access\Entries\EntitlementEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Role;
use Statamic\Facades\UserGroup;

class InstallCommand extends Command {
    use RunsInPlease;

    protected $signature = 'statamic:daugt-access:install';

    protected $description = 'Installs Access Addon.';

    public function handle(EntitlementBlueprint $entitlementBlueprint, EntitlementCollection $entitlementCollection): void
    {
        $this->output->write((new AsciiArt())());

        $this->createBlueprints($entitlementCollection, $entitlementBlueprint);
        $this->createUserGroups();
    }


    private function createBlueprints(EntitlementCollection $entitlementCollection, EntitlementBlueprint $entitlementBlueprint): self {
        $collection = $entitlementCollection();
        $collection->save();

        $blueprint = $entitlementBlueprint();
        $blueprint->setHandle(sprintf('collections/%s/%s', $collection->handle(), Str::singular($collection->handle())));

        Blueprint::save($blueprint);

        $this->info("Blueprints created!");

        return $this;
    }

    private function createUserGroups(): self {
        $role = Role::make();
        $role->handle('member');
        $role->title(__('daugt-access::members.role'));
        $role->addPermission('access cp');
        $role->save();

        $group = UserGroup::make();
        $group->roles([$role->handle()]);
        $group->handle('members');
        $group->title(__('daugt-access::members.group'));
        $group->save();

        return $this;
    }

}
