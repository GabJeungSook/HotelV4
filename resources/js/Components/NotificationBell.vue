<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useShared } from '@/Composables/useShared';
import { notificationFallback, notificationMeta, toneBlock, toneTextClass } from '@/types/ui';

/**
 * The top-bar bell (DESIGN_SYSTEM §6.1): a live count and a panel with the first few notifications
 * — overdue rooms, due-out-soon, kiosk holds, unpaid balances, low stock and the shift reminders —
 * each linking to the screen that handles it, with "View all" to the full page. The feed is a
 * shared prop; opening the panel asks the server for a fresh copy. No read state: the count is how
 * many items are open now (NotificationQuery). Only roles with a feed get a bell.
 */
const { props: shared } = useShared();

const feed = computed(() => shared.value.notifications);
const items = computed(() => feed.value?.items ?? []);
const count = computed(() => feed.value?.count ?? 0);
const badge = computed(() => (count.value > 9 ? '9+' : String(count.value)));

const open = ref(false);

function refresh(): void {
    router.reload({ only: ['notifications'], preserveScroll: true, preserveState: true });
}

function onToggle(value: boolean): void {
    open.value = value;

    if (value) {
        refresh();
    }
}

const meta = (kind: string) => notificationMeta[kind] ?? notificationFallback;
</script>

<template>
    <UPopover v-if="feed" :open="open" :content="{ align: 'end', sideOffset: 8 }" @update:open="onToggle">
        <button
            type="button"
            class="hm-focus relative grid size-[34px] shrink-0 place-items-center rounded-lg border border-default bg-default text-muted hover:bg-elevated"
            :aria-label="count > 0 ? `Notifications, ${count} open` : 'Notifications'"
        >
            <UIcon name="i-solar-bell-bold-duotone" class="size-4" aria-hidden="true" />
            <span
                v-if="count > 0"
                class="absolute -top-1.5 -right-1.5 grid h-[17px] min-w-[17px] place-items-center rounded-full bg-bad px-1 text-[10px] leading-none font-semibold text-on-primary ring-2 ring-default"
            >{{ badge }}</span>
        </button>

        <template #content>
            <div class="flex max-h-[min(70dvh,560px)] w-[360px] flex-col overflow-hidden">
                <div class="flex items-center justify-between gap-2 border-b border-default px-4 py-3">
                    <b class="text-[13px] font-semibold text-highlighted">Notifications</b>
                    <span v-if="count > 0" class="text-[11.5px] font-medium text-muted">{{ count }} open</span>
                </div>

                <div v-if="items.length" class="min-h-0 flex-1 divide-y divide-default overflow-y-auto">
                    <Link
                        v-for="(n, i) in items"
                        :key="`${n.kind}-${i}`"
                        :href="n.href"
                        class="hm-focus flex items-start gap-3 px-4 py-3 transition-colors hover:bg-elevated"
                        @click="open = false"
                    >
                        <span class="grid size-8 shrink-0 place-items-center rounded-lg border" :class="toneBlock[meta(n.kind).tone]" aria-hidden="true">
                            <UIcon :name="meta(n.kind).icon" class="size-[17px]" :class="toneTextClass[meta(n.kind).tone]" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="flex items-baseline justify-between gap-2">
                                <b class="truncate text-[13px] leading-tight font-semibold text-highlighted">{{ n.title }}</b>
                                <small class="shrink-0 text-[11px] text-dimmed">{{ n.since_text }}</small>
                            </span>
                            <small class="mt-0.5 block truncate text-[12px] text-muted">{{ n.detail }}</small>
                        </span>
                    </Link>
                </div>

                <div v-else class="flex flex-col items-center gap-2 px-6 py-10 text-center">
                    <span class="grid size-11 place-items-center rounded-full bg-ok-50 text-ok" aria-hidden="true">
                        <UIcon name="i-solar-check-circle-bold-duotone" class="size-6" />
                    </span>
                    <b class="text-[13px] font-semibold text-highlighted">You're all caught up</b>
                    <small class="text-[12px] text-muted">Nothing needs attention right now.</small>
                </div>

                <Link
                    :href="feed.href"
                    class="hm-focus block border-t border-default px-4 py-2.5 text-center text-[12.5px] font-medium text-primary hover:bg-elevated"
                    @click="open = false"
                >View all notifications</Link>
            </div>
        </template>
    </UPopover>
</template>
