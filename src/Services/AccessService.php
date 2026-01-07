<?php

namespace Daugt\Access\Services;

use Carbon\Carbon;
use Daugt\Access\Entries\EntitlementEntry;
use Daugt\Access\Events\EntitlementGranted;
use Daugt\Access\Events\EntitlementRevoked;
use Illuminate\Support\Arr;
use Statamic\Contracts\Auth\User as StatamicUser;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Taxonomies\Term as TermContract;
use Statamic\Facades\Collection as StatamicCollection;
use Statamic\Facades\Entry;

final class AccessService
{
    public function canAccess(?StatamicUser $user, EntryContract|string $target, ?\DateTimeInterface $at = null): bool
    {
        if (!$user) return false;
        if (!$this->entitlementsCollectionExists()) return false;

        $at = $at ? Carbon::instance($at) : now();
        $userId = (string) $user->id();
        $targetEntry = $this->resolveTargetEntry($target);
        if (!$targetEntry || !$this->isPublishedEntry($targetEntry)) return false;

        $targetId = (string) $targetEntry->id();

        return Entry::query()
            ->where('collection', EntitlementEntry::COLLECTION)
            ->whereStatus('published')
            ->where(EntitlementEntry::USER, $userId)
            ->where(EntitlementEntry::TARGET, $targetId)
            ->get()
            ->contains(fn (EntitlementEntry $entitlement) => $this->isValidAt(
                $entitlement->validityStart(),
                $entitlement->validityEnd(),
                $at,
                $entitlement->keepAccessibleAfterExpiry()
            ));
    }

    /**
     * Return all currently accessible target entries for a user.
     *
     * @return array<int, EntryContract>
     */
    public function accessibleTargets(
        StatamicUser $user,
        ?string $targetCollection = null,
        ?\DateTimeInterface $at = null
    ): array {
        if (!$this->entitlementsCollectionExists()) {
            return [];
        }

        $at = $at ? Carbon::instance($at) : now();
        $userId = (string) $user->id();

        $targetIds = Entry::query()
            ->where('collection', EntitlementEntry::COLLECTION)
            ->whereStatus('published')
            ->where(EntitlementEntry::USER, $userId)
            ->get()
            ->filter(fn (EntitlementEntry $entitlement) => $this->isValidAt(
                $entitlement->validityStart(),
                $entitlement->validityEnd(),
                $at,
                $entitlement->keepAccessibleAfterExpiry()
            ))
            ->map(fn (EntitlementEntry $entitlement) => $entitlement->get(EntitlementEntry::TARGET))
            ->filter() // remove null/empty
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();

        if ($targetIds->isEmpty()) {
            return [];
        }

        $targets = Entry::query()
            ->whereIn('id', $targetIds->all())
            ->when($targetCollection, fn ($q) => $q->where('collection', $targetCollection))
            ->whereStatus('published')
            ->get();

        // Preserve ordering from $targetIds (and implicitly de-dupe)
        $targetsById = $targets->keyBy(fn (EntryContract $e) => (string) $e->id());

        return $targetIds
            ->map(fn (string $id) => $targetsById->get($id))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Return access timeslots for a target entry.
     *
     * @return array{unrestricted: bool, slots: array<int, array{start: string|null, end: string|null}>}
     */
    public function accessTimeslots(
        StatamicUser|string $user,
        EntryContract|string $target,
        ?\DateTimeInterface $at = null
    ): array {
        if (!$this->entitlementsCollectionExists()) {
            return ['unrestricted' => false, 'slots' => []];
        }

        $targetEntry = $this->resolveTargetEntry($target);
        if (!$targetEntry || !$this->isPublishedEntry($targetEntry)) {
            return ['unrestricted' => false, 'slots' => []];
        }

        $at = $at ? Carbon::instance($at) : now();
        $userId = $this->resolveUserId($user);
        $targetId = (string) $targetEntry->id();

        $entitlements = Entry::query()
            ->where('collection', EntitlementEntry::COLLECTION)
            ->whereStatus('published')
            ->where(EntitlementEntry::USER, $userId)
            ->where(EntitlementEntry::TARGET, $targetId)
            ->get();

        $unrestricted = false;
        $slots = [];

        foreach ($entitlements as $entitlement) {
            $start = $this->parseDateLike($entitlement->validityStart());
            $end = $this->parseDateLike($entitlement->validityEnd());

            if (!$start && !$end) {
                return ['unrestricted' => true, 'slots' => []];
            }

            if ($entitlement->keepUnlockedWhenActive() && $this->isActiveAt($start, $end, $at)) {
                $unrestricted = true;
            }

            if (!$entitlement->keepAccessibleAfterExpiry() && $end && $end->lt($at)) {
                continue;
            }

            $slots[] = [
                'start' => $start ? $start->toIso8601String() : null,
                'end' => $end ? $end->toIso8601String() : null,
            ];
        }

        usort($slots, function (array $left, array $right) {
            $leftStart = $left['start'];
            $rightStart = $right['start'];

            if ($leftStart === $rightStart) {
                return 0;
            }

            if ($leftStart === null) {
                return -1;
            }

            if ($rightStart === null) {
                return 1;
            }

            return strcmp($leftStart, $rightStart);
        });

        return ['unrestricted' => $unrestricted, 'slots' => $slots];
    }

    /**
     * Return accessible series items for a parent entry (course/series).
     *
     * @return array<int, EntryContract>
     */
    public function accessibleSeriesItems(
        StatamicUser $user,
        EntryContract|string $series,
        string $itemsCollection,
        ?string $seriesField = null,
        ?string $taxonomy = null,
        ?\DateTimeInterface $at = null,
        string|TermContract|null $category = null
    ): array {
        if (! $this->entitlementsCollectionExists()) {
            return [];
        }

        $seriesEntry = $this->resolveTargetEntry($series);
        if (! $seriesEntry || ! $this->isPublishedEntry($seriesEntry)) {
            return [];
        }

        $at = $at ? Carbon::instance($at) : now();
        $seriesId = (string) $seriesEntry->id();
        $seriesHandle = $seriesEntry->collectionHandle();

        $seriesField ??= $seriesHandle;
        $taxonomy ??= $category instanceof TermContract
            ? $category->taxonomyHandle()
            : ($seriesHandle ? "{$seriesHandle}_categories" : null);

        if (! $seriesField) {
            return [];
        }

        $categorySlug = null;
        if ($category) {
            if (! $taxonomy) {
                return [];
            }

            $categorySlug = $this->normalizeTermSlug($category, $taxonomy);
            if (! $categorySlug) {
                return [];
            }
        }

        $items = Entry::query()
            ->where('collection', $itemsCollection)
            ->whereStatus('published')
            ->get();

        if ($items->isEmpty()) {
            return [];
        }

        $items = $items->filter(function (EntryContract $entry) use ($seriesField, $seriesId, $taxonomy, $categorySlug) {
            $seriesValue = $entry->get($seriesField);
            if (! $this->valueContainsId($seriesValue, $seriesId)) {
                return false;
            }

            if (! $categorySlug) {
                return true;
            }

            $terms = Arr::wrap($entry->get($taxonomy));
            return in_array($categorySlug, $terms, true);
        });

        if ($items->isEmpty()) {
            return [];
        }

        $timeslots = $this->accessTimeslots($user, $seriesEntry, $at);
        $slotItems = $timeslots['unrestricted']
            ? $items
            : $items->filter(fn (EntryContract $entry) => $this->isEntryDateInSlots($entry, $timeslots['slots']));

        $result = $slotItems->keyBy(fn (EntryContract $entry) => (string) $entry->id());

        return $result->values()->all();
    }

    public function grantEntitlement(
        StatamicUser|string $user,
        EntryContract|string $target,
        ?\DateTimeInterface $start = null,
        ?\DateTimeInterface $end = null,
        bool $keepAccessibleAfterExpiry = false,
        bool $keepUnlockedWhenActive = false,
        bool $published = true,
        ?string $id = null
    ): EntryContract {
        $this->ensureEntitlementsCollectionExists();

        $userId = $this->resolveUserId($user);
        $targetId = $target instanceof EntryContract ? (string) $target->id() : (string) $target;

        $data = [
            EntitlementEntry::USER => $userId,
            EntitlementEntry::TARGET => $targetId,
        ];

        if ($start) {
            $data[EntitlementEntry::VALIDITY_START] = Carbon::instance($start)->toDateTimeString();
        }

        if ($end) {
            $data[EntitlementEntry::VALIDITY_END] = Carbon::instance($end)->toDateTimeString();
        }

        $data[EntitlementEntry::KEEP_ACCESSIBLE_AFTER_EXPIRY] = $keepAccessibleAfterExpiry;
        $data[EntitlementEntry::KEEP_UNLOCKED_WHEN_ACTIVE] = $keepUnlockedWhenActive;

        $entitlement = Entry::make()->collection(EntitlementEntry::COLLECTION);
        $entitlement->data($data);
        $entitlement->published($published);

        if ($id) {
            $entitlement->id($id);
        }

        $entitlement->save();

        EntitlementGranted::dispatch($entitlement);

        return $entitlement;
    }

    public function revokeEntitlement(EntryContract|string $entitlement): bool
    {
        $entry = $entitlement instanceof EntryContract ? $entitlement : Entry::find($entitlement);

        if (!$entry || $entry->collectionHandle() !== EntitlementEntry::COLLECTION) {
            return false;
        }

        $entry->delete();
        EntitlementRevoked::dispatch($entry);

        return true;
    }

    public function revokeEntitlementsForUserTarget(StatamicUser|string $user, EntryContract|string $target): int
    {
        if (!$this->entitlementsCollectionExists()) {
            return 0;
        }

        $userId = $this->resolveUserId($user);
        $targetId = $target instanceof EntryContract ? (string) $target->id() : (string) $target;

        $entitlements = Entry::query()
            ->where('collection', EntitlementEntry::COLLECTION)
            ->where(EntitlementEntry::USER, $userId)
            ->where(EntitlementEntry::TARGET, $targetId)
            ->get();

        $entitlements->each(function (EntryContract $entitlement) {
            $entitlement->delete();
            EntitlementRevoked::dispatch($entitlement);
        });

        return $entitlements->count();
    }

    private function entitlementsCollectionExists(): bool
    {
        return StatamicCollection::find(EntitlementEntry::COLLECTION) !== null;
    }

    private function ensureEntitlementsCollectionExists(): void
    {
        if ($this->entitlementsCollectionExists()) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'Entitlements collection [%s] does not exist. Run the install command or create the collection.',
            EntitlementEntry::COLLECTION
        ));
    }

    private function resolveUserId(StatamicUser|string $user): string
    {
        if ($user instanceof StatamicUser) {
            return (string) $user->id();
        }

        return (string) $user;
    }

    private function resolveTargetEntry(EntryContract|string $target): ?EntryContract
    {
        return $target instanceof EntryContract ? $target : Entry::find($target);
    }

    private function isPublishedEntry(EntryContract $entry): bool
    {
        return method_exists($entry, 'published') ? (bool) $entry->published() : false;
    }

    private function isValidAt(
        mixed $startRaw,
        mixed $endRaw,
        Carbon $at,
        bool $keepAccessibleAfterExpiry = false
    ): bool
    {
        if (empty($startRaw) && empty($endRaw)) return true;

        $start = $this->parseDateLike($startRaw);
        $end = $this->parseDateLike($endRaw);

        if ($start && $at->lt($start)) return false;
        if (!$keepAccessibleAfterExpiry && $end && $at->gte($end)) return false;

        return true;
    }

    private function isActiveAt(?Carbon $start, ?Carbon $end, Carbon $at): bool
    {
        if ($start && $at->lt($start)) return false;
        if ($end && $at->gte($end)) return false;

        return true;
    }

    private function parseDateLike(mixed $value): ?Carbon
    {
        if (!$value) return null;

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_array($value)) {
            $date = Arr::get($value, 'date');
            $time = Arr::get($value, 'time');

            if (is_string($date) && is_string($time)) {
                return Carbon::parse($date . ' ' . $time);
            }

            $nested = $date ?? Arr::get($value, 'value');
            return $nested ? Carbon::parse($nested) : null;
        }

        if (is_string($value)) {
            return Carbon::parse($value);
        }

        return null;
    }

    /**
     * @param array<int, array{start: string|null, end: string|null}> $slots
     */
    private function isEntryDateInSlots(EntryContract $entry, array $slots): bool
    {
        $date = $entry->date();

        if (! $date) {
            return false;
        }

        foreach ($slots as $slot) {
            $start = isset($slot['start']) && $slot['start'] ? Carbon::parse($slot['start']) : null;
            $end = isset($slot['end']) && $slot['end'] ? Carbon::parse($slot['end']) : null;

            if ($start && $date->lt($start)) {
                continue;
            }

            if ($end && $date->gte($end)) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function normalizeTermSlug(string|TermContract|null $term, string $taxonomy): ?string
    {
        if (! $term) {
            return null;
        }

        if ($term instanceof TermContract) {
            return $term->slug();
        }

        if (! is_string($term) || $term === '') {
            return null;
        }

        if (str_contains($term, '::')) {
            $parts = explode('::', $term, 2);
            if (($parts[0] ?? '') !== $taxonomy) {
                return null;
            }

            return $parts[1] ?? null;
        }

        return $term;
    }

    private function valueContainsId(mixed $value, string $targetId): bool
    {
        if (! $value) {
            return false;
        }

        $values = Arr::wrap($value);

        foreach ($values as $item) {
            if (is_object($item) && method_exists($item, 'id')) {
                $item = $item->id();
            }

            if (is_array($item)) {
                $item = Arr::get($item, 'id', $item);
            }

            if ((string) $item === $targetId) {
                return true;
            }
        }

        return false;
    }
}
