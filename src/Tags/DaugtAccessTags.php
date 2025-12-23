<?php

namespace Daugt\Access\Tags;

use Daugt\Access\Services\AccessService;
use Statamic\Tags\Tags;

class DaugtAccessTags extends Tags
{
    public function __construct(private AccessService $access) {}

    /**
     * {{ daugt_access:can target="ENTRY_ID" }}
     * {{ daugt_access:can :target="id" }}
     * If target omitted, uses current entry id from context.
     */
    public function can(): bool
    {
        $user = auth()->user();
        if (!$user) return false;

        $targetId = $this->resolveTargetId();
        if (!$targetId) return false;

        return $this->access->canAccess($user, $targetId);
    }

    /**
     * {{ daugt_access:targets }}
     * Loops over all currently accessible entries.
     *
     * Optional:
     * {{ daugt_access:targets collection="pages" }}
     */
    public function targets(): array
    {
        $user = auth()->user();
        if (!$user) return [];

        $collection = $this->params->get('collection');

        return $this->access->accessibleTargets($user, $collection);
    }

    /**
     * {{ daugt_access:targets_by_collection }}
     * Returns grouped targets:
     * [
     *   { collection: "courses", items: [...] },
     *   ...
     * ]
     *
     * Optional:
     * {{ daugt_access:targets_by_collection collection="pages" }}
     */
    public function targetsByCollection(): array
    {
        $user = auth()->user();
        if (!$user) return [];

        $collection = $this->params->get('collection');
        $targets = $this->access->accessibleTargets($user, $collection);

        return collect($targets)
            ->groupBy(fn ($entry) => $entry->collectionHandle() ?? 'unknown')
            ->map(fn ($items, $handle) => ['collection' => $handle, 'items' => $items->values()->all()])
            ->values()
            ->all();
    }

    private function resolveTargetId(): ?string
    {
        $target = $this->params->get('target')
            ?? $this->context->get('id');

        if (!$target) return null;

        // Support Entry-like objects (in case Antlers gives you an entry)
        if (!is_string($target) && is_object($target) && method_exists($target, 'id')) {
            $target = $target->id();
        }

        $target = (string) $target;

        return $target !== '' ? $target : null;
    }
}
