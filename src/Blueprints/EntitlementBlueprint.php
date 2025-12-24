<?php

namespace Daugt\Access\Blueprints;

use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Fields\Blueprint as StatamicBlueprint;

class EntitlementBlueprint
{
    public function __invoke(): StatamicBlueprint {
        return BlueprintFacade::makeFromFields([
            config('statamic.daugt-access.entitlements.fields.user') => ['type' => 'users', 'max_items' => 1],
            config('statamic.daugt-access.entitlements.fields.target') => ['type' => 'entries', 'max_items' => 1, 'collections' => config('statamic.daugt-access.entitlements.target_collections')],
            config('statamic.daugt-access.entitlements.fields.validity') => ['type' => 'date', 'mode' => 'range', 'time_enabled' => true],
            config('statamic.daugt-access.entitlements.fields.keep_unlocked_after_expiry') => ['type' => 'boolean', 'default' => false],
        ]);
    }
}
