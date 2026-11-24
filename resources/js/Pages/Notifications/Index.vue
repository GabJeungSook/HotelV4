<!-- No mockup number: the notification bell and this list were added on request (sibling of 49). -->
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import { formatDateTime } from '@/Composables/useDateTime';
import { type NotificationItem, useShared } from '@/Composables/useShared';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout.vue';
import FrontDeskLayout from '@/Layouts/FrontDeskLayout.vue';
import OwnerLayout from '@/Layouts/OwnerLayout.vue';
import { notificationFallback, notificationMeta, toneBlock, toneTextClass } from '@/types/ui';

/**
 * All notifications: the full live feed behind the top-bar bell, one card per item linking to the
 * screen that handles it. Read-only — the count falls as the work is done. The shell matches the
 * viewer's role, so the front desk, back office, admin and owner each see it in their own layout.
 */
const props = defineProps<{ rows: NotificationItem[]; at: string }>();

const { props: shared } = useShared();

const layout = computed(
    () =>
        ({
            frontdesk: FrontDeskLayout,
            supervisor: FrontDeskLayout,
            backoffice: BackOfficeLayout,
            admin: AdminLayout,
            owner: OwnerLayout,
        })[shared.value.auth?.role ?? ''] ?? BackOfficeLayout,
);

const meta = (kind: string) => notificationMeta[kind] ?? notificationFallback;
</script>

<template>
    <component :is="layout" title="Notifications">
        <Head title="Notifications" />
        <div class="rounded-card border border-default bg-default">
            <div class="flex items-center justify-between gap-2 border-b border-default px-5 py-3.5">
                <b class="text-[14px] font-semibold text-highlighted">{{ rows.length }} {{ rows.length === 1 ? 'notification' : 'notifications' }}</b>
                <small class="text-[12px] text-muted">Updated {{ formatDateTime(at, 'time') }}</small>
            </div>

            <EmptyState
                v-if="!rows.length"
                class="py-16"
                icon="i-solar-check-circle-bold-duotone"
                title="You're all caught up"
                text="Nothing needs attention right now. New items show up here and on the bell as they come."
            />

            <ul v-else class="divide-y divide-default">
                <li v-for="(n, i) in rows" :key="`${n.kind}-${i}`">
                    <Link
                        :href="n.href"
                        class="hm-focus flex items-center gap-3.5 px-5 py-3.5 transition-colors hover:bg-elevated"
                        :class="n.severity === 'high' ? 'border-l-2 border-l-bad' : 'border-l-2 border-l-transparent'"
                    >
                        <span class="grid size-10 shrink-0 place-items-center rounded-control border" :class="toneBlock[meta(n.kind).tone]" aria-hidden="true">
                            <UIcon :name="meta(n.kind).icon" class="size-5" :class="toneTextClass[meta(n.kind).tone]" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <b class="block truncate text-[13.5px] leading-tight font-semibold text-highlighted">{{ n.title }}</b>
                            <p class="mt-0.5 truncate text-[12.5px] text-muted">{{ n.detail }}</p>
                        </div>
                        <small class="shrink-0 text-[11.5px] text-dimmed">{{ n.since_text }}</small>
                        <UIcon name="i-solar-alt-arrow-right-linear" class="size-4 shrink-0 text-dimmed" aria-hidden="true" />
                    </Link>
                </li>
            </ul>
        </div>
    </component>
</template>
