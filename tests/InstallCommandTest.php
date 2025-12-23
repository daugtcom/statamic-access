<?php

namespace Daugt\Access\Tests;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Role;
use Statamic\Facades\UserGroup;

class InstallCommandTest extends TestCase
{
    public function test_install_command_creates_role_and_group(): void
    {
        config()->set('statamic.daugt-access.members.role', 'access_member');
        config()->set('statamic.daugt-access.members.group', 'access_members');

        $this->ensureBlueprintDirectory();

        $this->artisan('statamic:daugt-access:install')->assertExitCode(0);

        $role = Role::find('access_member');
        $this->assertNotNull($role);
        $this->assertTrue($role->hasPermission('access cp'));

        $group = UserGroup::find('access_members');
        $this->assertNotNull($group);
        $this->assertTrue($group->hasRole('access_member'));
    }

    public function test_install_command_creates_entitlements_blueprint(): void
    {
        config()->set('statamic.daugt-access.entitlements.collection', 'access_entitlements');
        config()->set('statamic.daugt-access.entitlements.fields.user', 'access_user');
        config()->set('statamic.daugt-access.entitlements.fields.target', 'access_target');
        config()->set('statamic.daugt-access.entitlements.fields.validity', 'access_validity');
        config()->set('statamic.daugt-access.entitlements.target_collections', ['products', 'events']);

        $this->ensureBlueprintDirectory();

        $this->artisan('statamic:daugt-access:install')->assertExitCode(0);

        $collectionHandle = config('statamic.daugt-access.entitlements.collection');
        $blueprintHandle = sprintf(
            'collections/%s/%s',
            $collectionHandle,
            Str::singular($collectionHandle)
        );

        $blueprint = Blueprint::find($blueprintHandle);

        $this->assertNotNull($blueprint);
        $this->assertTrue($blueprint->hasField('access_user'));
        $this->assertTrue($blueprint->hasField('access_target'));
        $this->assertTrue($blueprint->hasField('access_validity'));

        $userField = $blueprint->field('access_user');
        $targetField = $blueprint->field('access_target');
        $validityField = $blueprint->field('access_validity');

        $this->assertSame('users', $userField->type());
        $this->assertSame(1, $userField->get('max_items'));

        $this->assertSame('entries', $targetField->type());
        $this->assertSame(1, $targetField->get('max_items'));
        $this->assertSame(['products', 'events'], $targetField->get('collections'));

        $this->assertSame('date', $validityField->type());
        $this->assertSame('range', $validityField->get('mode'));
        $this->assertTrue($validityField->get('time_enabled'));
    }

    private function ensureBlueprintDirectory(): void
    {
        $collectionHandle = config('statamic.daugt-access.entitlements.collection');
        $blueprintsPath = config('statamic.system.blueprints_path');
        $collectionPath = $blueprintsPath . '/collections/' . $collectionHandle;

        if (! File::isDirectory($collectionPath)) {
            File::makeDirectory($collectionPath, 0755, true);
        }
    }
}
