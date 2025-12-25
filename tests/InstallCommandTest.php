<?php

namespace Daugt\Access\Tests;

use Daugt\Access\Entries\EntitlementEntry;
use Illuminate\Support\Facades\File;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Role;
use Statamic\Facades\UserGroup;

class InstallCommandTest extends TestCase
{
    public function test_install_command_creates_role_and_group(): void
    {
        $this->ensureBlueprintDirectory();

        $this->artisan('statamic:daugt-access:install')->assertExitCode(0);

        $role = Role::find('member');
        $this->assertNotNull($role);
        $this->assertTrue($role->hasPermission('access cp'));

        $group = UserGroup::find('members');
        $this->assertNotNull($group);
        $this->assertTrue($group->hasRole('member'));
    }

    public function test_install_command_creates_entitlements_blueprint(): void
    {
        config()->set('statamic.daugt-access.entitlements.target_collections', ['products', 'events']);

        $this->ensureBlueprintDirectory();

        $this->artisan('statamic:daugt-access:install')->assertExitCode(0);

        $blueprintHandle = 'collections/'.EntitlementEntry::COLLECTION.'/entitlement';

        $blueprint = Blueprint::find($blueprintHandle);

        $this->assertNotNull($blueprint);
        $this->assertTrue($blueprint->hasField(EntitlementEntry::USER));
        $this->assertTrue($blueprint->hasField(EntitlementEntry::TARGET));
        $this->assertTrue($blueprint->hasField(EntitlementEntry::VALIDITY));
        $this->assertTrue($blueprint->hasField(EntitlementEntry::KEEP_UNLOCKED_AFTER_EXPIRY));

        $userField = $blueprint->field(EntitlementEntry::USER);
        $targetField = $blueprint->field(EntitlementEntry::TARGET);
        $validityField = $blueprint->field(EntitlementEntry::VALIDITY);
        $keepField = $blueprint->field(EntitlementEntry::KEEP_UNLOCKED_AFTER_EXPIRY);

        $this->assertSame('users', $userField->type());
        $this->assertSame(1, $userField->get('max_items'));

        $this->assertSame('entries', $targetField->type());
        $this->assertSame(1, $targetField->get('max_items'));
        $this->assertSame(['products', 'events'], $targetField->get('collections'));

        $this->assertSame('date', $validityField->type());
        $this->assertSame('range', $validityField->get('mode'));
        $this->assertTrue($validityField->get('time_enabled'));
        $this->assertSame('boolean', $keepField->type());

        $collection = CollectionFacade::find(EntitlementEntry::COLLECTION);
        $this->assertNotNull($collection);
        $this->assertSame(EntitlementEntry::class, $collection->entryClass());
    }

    private function ensureBlueprintDirectory(): void
    {
        $blueprintsPath = config('statamic.system.blueprints_path');
        $collectionPath = $blueprintsPath . '/collections/' . EntitlementEntry::COLLECTION;

        if (! File::isDirectory($collectionPath)) {
            File::makeDirectory($collectionPath, 0755, true);
        }
    }
}
