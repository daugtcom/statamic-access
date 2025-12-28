<?php

namespace Daugt\Access\Tests;

use Daugt\Access\Entries\EntitlementEntry;
use Daugt\Access\Services\AccessService;
use Illuminate\Support\Str;
use Statamic\Contracts\Auth\User as StatamicUser;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection as StatamicCollection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

class AccessServiceTest extends TestCase
{
    public function test_can_access_returns_true_for_published_entitlement_and_target(): void
    {
        $this->makeCollection(EntitlementEntry::COLLECTION);
        $this->makeCollection('products');

        $user = $this->makeUser('user-1');
        $target = $this->makeEntry('products', 'product-1', true);

        $validityStart = now()->subDay()->toDateTimeString();
        $validityEnd = now()->addDay()->toDateTimeString();

        $this->makeEntitlement('entitlement-1', $user->id(), $target->id(), true, $validityStart, $validityEnd);

        $service = new AccessService();
        $at = now();

        $this->assertTrue($service->canAccess($user, $target, $at));
        $this->assertTrue($service->canAccess($user, (string) $target->id(), $at));
    }

    public function test_can_access_returns_false_for_missing_or_invalid_entitlements(): void
    {
        $this->makeCollection(EntitlementEntry::COLLECTION);
        $this->makeCollection('products');

        $user = $this->makeUser('user-2');
        $target = $this->makeEntry('products', 'product-2', true);

        $service = new AccessService();
        $at = now();

        $this->assertFalse($service->canAccess($user, $target, $at));

        $this->makeEntitlement('entitlement-2', $user->id(), $target->id(), false, null, null);
        $this->assertFalse($service->canAccess($user, $target, $at));

        $this->makeEntitlement(
            'entitlement-3',
            $user->id(),
            $target->id(),
            true,
            now()->addDay()->toDateTimeString(),
            now()->addDays(2)->toDateTimeString()
        );
        $this->assertFalse($service->canAccess($user, $target, $at));
    }

    public function test_can_access_allows_keep_accessible_after_expiry(): void
    {
        $this->makeCollection(EntitlementEntry::COLLECTION);
        $this->makeCollection('products');

        $user = $this->makeUser('user-keep-1');
        $target = $this->makeEntry('products', 'product-keep-1', true);

        $this->makeEntitlement(
            'entitlement-keep-1',
            $user->id(),
            $target->id(),
            true,
            now()->subDays(10)->toDateTimeString(),
            now()->subDays(2)->toDateTimeString(),
            true
        );

        $service = new AccessService();

        $this->assertTrue($service->canAccess($user, $target, now()));
    }

    public function test_can_access_denies_keep_accessible_before_start(): void
    {
        $this->makeCollection(EntitlementEntry::COLLECTION);
        $this->makeCollection('products');

        $user = $this->makeUser('user-keep-2');
        $target = $this->makeEntry('products', 'product-keep-2', true);

        $this->makeEntitlement(
            'entitlement-keep-2',
            $user->id(),
            $target->id(),
            true,
            now()->addDay()->toDateTimeString(),
            now()->addDays(10)->toDateTimeString(),
            true
        );

        $service = new AccessService();

        $this->assertFalse($service->canAccess($user, $target, now()));
    }

    public function test_can_access_allows_open_ended_start_only(): void
    {
        $this->makeCollection(EntitlementEntry::COLLECTION);
        $this->makeCollection('products');

        $user = $this->makeUser('user-open-start');
        $target = $this->makeEntry('products', 'product-open-start', true);

        $this->makeEntitlement(
            'entitlement-open-start',
            $user->id(),
            $target->id(),
            true,
            now()->subDay()->toDateTimeString(),
            null
        );

        $service = new AccessService();

        $this->assertTrue($service->canAccess($user, $target, now()));
    }

    public function test_can_access_denies_open_ended_start_only_before_start(): void
    {
        $this->makeCollection(EntitlementEntry::COLLECTION);
        $this->makeCollection('products');

        $user = $this->makeUser('user-open-start-future');
        $target = $this->makeEntry('products', 'product-open-start-future', true);

        $this->makeEntitlement(
            'entitlement-open-start-future',
            $user->id(),
            $target->id(),
            true,
            now()->addDay()->toDateTimeString(),
            null
        );

        $service = new AccessService();

        $this->assertFalse($service->canAccess($user, $target, now()));
    }

    public function test_can_access_allows_open_ended_end_only_until_end(): void
    {
        $this->makeCollection(EntitlementEntry::COLLECTION);
        $this->makeCollection('products');

        $user = $this->makeUser('user-open-end');
        $target = $this->makeEntry('products', 'product-open-end', true);

        $end = now()->addDay()->toDateTimeString();

        $this->makeEntitlement(
            'entitlement-open-end',
            $user->id(),
            $target->id(),
            true,
            null,
            $end
        );

        $service = new AccessService();

        $this->assertTrue($service->canAccess($user, $target, now()));
        $this->assertFalse($service->canAccess($user, $target, now()->addDays(2)));
    }

    public function test_access_timeslots_returns_unrestricted_for_open_entitlement(): void
    {
        $this->makeCollection(EntitlementEntry::COLLECTION);
        $this->makeCollection('products');

        $user = $this->makeUser('user-timeslots-1');
        $target = $this->makeEntry('products', 'product-timeslots-1', true);

        $this->makeEntitlement('entitlement-timeslots-1', $user->id(), $target->id(), true, null, null);

        $service = new AccessService();
        $result = $service->accessTimeslots($user, $target, now());

        $this->assertTrue($result['unrestricted']);
        $this->assertSame([], $result['slots']);
    }

    public function test_access_timeslots_unrestricted_when_keep_unlocked_when_active(): void
    {
        $this->makeCollection(EntitlementEntry::COLLECTION);
        $this->makeCollection('products');

        $user = $this->makeUser('user-timeslots-2');
        $target = $this->makeEntry('products', 'product-timeslots-2', true);

        $start = now()->subDay();
        $end = now()->addDay();

        $this->makeEntitlement(
            'entitlement-timeslots-2',
            $user->id(),
            $target->id(),
            true,
            $start->toDateTimeString(),
            $end->toDateTimeString(),
            null,
            true
        );

        $service = new AccessService();
        $result = $service->accessTimeslots($user, $target, now());

        $this->assertTrue($result['unrestricted']);
        $this->assertCount(1, $result['slots']);
        $this->assertSame($start->toIso8601String(), $result['slots'][0]['start']);
        $this->assertSame($end->toIso8601String(), $result['slots'][0]['end']);
    }

    public function test_access_timeslots_excludes_expired_without_keep_accessible(): void
    {
        $this->makeCollection(EntitlementEntry::COLLECTION);
        $this->makeCollection('products');

        $user = $this->makeUser('user-timeslots-3');
        $target = $this->makeEntry('products', 'product-timeslots-3', true);

        $start = now()->subDays(5);
        $end = now()->subDays(2);

        $this->makeEntitlement(
            'entitlement-timeslots-3',
            $user->id(),
            $target->id(),
            true,
            $start->toDateTimeString(),
            $end->toDateTimeString()
        );

        $this->makeEntitlement(
            'entitlement-timeslots-4',
            $user->id(),
            $target->id(),
            true,
            $start->toDateTimeString(),
            $end->toDateTimeString(),
            true
        );

        $service = new AccessService();
        $result = $service->accessTimeslots($user, $target, now());

        $this->assertFalse($result['unrestricted']);
        $this->assertCount(1, $result['slots']);
        $this->assertSame($start->toIso8601String(), $result['slots'][0]['start']);
        $this->assertSame($end->toIso8601String(), $result['slots'][0]['end']);
    }

    public function test_can_access_returns_false_when_target_unpublished(): void
    {
        $this->makeCollection(EntitlementEntry::COLLECTION);
        $this->makeCollection('products');

        $user = $this->makeUser('user-3');
        $target = $this->makeEntry('products', 'product-3', false);

        $this->makeEntitlement('entitlement-4', $user->id(), $target->id(), true, null, null);

        $service = new AccessService();

        $this->assertFalse($service->canAccess($user, $target, now()));
    }

    public function test_accessible_targets_returns_only_published_valid_targets_and_respects_filter(): void
    {
        $this->makeCollection(EntitlementEntry::COLLECTION);
        $this->makeCollection('alpha');
        $this->makeCollection('beta');

        $user = $this->makeUser('user-4');

        $alpha = $this->makeEntry('alpha', 'alpha-1', true);
        $beta = $this->makeEntry('beta', 'beta-1', true);
        $unpublished = $this->makeEntry('alpha', 'alpha-2', false);
        $expired = $this->makeEntry('beta', 'beta-2', true);
        $keepExpired = $this->makeEntry('beta', 'beta-3', true);

        $validityStart = now()->subDay()->toDateTimeString();
        $validityEnd = now()->addDay()->toDateTimeString();

        $this->makeEntitlement('entitlement-5', $user->id(), $alpha->id(), true, $validityStart, $validityEnd);
        $this->makeEntitlement('entitlement-6', $user->id(), $beta->id(), true, $validityStart, $validityEnd);
        $this->makeEntitlement('entitlement-7', $user->id(), $unpublished->id(), true, $validityStart, $validityEnd);
        $this->makeEntitlement(
            'entitlement-8',
            $user->id(),
            $expired->id(),
            true,
            now()->subDays(3)->toDateTimeString(),
            now()->subDay()->toDateTimeString()
        );
        $this->makeEntitlement(
            'entitlement-keep-3',
            $user->id(),
            $keepExpired->id(),
            true,
            now()->subDays(6)->toDateTimeString(),
            now()->subDays(2)->toDateTimeString(),
            true
        );
        $this->makeEntitlement('entitlement-9', $user->id(), $alpha->id(), true, $validityStart, $validityEnd);
        $this->makeEntitlement('entitlement-10', $user->id(), $beta->id(), false, $validityStart, $validityEnd);

        $service = new AccessService();
        $targets = $service->accessibleTargets($user, null, now());
        $targetIds = collect($targets)->map(fn (EntryContract $entry) => (string) $entry->id())->all();

        $this->assertCount(3, $targets);
        $this->assertContains((string) $alpha->id(), $targetIds);
        $this->assertContains((string) $beta->id(), $targetIds);
        $this->assertContains((string) $keepExpired->id(), $targetIds);
        $this->assertNotContains((string) $unpublished->id(), $targetIds);
        $this->assertNotContains((string) $expired->id(), $targetIds);

        $filtered = $service->accessibleTargets($user, 'alpha', now());
        $filteredIds = collect($filtered)->map(fn (EntryContract $entry) => (string) $entry->id())->all();

        $this->assertCount(1, $filtered);
        $this->assertContains((string) $alpha->id(), $filteredIds);
        $this->assertNotContains((string) $beta->id(), $filteredIds);
    }

    private function makeCollection(string $handle): void
    {
        if (StatamicCollection::find($handle)) {
            return;
        }

        $collection = StatamicCollection::make($handle);

        if ($handle === EntitlementEntry::COLLECTION) {
            $collection->entryClass(EntitlementEntry::class);
        }

        $collection->save();
    }

    private function makeUser(string $id): StatamicUser
    {
        $user = User::make();
        $user->id($id);
        $user->email($id.'@example.com');
        $user->password('secret');
        $user->save();

        return $user;
    }

    private function makeEntry(string $collection, string $id, bool $published): EntryContract
    {
        $this->makeCollection($collection);

        $entry = Entry::make()->collection($collection);
        $entry->id($id);
        $entry->slug(Str::slug($id));
        $entry->data(['title' => Str::title(str_replace('-', ' ', $id))]);
        $entry->published($published);
        $entry->save();

        return $entry;
    }

    private function makeEntitlement(
        string $id,
        string $userId,
        string $targetId,
        bool $published,
        ?string $validityStart,
        ?string $validityEnd,
        ?bool $keepAccessibleAfterExpiry = null,
        ?bool $keepUnlockedWhenActive = null
    ): EntryContract {
        $data = [
            EntitlementEntry::USER => $userId,
            EntitlementEntry::TARGET => $targetId,
        ];

        if ($validityStart !== null) {
            $data[EntitlementEntry::VALIDITY_START] = $validityStart;
        }

        if ($validityEnd !== null) {
            $data[EntitlementEntry::VALIDITY_END] = $validityEnd;
        }

        if ($keepAccessibleAfterExpiry !== null) {
            $data[EntitlementEntry::KEEP_ACCESSIBLE_AFTER_EXPIRY] = $keepAccessibleAfterExpiry;
        }

        if ($keepUnlockedWhenActive !== null) {
            $data[EntitlementEntry::KEEP_UNLOCKED_WHEN_ACTIVE] = $keepUnlockedWhenActive;
        }

        $entry = Entry::make()->collection(EntitlementEntry::COLLECTION);
        $entry->id($id);
        $entry->slug(Str::slug($id));
        $entry->data($data);
        $entry->published($published);
        $entry->save();

        return $entry;
    }
}
