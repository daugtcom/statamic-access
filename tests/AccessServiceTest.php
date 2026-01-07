<?php

namespace Daugt\Access\Tests;

use Daugt\Access\Entries\EntitlementEntry;
use Daugt\Access\Services\AccessService;
use Illuminate\Support\Str;
use Statamic\Contracts\Auth\User as StatamicUser;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection as StatamicCollection;
use Statamic\Facades\Entry;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
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

    public function test_accessible_series_items_use_timeslots(): void
    {
        $this->makeCollection(EntitlementEntry::COLLECTION);
        $this->makeCollection('series');
        $this->makeCollection('series_items', true, 'private', ['series_categories']);
        $this->makeTaxonomy('series_categories');

        $user = $this->makeUser('user-series-1');
        $series = $this->makeEntry('series', 'series-1', true);
        $otherSeries = $this->makeEntry('series', 'series-2', true);

        $seriesTerm = $this->makeTerm('series_categories', 'series-1-category', 'Series 1 - Category');
        $otherTerm = $this->makeTerm('series_categories', 'series-2-category', 'Series 2 - Category');

        $slotStart = now()->subDays(2)->toDateTimeString();
        $slotEnd = now()->addHours(1)->toDateTimeString();

        $withinSlotDate = now()->subDay();
        $outsideSlotDate = now()->subDays(5);

        $itemWithinSlot = $this->makeDatedEntry('series_items', 'item-1', true, $withinSlotDate, [
            'series' => [(string) $series->id()],
            'series_categories' => [$seriesTerm->slug()],
        ]);

        $itemOutsideSlot = $this->makeDatedEntry('series_items', 'item-2', true, $outsideSlotDate, [
            'series' => [(string) $series->id()],
            'series_categories' => [$seriesTerm->slug()],
        ]);

        $itemOtherSeries = $this->makeDatedEntry('series_items', 'item-3', true, $withinSlotDate, [
            'series' => [(string) $otherSeries->id()],
            'series_categories' => [$otherTerm->slug()],
        ]);

        $itemNoSeries = $this->makeDatedEntry('series_items', 'item-4', true, $withinSlotDate);
        $itemNoCategory = $this->makeDatedEntry('series_items', 'item-5', true, $withinSlotDate, [
            'series' => [(string) $series->id()],
        ]);

        $this->makeEntitlement('entitlement-series-1', $user->id(), $series->id(), true, $slotStart, $slotEnd);
        $service = new AccessService();
        $items = $service->accessibleSeriesItems($user, $series, 'series_items', 'series', 'series_categories', now());
        $ids = collect($items)->map(fn (EntryContract $entry) => (string) $entry->id())->all();

        $this->assertContains((string) $itemWithinSlot->id(), $ids);
        $this->assertContains((string) $itemNoCategory->id(), $ids);
        $this->assertNotContains((string) $itemOtherSeries->id(), $ids);
        $this->assertNotContains((string) $itemNoSeries->id(), $ids);
    }

    public function test_accessible_series_items_exclude_outside_timeslots(): void
    {
        $this->makeCollection(EntitlementEntry::COLLECTION);
        $this->makeCollection('series');
        $this->makeCollection('series_items', true, 'private', ['series_categories']);
        $this->makeTaxonomy('series_categories');

        $user = $this->makeUser('user-series-2');
        $series = $this->makeEntry('series', 'series-2', true);

        $seriesTerm = $this->makeTerm('series_categories', 'series-2-category', 'Series 2 - Category');

        $slotStart = now()->subDays(2)->toDateTimeString();
        $slotEnd = now()->addHours(1)->toDateTimeString();

        $itemWithinSlot = $this->makeDatedEntry('series_items', 'item-5', true, now()->subDay(), [
            'series' => [(string) $series->id()],
            'series_categories' => [$seriesTerm->slug()],
        ]);

        $itemOutsideSlot = $this->makeDatedEntry('series_items', 'item-6', true, now()->subDays(5), [
            'series' => [(string) $series->id()],
            'series_categories' => [$seriesTerm->slug()],
        ]);

        $this->makeEntitlement('entitlement-series-2', $user->id(), $series->id(), true, $slotStart, $slotEnd);
        $service = new AccessService();
        $items = $service->accessibleSeriesItems($user, $series, 'series_items', 'series', 'series_categories', now());
        $ids = collect($items)->map(fn (EntryContract $entry) => (string) $entry->id())->all();

        $this->assertContains((string) $itemWithinSlot->id(), $ids);
        $this->assertNotContains((string) $itemOutsideSlot->id(), $ids);
    }

    private function makeCollection(
        string $handle,
        bool $dated = false,
        ?string $futureDateBehavior = null,
        array $taxonomies = []
    ): void
    {
        $existing = StatamicCollection::find($handle);

        if ($existing) {
            $dirty = false;

            if ($handle === EntitlementEntry::COLLECTION && $existing->entryClass() !== EntitlementEntry::class) {
                $existing->entryClass(EntitlementEntry::class);
                $dirty = true;
            }

            if ($dated && ! $existing->dated()) {
                $existing->dated(true);
                $dirty = true;
            }

            if ($futureDateBehavior && $existing->futureDateBehavior() !== $futureDateBehavior) {
                $existing->futureDateBehavior($futureDateBehavior);
                $dirty = true;
            }

            if ($taxonomies !== []) {
                $existingHandles = $existing->taxonomies()->map->handle()->all();
                $merged = collect($existingHandles)
                    ->merge($taxonomies)
                    ->unique()
                    ->values()
                    ->all();

                if ($merged !== $existingHandles) {
                    $existing->taxonomies($merged);
                    $dirty = true;
                }
            }

            if ($dirty) {
                $existing->save();
            }

            return;
        }

        $collection = StatamicCollection::make($handle);

        if ($handle === EntitlementEntry::COLLECTION) {
            $collection->entryClass(EntitlementEntry::class);
        }

        if ($dated) {
            $collection->dated(true);
        }

        if ($futureDateBehavior) {
            $collection->futureDateBehavior($futureDateBehavior);
        }

        if ($taxonomies !== []) {
            $collection->taxonomies($taxonomies);
        }

        $collection->save();
    }

    private function makeTaxonomy(string $handle, ?string $title = null): void
    {
        if (Taxonomy::findByHandle($handle)) {
            return;
        }

        $taxonomy = Taxonomy::make($handle);
        $taxonomy->title($title ?? Str::title(str_replace(['_', '-'], ' ', $handle)));
        $taxonomy->save();
    }

    private function makeTerm(string $taxonomy, string $slug, string $title)
    {
        $term = Term::make()
            ->taxonomy($taxonomy)
            ->slug($slug)
            ->set('title', $title);

        $term->save();

        return $term;
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

    private function makeDatedEntry(
        string $collection,
        string $id,
        bool $published,
        \Carbon\CarbonInterface $date,
        array $data = []
    ): EntryContract {
        $this->makeCollection($collection, true, 'private');

        $entry = Entry::make()->collection($collection);
        $entry->id($id);
        $entry->slug(Str::slug($id));
        $entry->data(array_merge(['title' => Str::title(str_replace('-', ' ', $id))], $data));
        $entry->date($date);
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
