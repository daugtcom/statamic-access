<?php

namespace Daugt\Access\Services;

use Carbon\Carbon;
use Daugt\Access\Entries\EntitlementEntry;
use Daugt\Access\Events\EntitlementGranted;
use Daugt\Access\Events\EntitlementRevoked;
use Illuminate\Support\Arr;
use Statamic\Contracts\Auth\User as StatamicUser;
use Statamic\Contracts\Entries\Entry as EntryContract;
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

}
