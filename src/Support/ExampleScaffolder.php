<?php

namespace Daugt\Access\Support;

use Daugt\Access\Entries\EntitlementEntry;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Statamic\Entries\Collection as StatamicCollection;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy as TaxonomyFacade;
use Statamic\Fields\Blueprint as StatamicBlueprint;
use Statamic\Taxonomies\Taxonomy as StatamicTaxonomy;

final class ExampleScaffolder
{
    /**
     * @return array{collection: StatamicCollection, created: bool}
     */
    public function ensureCollection(
        string $handle,
        string $title,
        bool $dated = false,
        ?string $futureDateBehavior = null
    ): array {
        $collection = CollectionFacade::find($handle);
        $created = false;

        if (! $collection) {
            $collection = CollectionFacade::make($handle);
            $collection->title($title);
            $collection->dated($dated);
            $collection->requiresSlugs(true);

            if ($futureDateBehavior) {
                $collection->futureDateBehavior($futureDateBehavior);
            }

            $collection->save();
            $created = true;
        }

        return ['collection' => $collection, 'created' => $created];
    }

    /**
     * @return array{taxonomy: StatamicTaxonomy, created: bool}
     */
    public function ensureTaxonomy(string $handle, string $title, array $inject = []): array
    {
        $taxonomy = TaxonomyFacade::findByHandle($handle);
        $created = false;
        $updated = false;

        if (! $taxonomy) {
            $taxonomy = TaxonomyFacade::make($handle);
            $taxonomy->title($title);

            if (Site::multiEnabled()) {
                $taxonomy->sites([Site::default()->handle()]);
            }

            $created = true;
            $updated = true;
        }

        if (! empty($inject)) {
            $cascade = $taxonomy->cascade() ?? collect();
            foreach ($inject as $key => $value) {
                if ($cascade->get($key) !== $value) {
                    $cascade = $cascade->merge([$key => $value]);
                    $updated = true;
                }
            }

            if ($updated) {
                $taxonomy->cascade($cascade->all());
            }
        }

        if ($updated) {
            $taxonomy->save();
        }

        return ['taxonomy' => $taxonomy, 'created' => $created];
    }

    /**
     * @param array<int, string> $taxonomies
     */
    public function ensureCollectionTaxonomies(StatamicCollection $collection, array $taxonomies): bool
    {
        $handles = collect($taxonomies)
            ->filter(fn ($handle) => is_string($handle) && $handle !== '')
            ->unique()
            ->values()
            ->all();

        if ($handles === []) {
            return false;
        }

        $existing = $collection->taxonomies()->map->handle()->all();
        $merged = collect($existing)
            ->merge($handles)
            ->unique()
            ->values()
            ->all();

        if ($merged === $existing) {
            return false;
        }

        $collection->taxonomies($merged);
        $collection->save();

        return true;
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @return array{blueprint: StatamicBlueprint, created: bool, updated: bool}
     */
    public function ensureBlueprintForCollection(StatamicCollection $collection, array $fields): array
    {
        $handle = sprintf('collections/%s/%s', $collection->handle(), Str::singular($collection->handle()));

        $blueprint = Blueprint::find($handle);
        $created = false;
        $updated = false;

        if (! $blueprint) {
            $blueprint = Blueprint::makeFromFields($fields);
            $blueprint->setHandle($handle);
            Blueprint::save($blueprint);
            $created = true;

            return ['blueprint' => $blueprint, 'created' => $created, 'updated' => $updated];
        }

        foreach ($fields as $fieldHandle => $config) {
            if (! $blueprint->hasField($fieldHandle)) {
                $blueprint->ensureField($fieldHandle, $config);
                $updated = true;
                continue;
            }

            $blueprint->ensureFieldHasConfig($fieldHandle, $config);
            $updated = true;
        }

        if ($updated) {
            Blueprint::save($blueprint);
        }

        return ['blueprint' => $blueprint, 'created' => $created, 'updated' => $updated];
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @return array{blueprint: StatamicBlueprint, created: bool, updated: bool}
     */
    public function ensureBlueprintForTaxonomy(StatamicTaxonomy $taxonomy, array $fields): array
    {
        $handle = sprintf('taxonomies/%s/%s', $taxonomy->handle(), Str::singular($taxonomy->handle()));

        $blueprint = Blueprint::find($handle);
        $created = false;
        $updated = false;

        if (! $blueprint) {
            $blueprint = Blueprint::makeFromFields($fields);
            $blueprint->setHandle($handle);
            Blueprint::save($blueprint);
            $created = true;

            return ['blueprint' => $blueprint, 'created' => $created, 'updated' => $updated];
        }

        foreach ($fields as $fieldHandle => $config) {
            if (! $blueprint->hasField($fieldHandle)) {
                $blueprint->ensureField($fieldHandle, $config);
                $updated = true;
                continue;
            }

            $blueprint->ensureFieldHasConfig($fieldHandle, $config);
            $updated = true;
        }

        if ($updated) {
            Blueprint::save($blueprint);
        }

        return ['blueprint' => $blueprint, 'created' => $created, 'updated' => $updated];
    }

    /**
     * @param array<int, string> $handles
     * @return array{targets: array<int, string>, config_updated: bool, blueprint_updated: bool}
     */
    public function ensureEntitlementTargets(array $handles): array
    {
        $handles = collect($handles)
            ->filter(fn ($handle) => is_string($handle) && $handle !== '')
            ->unique()
            ->values()
            ->all();

        $existing = config('statamic.daugt-access.entitlements.target_collections', []);
        $existing = Arr::wrap($existing);
        $targets = collect($existing)
            ->merge($handles)
            ->filter()
            ->unique()
            ->values()
            ->all();

        config()->set('statamic.daugt-access.entitlements.target_collections', $targets);

        $configUpdated = $this->updateConfigTargetCollections($targets);
        $blueprintUpdated = $this->updateEntitlementsBlueprintTargets($targets);

        return [
            'targets' => $targets,
            'config_updated' => $configUpdated,
            'blueprint_updated' => $blueprintUpdated,
        ];
    }

    /**
     * @param array<int, string> $targets
     */
    private function updateConfigTargetCollections(array $targets): bool
    {
        $path = config_path('statamic/daugt-access.php');

        if (! File::exists($path)) {
            return false;
        }

        $contents = File::get($path);
        $pattern = "/^([ \t]*)'target_collections'\s*=>\s*\[[^\]]*\]/m";

        if (! preg_match($pattern, $contents, $matches)) {
            return false;
        }

        $indent = $matches[1] ?? '';
        $replacement = $this->renderTargetCollectionsLine($indent, $targets);

        $updated = preg_replace($pattern, $replacement, $contents, 1, $count);

        if ($updated === null || $count < 1) {
            return false;
        }

        if ($updated !== $contents) {
            File::put($path, $updated);
            return true;
        }

        return false;
    }

    /**
     * @param array<int, string> $targets
     */
    private function renderTargetCollectionsLine(string $indent, array $targets): string
    {
        if ($targets === []) {
            return sprintf("%s'target_collections' => []", $indent);
        }

        $lines = array_map(fn (string $handle) => sprintf("'%s'", $handle), $targets);
        $body = implode(",\n{$indent}    ", $lines);

        return sprintf(
            "%s'target_collections' => [\n%s    %s,\n%s]",
            $indent,
            $indent,
            $body,
            $indent
        );
    }

    /**
     * @param array<int, string> $targets
     */
    private function updateEntitlementsBlueprintTargets(array $targets): bool
    {
        $handle = 'collections/' . EntitlementEntry::COLLECTION . '/' . Str::singular(EntitlementEntry::COLLECTION);
        $blueprint = Blueprint::find($handle);

        if (! $blueprint) {
            return false;
        }

        $blueprint->ensureFieldHasConfig(EntitlementEntry::TARGET, ['collections' => $targets]);
        Blueprint::save($blueprint);

        return true;
    }
}
