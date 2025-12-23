<script setup>
    import { Widget, Listing, Icon } from '@statamic/cms/ui';

    defineProps({
        title: { type: String, default: 'Access List' },
        access: { type: Array, default: () => [] },
    });

    const columns = [
        { field: 'image', label: 'Image', sortable: false },
        { field: 'name', label: 'Name', sortable: false },
    ];

    function imageSrc(row) {
        const img = row?.image;

        // If backend already returns a URL/path, use it directly.
        if (typeof img === 'string' && (img.startsWith('http') || img.startsWith('/'))) {
            return img;
        }

        // Otherwise it's probably a raw filename (current situation).
        // Return null so we render a fallback label instead of a broken <img>.
        return null;
    }
</script>

<template>
    <Widget :title="title">
        <div class="p-2">
            <Listing
                :allowSearch="false"
                :allowPresets="false"
                :allowBulkActions="false"
                :sortable="false"
                :allowCustomizingColumns="false"
                :items="access"
                :columns="columns"
            >
                <template #cell-name="{ row, value }">
                    <a
                        v-if="row.url"
                        class="title-index-field md:text-lg! inline-flex gap-x-1 flex-grow-1"
                        :href="row.url"
                    >
                        {{value}}
                        <Icon name="external-link" />
                    </a>
                    <span v-else class="title-index-field" v-html="value" />
                </template>

                <template #cell-image="{ row }">
                    <div class="gap-2">
                        <img
                            v-if="imageSrc(row)"
                            :src="imageSrc(row)"
                            alt=""
                            class="max-h-24 max-w-24 md:max-w-36 rounded object-cover"
                        />
                        <span v-else class="text-xs text-gray-500">
                            {{ row.image ?? '—' }}
                        </span>
                    </div>
                </template>
            </Listing>
        </div>
    </Widget>
</template>
