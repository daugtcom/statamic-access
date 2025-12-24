<?php

namespace Daugt\Access\Tests;

use Daugt\Access\Events\EntitlementGranted;
use Daugt\Access\Events\EntitlementRevoked;
use Daugt\Access\Services\AccessService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Statamic\Contracts\Auth\User as StatamicUser;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection as StatamicCollection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

class EntitlementApiTest extends TestCase
{
    public function test_grant_entitlement_creates_entry_and_dispatches_event(): void
    {
        Event::fake([EntitlementGranted::class]);

        $this->makeCollection('entitlements');
        $this->makeCollection('products');

        $user = $this->makeUser('api-user-1');
        $target = $this->makeEntry('products', 'api-product-1', true);

        $start = now()->subDay();
        $end = now()->addDay();

        $service = new AccessService();
        $entitlement = $service->grantEntitlement(
            $user,
            $target,
            $start,
            $end,
            true,
            true
        );

        $this->assertSame('entitlements', $entitlement->collectionHandle());
        $this->assertSame($user->id(), $entitlement->get('user'));
        $this->assertSame($target->id(), $entitlement->get('target'));
        $this->assertTrue((bool) $entitlement->get($this->keepUnlockedAfterExpiryField()));
        $this->assertTrue($entitlement->published());

        $range = $entitlement->get('validity');
        $this->assertIsArray($range);
        $this->assertSame($start->toDateTimeString(), $range['start'] ?? null);
        $this->assertSame($end->toDateTimeString(), $range['end'] ?? null);

        Event::assertDispatched(EntitlementGranted::class, function ($event) use ($entitlement) {
            return (string) $event->entitlement->id() === (string) $entitlement->id();
        });
    }

    public function test_revoke_entitlement_deletes_single_entry_and_dispatches_event(): void
    {
        Event::fake([EntitlementRevoked::class]);

        $this->makeCollection('entitlements');
        $this->makeCollection('products');

        $user = $this->makeUser('api-user-2');
        $target = $this->makeEntry('products', 'api-product-2', true);

        $entitlement = $this->makeEntitlement('api-entitlement-1', $user->id(), $target->id(), true, null);

        $service = new AccessService();
        $result = $service->revokeEntitlement($entitlement);

        $this->assertTrue($result);
        $this->assertNull(Entry::find($entitlement->id()));

        Event::assertDispatched(EntitlementRevoked::class, function ($event) use ($entitlement) {
            return (string) $event->entitlement->id() === (string) $entitlement->id();
        });
    }

    public function test_revoke_entitlements_for_user_target_deletes_all_matching_entries_and_dispatches_events(): void
    {
        Event::fake([EntitlementRevoked::class]);

        $this->makeCollection('entitlements');
        $this->makeCollection('products');

        $user = $this->makeUser('api-user-2');
        $otherUser = $this->makeUser('api-user-3');
        $target = $this->makeEntry('products', 'api-product-2', true);

        $entitlementOne = $this->makeEntitlement('api-entitlement-1', $user->id(), $target->id(), true, null);
        $entitlementTwo = $this->makeEntitlement('api-entitlement-2', $user->id(), $target->id(), false, null);
        $this->makeEntitlement('api-entitlement-3', $otherUser->id(), $target->id(), true, null);

        $service = new AccessService();
        $count = $service->revokeEntitlementsForUserTarget($user, $target);

        $this->assertSame(2, $count);
        $this->assertNull(Entry::find($entitlementOne->id()));
        $this->assertNull(Entry::find($entitlementTwo->id()));

        Event::assertDispatchedTimes(EntitlementRevoked::class, 2);
    }

    private function makeCollection(string $handle): void
    {
        if (StatamicCollection::find($handle)) {
            return;
        }

        StatamicCollection::make($handle)->save();
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

        $entry = Entry::make();
        $entry->collection($collection);
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
        mixed $validity,
        ?bool $keepUnlockedAfterExpiry = null
    ): EntryContract {
        $data = [
            'user' => $userId,
            'target' => $targetId,
        ];

        if ($validity !== null) {
            $data['validity'] = $validity;
        }

        if ($keepUnlockedAfterExpiry !== null) {
            $data[$this->keepUnlockedAfterExpiryField()] = $keepUnlockedAfterExpiry;
        }

        $entry = Entry::make();
        $entry->collection('entitlements');
        $entry->id($id);
        $entry->slug(Str::slug($id));
        $entry->data($data);
        $entry->published($published);
        $entry->save();

        return $entry;
    }

    private function keepUnlockedAfterExpiryField(): string
    {
        return config(
            'statamic.daugt-access.entitlements.fields.keep_unlocked_after_expiry',
            'keepUnlockedAfterExpiry'
        );
    }
}
