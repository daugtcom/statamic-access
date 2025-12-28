<?php

namespace Daugt\Access\Widgets;

use Daugt\Access\Services\AccessService;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Widgets\VueComponent;
use Statamic\Widgets\Widget;

class AccessList extends Widget
{
    /**
     * Access List widget
     *
     * Displays a list of entries the currently authenticated
     * Control Panel user has access to.
     *
     * ## Config options
     *
     * - `title` (string, optional)
     *   Widget title shown in the Control Panel.
     *   Default: `"Access List"`
     *
     * - `collection` (string|null, optional)
     *   Restrict accessible targets to a specific collection handle.
     *   Example: `"pages"`
     *
     * - `image` (string|null, optional)
     *   Field handle of an Assets field to use as thumbnail image.
     *   Example: `"cover"` or `"image"`
     *   Default: `null` (no image in payload)
     *
     * Notes:
     * - `name` uses the entry title.
     * - `url` uses Statamic’s default `$entry->url()`.
     */
    public function __construct(private AccessService $access) {}

    public function component()
    {
        $user = auth()->user();
        $title = $this->config('title') ?? 'Access List';

        if (!$user) {
            return VueComponent::render('AccessList', ['title' => $title, 'access' => []]);
        }

        $targets = collect(
            $this->access->accessibleTargets($user, $this->config('collection'))
        );

        $imageHandle = $this->config('image'); // optional

        $access = $targets->map(fn (EntryContract $entry) => [
            'id'    => (string) $entry->id(),
            'name'  => $this->entryName($entry),
            'url'   => $entry->url(),
            'image' => $imageHandle ? $this->imageUrl($entry, (string) $imageHandle) : null,
        ])->values()->all();

        return VueComponent::render('AccessList', [
            'title'  => $title,
            'access' => $access,
        ]);
    }

    private function entryName(EntryContract $entry): string
    {
        $title = $entry->get('title');
        if (is_string($title) && $title !== '') {
            return $title;
        }

        return $entry->slug() ?? (string) $entry->id();
    }

    private function imageUrl(EntryContract $entry, string $handle): ?string
    {
        $asset = $entry->augmentedValue($handle)->value();

        return $asset->manipulate([
            'w' => 64,
            'h' => 64,
            'fit' => 'crop_focal',
        ]);
    }
}
