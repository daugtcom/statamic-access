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
                'display' => __('daugt-access::entitlements.user'),
            ],
            EntitlementEntry::TARGET => [
                'type' => 'entries',
                'max_items' => 1,
                'collections' => config('statamic.daugt-access.entitlements.target_collections'),
                'display' => __('daugt-access::entitlements.target'),
            ],
            EntitlementEntry::VALIDITY => [
                'type' => 'date',
                'mode' => 'range',
                'time_enabled' => true,
                'display' => __('daugt-access::entitlements.validity'),
            ],
            EntitlementEntry::KEEP_UNLOCKED_AFTER_EXPIRY => [
                'type' => 'boolean',
                'default' => false,
                'display' => __('daugt-access::entitlements.keep_unlocked_after_expiry'),
            ],
        ]);
    }
}
