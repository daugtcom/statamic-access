<?php

namespace Daugt\Access\Events;

use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Events\Event;

class EntitlementRevoked extends Event
{
    public function __construct(public EntryContract $entitlement)
    {
    }
}
