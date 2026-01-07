<?php

namespace Daugt\Access\Console\Commands;

use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;

use function Laravel\Prompts\confirm;

class InstallExamplesCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:daugt-access:install-examples';

    protected $description = 'Install example single and series access collections.';

    public function handle(): void
    {
        $installSingle = confirm(
            label: 'Create a single-access example collection?',
            default: true
        );

        $installSeries = confirm(
            label: 'Create a series example (course + items)?',
            default: true
        );

        if (! $installSingle && ! $installSeries) {
            $this->info('No examples selected.');
            return;
        }

        if ($installSingle) {
            $this->call('statamic:daugt-access:make-single');
        }

        if ($installSeries) {
            $this->call('statamic:daugt-access:make-series');
        }

        $this->info('Example installation complete.');
    }
}
