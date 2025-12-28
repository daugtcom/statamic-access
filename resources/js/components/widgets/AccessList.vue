<script setup>
    import { Widget, Listing, Button } from '@statamic/cms/ui';

    defineProps({
        title: { type: String, default: 'Access List' },
        access: { type: Array, default: () => [] },
    });

    const columns = [
        { field: 'name', label: __('daugt-access::entitlements.name'), sortable: false },
        { field: 'actions', label: '', sortable: false },
    ];

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
                <template #cell-name="{ row }">
                  <div class="flex items-center gap-2">
                    <img
                        v-if="row.image"
                        :src="row.image"
                        :alt="row.product ?? ''"
                        class="h-8 w-8 rounded object-cover bg-gray-100"
                        loading="lazy"
                    />
                    <span>{{ row.name ?? '—' }}</span>
                  </div>
                </template>
              <template #cell-actions="{ row }">
                <Button
                    variant="default"
                    size="sm"
                    iconAppend="eye"
                    :text="__('daugt-access::widget.show')"
                    :href="row.url"
                />
              </template>
            </Listing>
        </div>
    </Widget>
</template>
