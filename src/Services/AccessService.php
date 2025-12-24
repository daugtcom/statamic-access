<?php

namespace Daugt\Access\Services;

use Carbon\Carbon;
use Daugt\Access\Events\EntitlementGranted;
use Daugt\Access\Events\EntitlementRevoked;
use Illuminate\Support\Arr;
use Statamic\Contracts\Auth\User as StatamicUser;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection as StatamicCollection;
use Statamic\Facades\Entry;

final class AccessService
{
    public function __construct(
        private readonly string $entitlementsCollection = 'entitlements',
        private readonly string $userField = 'user',
        private readonly string $targetField = 'target',
        private readonly string $validityField = 'validity',
    ) {}

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
            ->where('collection', $this->entitlementsCollection)
            ->whereStatus('published')
            ->where($this->userField, $userId)
            ->where($this->targetField, $targetId)
            ->get()
            ->contains(fn ($entitlement) => $this->isValidAt(
                $entitlement->get($this->validityField),
                $at,
                $this->shouldKeepUnlockedAfterExpiry($entitlement)
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
            ->where('collection', $this->entitlementsCollection)
            ->whereStatus('published')
            ->where($this->userField, $userId)
            ->get()
            ->filter(fn ($entitlement) => $this->isValidAt(
                $entitlement->get($this->validityField),
                $at,
                $this->shouldKeepUnlockedAfterExpiry($entitlement)
            ))
            ->map(fn ($entitlement) => $entitlement->get($this->targetField))
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

    public function grantEntitlement(
        StatamicUser|string $user,
        EntryContract|string $target,
        ?\DateTimeInterface $start = null,
        ?\DateTimeInterface $end = null,
        bool $keepUnlockedAfterExpiry = false,
        bool $published = true,
        ?string $id = null
    ): EntryContract {
        $this->ensureEntitlementsCollectionExists();

        $userId = $this->resolveUserId($user);
        $targetId = $target instanceof EntryContract ? (string) $target->id() : (string) $target;

        $data = [
            $this->userField => $userId,
            $this->targetField => $targetId,
        ];

        if ($start || $end) {
            $data[$this->validityField] = array_filter([
                'start' => $start ? Carbon::instance($start)->toDateTimeString() : null,
                'end' => $end ? Carbon::instance($end)->toDateTimeString() : null,
            ]);
        }

        if ($keepField = $this->keepUnlockedAfterExpiryField()) {
            $data[$keepField] = $keepUnlockedAfterExpiry;
        }

        $entitlement = Entry::make();
        $entitlement->collection($this->entitlementsCollection);
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

        if (!$entry || $entry->collectionHandle() !== $this->entitlementsCollection) {
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
            ->where('collection', $this->entitlementsCollection)
            ->where($this->userField, $userId)
            ->where($this->targetField, $targetId)
            ->get();

        $entitlements->each(function (EntryContract $entitlement) {
            $entitlement->delete();
            EntitlementRevoked::dispatch($entitlement);
        });

        return $entitlements->count();
    }

    private function entitlementsCollectionExists(): bool
    {
        return StatamicCollection::find($this->entitlementsCollection) !== null;
    }

    private function ensureEntitlementsCollectionExists(): void
    {
        if ($this->entitlementsCollectionExists()) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'Entitlements collection [%s] does not exist. Run the install command or create the collection.',
            $this->entitlementsCollection
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

    private function isValidAt(mixed $range, Carbon $at, bool $keepUnlockedAfterExpiry = false): bool
    {
        if (empty($range)) return true;

        [$start, $end] = $this->parseRange($range);

        if ($start && $at->lt($start)) return false;
        if (!$keepUnlockedAfterExpiry && $end && $at->gte($end)) return false;

        return true;
    }

    /**
     * @return array{0: Carbon|null, 1: Carbon|null}
     */
    private function parseRange(mixed $range): array
    {
        if (is_string($range)) {
            return [Carbon::parse($range), null];
        }
        if (!is_array($range)) {
            return [null, null];
        }

        $startRaw = Arr::get($range, 'start', Arr::get($range, 'from'));
        $endRaw   = Arr::get($range, 'end', Arr::get($range, 'to'));

        return [$this->parseDateLike($startRaw), $this->parseDateLike($endRaw)];
    }

    private function parseDateLike(mixed $value): ?Carbon
    {
        if (!$value) return null;

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_array($value)) {
            $nested = Arr::get($value, 'date') ?? Arr::get($value, 'value');
            return $nested ? Carbon::parse($nested) : null;
        }

        if (is_string($value)) {
            return Carbon::parse($value);
        }

        return null;
    }

    private function shouldKeepUnlockedAfterExpiry(EntryContract $entitlement): bool
    {
        $field = $this->keepUnlockedAfterExpiryField();

        if (!$field) {
            return false;
        }

        $value = $entitlement->get($field);

        if (is_bool($value)) return $value;

        if (is_int($value) || is_float($value)) {
            return (bool) $value;
        }

        if (is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return (bool) $value;
    }

    private function keepUnlockedAfterExpiryField(): ?string
    {
        $field = config(
            'statamic.daugt-access.entitlements.fields.keep_unlocked_after_expiry',
            'keepUnlockedAfterExpiry'
        );

        return $field ? (string) $field : null;
    }
}
