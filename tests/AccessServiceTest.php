<?php

namespace Daugt\Access\Tests;

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
        $this->makeCollection('entitlements');
        $this->makeCollection('products');

        $user = $this->makeUser('user-1');
        $target = $this->makeEntry('products', 'product-1', true);

        $validity = [
            'start' => now()->subDay()->toDateTimeString(),
            'end' => now()->addDay()->toDateTimeString(),
        ];

        $this->makeEntitlement('entitlement-1', $user->id(), $target->id(), true, $validity);

        $service = new AccessService();
        $at = now();

        $this->assertTrue($service->canAccess($user, $target, $at));
        $this->assertTrue($service->canAccess($user, (string) $target->id(), $at));
    }

    public function test_can_access_returns_false_for_missing_or_invalid_entitlements(): void
    {
        $this->makeCollection('entitlements');
        $this->makeCollection('products');

        $user = $this->makeUser('user-2');
        $target = $this->makeEntry('products', 'product-2', true);

        $service = new AccessService();
        $at = now();

        $this->assertFalse($service->canAccess($user, $target, $at));

        $this->makeEntitlement('entitlement-2', $user->id(), $target->id(), false, null);
        $this->assertFalse($service->canAccess($user, $target, $at));

        $this->makeEntitlement('entitlement-3', $user->id(), $target->id(), true, [
            'start' => now()->addDay()->toDateTimeString(),
            'end' => now()->addDays(2)->toDateTimeString(),
        ]);
        $this->assertFalse($service->canAccess($user, $target, $at));
    }

    public function test_can_access_allows_keep_unlocked_after_expiry(): void
    {
        $this->makeCollection('entitlements');
        $this->makeCollection('products');

        $user = $this->makeUser('user-keep-1');
        $target = $this->makeEntry('products', 'product-keep-1', true);

        $this->makeEntitlement('entitlement-keep-1', $user->id(), $target->id(), true, [
            'start' => now()->subDays(10)->toDateTimeString(),
            'end' => now()->subDays(2)->toDateTimeString(),
        ], true);

        $service = new AccessService();

        $this->assertTrue($service->canAccess($user, $target, now()));
    }

    public function test_can_access_denies_keep_unlocked_before_start(): void
    {
        $this->makeCollection('entitlements');
        $this->makeCollection('products');

        $user = $this->makeUser('user-keep-2');
        $target = $this->makeEntry('products', 'product-keep-2', true);

        $this->makeEntitlement('entitlement-keep-2', $user->id(), $target->id(), true, [
            'start' => now()->addDay()->toDateTimeString(),
            'end' => now()->addDays(10)->toDateTimeString(),
        ], true);

        $service = new AccessService();

        $this->assertFalse($service->canAccess($user, $target, now()));
    }

    public function test_can_access_returns_false_when_target_unpublished(): void
    {
        $this->makeCollection('entitlements');
        $this->makeCollection('products');

        $user = $this->makeUser('user-3');
        $target = $this->makeEntry('products', 'product-3', false);

        $this->makeEntitlement('entitlement-4', $user->id(), $target->id(), true, null);

        $service = new AccessService();

        $this->assertFalse($service->canAccess($user, $target, now()));
    }

    public function test_accessible_targets_returns_only_published_valid_targets_and_respects_filter(): void
    {
        $this->makeCollection('entitlements');
        $this->makeCollection('alpha');
        $this->makeCollection('beta');

        $user = $this->makeUser('user-4');

        $alpha = $this->makeEntry('alpha', 'alpha-1', true);
        $beta = $this->makeEntry('beta', 'beta-1', true);
        $unpublished = $this->makeEntry('alpha', 'alpha-2', false);
        $expired = $this->makeEntry('beta', 'beta-2', true);
        $keepExpired = $this->makeEntry('beta', 'beta-3', true);

        $validity = [
            'start' => now()->subDay()->toDateTimeString(),
            'end' => now()->addDay()->toDateTimeString(),
        ];

        $this->makeEntitlement('entitlement-5', $user->id(), $alpha->id(), true, $validity);
        $this->makeEntitlement('entitlement-6', $user->id(), $beta->id(), true, $validity);
        $this->makeEntitlement('entitlement-7', $user->id(), $unpublished->id(), true, $validity);
        $this->makeEntitlement('entitlement-8', $user->id(), $expired->id(), true, [
            'start' => now()->subDays(3)->toDateTimeString(),
            'end' => now()->subDay()->toDateTimeString(),
        ]);
        $this->makeEntitlement('entitlement-keep-3', $user->id(), $keepExpired->id(), true, [
            'start' => now()->subDays(6)->toDateTimeString(),
            'end' => now()->subDays(2)->toDateTimeString(),
        ], true);
        $this->makeEntitlement('entitlement-9', $user->id(), $alpha->id(), true, $validity);
        $this->makeEntitlement('entitlement-10', $user->id(), $beta->id(), false, $validity);

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
