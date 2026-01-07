<?php

namespace Daugt\Access\Console\Commands;

use Daugt\Access\Support\ExampleScaffolder;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Statamic\Console\RunsInPlease;

use function Laravel\Prompts\text;

class MakeSingleCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:daugt-access:make-single
        {--handle= : Collection handle for single access items}
        {--title= : Collection title for single access items}
        {--without-config : Skip updating entitlement target collections}';

    protected $description = 'Create a single-access example collection (e.g. a digital download).';

    public function handle(ExampleScaffolder $scaffolder): void
    {
        $handle = $this->option('handle') ?: $this->promptHandle(
            'Single collection handle',
            'meditations'
        );

        $title = $this->option('title') ?: text(
            label: 'Single collection title',
            placeholder: 'Meditations',
            default: $this->titleFromHandle($handle),
            required: true
        );

        $result = $scaffolder->ensureCollection($handle, $title);
        $this->line($result['created']
            ? "Collection [{$handle}] created."
            : "Collection [{$handle}] already exists."
        );

        $fields = $this->singleFields();
        $blueprintResult = $scaffolder->ensureBlueprintForCollection($result['collection'], $fields);

        $this->line($blueprintResult['created']
            ? 'Blueprint created.'
            : 'Blueprint ensured.'
        );

        if (! $this->option('without-config')) {
            $entitlements = $scaffolder->ensureEntitlementTargets([$handle]);
            $this->line('Entitlement targets: '.implode(', ', $entitlements['targets']));
        }

        $this->info('Single access example ready.');
    }

    private function promptHandle(string $label, string $default): string
    {
        return text(
            label: $label,
            placeholder: 'meditations',
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
    private function singleFields(): array
    {
        return [
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
    }
}
