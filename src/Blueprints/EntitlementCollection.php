<?php

namespace Daugt\Access\Blueprints;

use Statamic\Entries\Collection;
use Statamic\Facades\Collection as CollectionFacade;

class EntitlementCollection
{
    public function __invoke(): Collection {
        $collection = CollectionFacade::make(config('statamic.daugt-access.entitlements.collection'));

        $collection->titleFormats(
            "{%s:title} - {%s:name} ({%s:start} - {%s:end})",
            config('statamic.daugt-access.entitlements.fields.target'),
            config('statamic.daugt-access.entitlements.fields.user'),
            config('statamic.daugt-access.entitlements.fields.validity'),
            config('statamic.daugt-access.entitlements.fields.validity')
        );

        return $collection;
    }
}
