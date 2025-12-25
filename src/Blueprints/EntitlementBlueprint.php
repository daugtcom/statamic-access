<?php

namespace Daugt\Access\Blueprints;

use Daugt\Access\Entries\EntitlementEntry;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Fields\Blueprint as StatamicBlueprint;

class EntitlementBlueprint
{
    public function __invoke(): StatamicBlueprint {
        return BlueprintFacade::makeFromFields([
            EntitlementEntry::USER => [
                'type' => 'users',
                'max_items' => 1,
                'display' => 'daugt-access::entitlements.user',
            ],
            EntitlementEntry::TARGET => [
                'type' => 'entries',
                'max_items' => 1,
                'collections' => config('statamic.daugt-access.entitlements.target_collections'),
                'display' => 'daugt-access::entitlements.target',
            ],
            EntitlementEntry::VALIDITY => [
                'type' => 'date',
                'mode' => 'range',
                'time_enabled' => true,
                'display' => 'daugt-access::entitlements.validity',
            ],
            EntitlementEntry::KEEP_UNLOCKED_AFTER_EXPIRY => [
                'type' => 'toggle',
                'default' => false,
                'display' => 'daugt-access::entitlements.keep_unlocked_after_expiry',
            ],
        ]);
    }
}
