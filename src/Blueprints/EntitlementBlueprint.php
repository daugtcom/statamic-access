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
            EntitlementEntry::VALIDITY_START => [
                'type' => 'date',
                'time_enabled' => true,
                'display' => 'daugt-access::entitlements.validity_start',
                'instructions' => 'daugt-access::entitlements.validity_start_instructions',
            ],
            EntitlementEntry::VALIDITY_END => [
                'type' => 'date',
                'time_enabled' => true,
                'display' => 'daugt-access::entitlements.validity_end',
                'instructions' => 'daugt-access::entitlements.validity_end_instructions',
            ],
            EntitlementEntry::KEEP_ACCESSIBLE_AFTER_EXPIRY => [
                'type' => 'toggle',
                'default' => false,
                'display' => 'daugt-access::entitlements.keep_accessible_after_expiry',
                'instructions' => 'daugt-access::entitlements.keep_accessible_after_expiry_instructions',
            ],
            EntitlementEntry::KEEP_UNLOCKED_WHEN_ACTIVE => [
                'type' => 'toggle',
                'default' => false,
                'display' => 'daugt-access::entitlements.keep_unlocked_when_active',
                'instructions' => 'daugt-access::entitlements.keep_unlocked_when_active_instructions',
            ],
        ]);
    }
}
