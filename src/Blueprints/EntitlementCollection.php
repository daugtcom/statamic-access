<?php

namespace Daugt\Access\Blueprints;

use Daugt\Access\Entries\EntitlementEntry;
use Statamic\Entries\Collection;
use Statamic\Facades\Collection as CollectionFacade;

class EntitlementCollection
{
    public function __invoke(): Collection {
        $collection = CollectionFacade::make(EntitlementEntry::COLLECTION);
        $collection->entryClass(EntitlementEntry::class);
        $collection->title('daugt-access::entitlements.name');

        $collection->titleFormats(
            sprintf(
                "{%s:title} - {%s:name} ({%s:start} - {%s:end})",
                EntitlementEntry::TARGET,
                EntitlementEntry::USER,
                EntitlementEntry::VALIDITY,
                EntitlementEntry::VALIDITY
            )
        );

        $collection->requiresSlugs(false);

        return $collection;
    }
}
