<?php

namespace Daugt\Access\Console\Commands;

use Daugt\Access\Support\ExampleScaffolder;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Statamic\Console\RunsInPlease;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

class MakeSeriesCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:daugt-access:make-series
        {--without-config : Skip updating entitlement target collections}';

    protected $description = 'Create a series example (course-style collection + items).';

    public function handle(ExampleScaffolder $scaffolder): void
    {
        $seriesHandle = $this->promptHandle('Series collection handle', 'series');
        $seriesTitle = text(
            label: 'Series collection title',
            placeholder: 'Series',
            default: $this->titleFromHandle($seriesHandle),
            required: true
        );

        $itemsHandle = $this->promptHandle('Series items collection handle', 'series_items');
        $itemsTitle = text(
            label: 'Series items collection title',
            placeholder: 'Series Items',
            default: $this->titleFromHandle($itemsHandle),
            required: true
        );

        $useCategories = confirm(
            label: 'Enable categories for series items?',
            default: true
        );

        $taxonomyHandle = null;
        $taxonomyTitle = null;
        if ($useCategories) {
            $taxonomyHandle = $this->promptHandle(
                'Series categories taxonomy handle',
                $this->normalizeHandle($seriesHandle.'_categories')
            );
            $taxonomyTitle = text(
                label: 'Series categories taxonomy title',
                placeholder: 'Series Categories',
                default: $this->titleFromHandle($taxonomyHandle),
                required: true
            );
        }

        $allowIndividualItems = confirm(
            label: 'Allow individual entitlements for series items?',
            default: true
        );

        $series = $scaffolder->ensureCollection($seriesHandle, $seriesTitle);
        $this->line($series['created']
            ? "Collection [{$seriesHandle}] created."
            : "Collection [{$seriesHandle}] already exists."
        );

        $items = $scaffolder->ensureCollection(
            $itemsHandle,
            $itemsTitle,
            true,
            'private'
        );
        $this->line($items['created']
            ? "Collection [{$itemsHandle}] created."
            : "Collection [{$itemsHandle}] already exists."
        );

        $taxonomy = null;
        if ($useCategories && $taxonomyHandle && $taxonomyTitle) {
            $taxonomy = $scaffolder->ensureTaxonomy($taxonomyHandle, $taxonomyTitle);
            $this->line($taxonomy['created']
                ? "Taxonomy [{$taxonomyHandle}] created."
                : "Taxonomy [{$taxonomyHandle}] already exists."
            );
        }

        if (! $items['collection']->dated()) {
            $this->warn('Series items collection is not dated; publish dates will not gate access.');
        }

        if ($items['collection']->futureDateBehavior() !== 'private') {
            $this->warn('Series items future date behavior is not private; scheduled items may appear early.');
        }

        $seriesFields = $this->seriesFields();
        $seriesBlueprint = $scaffolder->ensureBlueprintForCollection($series['collection'], $seriesFields);
        $this->line($seriesBlueprint['created']
            ? 'Series blueprint created.'
            : 'Series blueprint ensured.'
        );

        if ($taxonomy) {
            $termFields = $this->taxonomyTermFields();
            $termBlueprint = $scaffolder->ensureBlueprintForTaxonomy($taxonomy['taxonomy'], $termFields);
            $this->line($termBlueprint['created']
                ? 'Series categories blueprint created.'
                : 'Series categories blueprint ensured.'
            );
        }

        $itemsFields = $this->itemsFields(
            $seriesHandle,
            $taxonomyHandle,
            $taxonomyTitle
        );
        $itemsBlueprint = $scaffolder->ensureBlueprintForCollection($items['collection'], $itemsFields);
        $this->line($itemsBlueprint['created']
            ? 'Series items blueprint created.'
            : 'Series items blueprint ensured.'
        );

        if ($taxonomyHandle) {
            $taxonomyUpdated = $scaffolder->ensureCollectionTaxonomies(
                $items['collection'],
                [$taxonomyHandle]
            );

            if ($taxonomyUpdated) {
                $this->line("Series items collection taxonomies updated: {$taxonomyHandle}.");
            }
        }

        if (! $this->option('without-config')) {
            $targets = [$seriesHandle];
            if ($allowIndividualItems) {
                $targets[] = $itemsHandle;
            }

            $entitlements = $scaffolder->ensureEntitlementTargets($targets);
            $this->line('Entitlement targets: '.implode(', ', $entitlements['targets']));
        }

        $this->info('Series example ready.');
    }

    private function promptHandle(string $label, string $default): string
    {
        return text(
            label: $label,
            placeholder: $default,
            default: $default,
            required: true,
            validate: function (string $value) {
                if (! $this->isValidHandle($value)) {
                    return 'Use only letters, numbers, underscores, or dashes.';
                }

                return null;
            },
            transform: fn (string $value) => $this->normalizeHandle($value)
        );
    }

    private function isValidHandle(string $value): bool
    {
        return (bool) preg_match('/^[a-z0-9_-]+$/', $this->normalizeHandle($value));
    }

    private function normalizeHandle(string $value): string
    {
        return Str::slug($value, '_');
    }

    private function titleFromHandle(string $handle): string
    {
        return Str::title(str_replace(['_', '-'], ' ', $handle));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function seriesFields(): array
    {
        $fields = [
            'title' => [
                'type' => 'text',
                'required' => true,
            ],
            'summary' => [
                'type' => 'textarea',
                'display' => 'Summary',
            ],
            'content' => [
                'type' => 'markdown',
                'display' => 'Content',
            ],
        ];

        return $fields;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function itemsFields(string $seriesHandle, ?string $taxonomyHandle, ?string $taxonomyTitle): array
    {
        $fields = [
            'title' => [
                'type' => 'text',
                'required' => true,
            ],
            $seriesHandle => [
                'type' => 'entries',
                'display' => 'Series',
                'instructions' => 'Optional series assignment for this item.',
                'collections' => [$seriesHandle],
                'mode' => 'select',
            ],
        ];

        if ($taxonomyHandle && $taxonomyTitle) {
            $fields[$taxonomyHandle] = [
                'type' => 'terms',
                'display' => $taxonomyTitle,
                'instructions' => 'Select a category when this item is part of a series.',
                'taxonomies' => [$taxonomyHandle],
                'mode' => 'select',
                'create' => true,
                'max_items' => 1,
                'if' => [
                    $seriesHandle => 'not empty',
                ],
            ];
        }

        $fields['summary'] = [
            'type' => 'textarea',
            'display' => 'Summary',
        ];
        $fields['content'] = [
            'type' => 'markdown',
            'display' => 'Content',
        ];

        return $fields;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function taxonomyTermFields(): array
    {
        return [
            'title' => [
                'type' => 'text',
                'required' => true,
                'display' => 'Title',
                'instructions' => 'Category name.',
            ],
        ];
    }
}
