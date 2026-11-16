<script setup lang="ts">
import { computed } from 'vue';
import { formatDateRange } from '@/Composables/useDateTime';
import { formatMoney } from '@/Composables/useMoney';

/**
 * Sales overview / How guests paid (61, 60; mockup v6): the period's net sales as one big figure,
 * rooms and extras side by side, then one bar per payment method with its share. Managers only:
 * never placed on a front desk page.
 */
export interface Overview {
    period: string;
    from: string;
    to: string;
    sales: number;
    rooms: number;
    extras: number;
    methods: { method: string; amount: number; count: number }[];
}

const props = withDefaults(defineProps<{ overview?: Overview; title?: string; showRange?: boolean; clickable?: boolean }>(), { overview: undefined, title: 'Sales overview', showRange: true, clickable: false });
const emit = defineEmits<{ drill: ['rooms' | 'extras'] }>();

const names: Record<string, string> = { cash: 'Cash', gcash: 'GCash', card: 'Card', deposit: 'From deposit' };
const fills = ['var(--hm-primary)', 'var(--hm-select-line)', 'var(--hm-primary-line)', 'var(--hm-bar-3)'];

const rows = computed(() => {
    const list = (props.overview?.methods ?? []).filter((m) => m.method !== 'deposit' || m.amount > 0);
    const total = list.reduce((a, m) => a + m.amount, 0);

    return list.map((m, i) => ({ key: m.method, name: names[m.method] ?? m.method, pct: total === 0 ? 0 : Math.round((m.amount * 100) / total), fill: fills[i % fills.length], text: formatMoney(m.amount, 'column') }));
});

const range = computed(() => (props.overview ? formatDateRange(`${props.overview.from}T12:00:00+08:00`, `${props.overview.to}T12:00:00+08:00`) : ''));
</script>

<template>
    <section class="rounded-card border border-default bg-default px-5 py-[18px]" data-sales-overview>
        <div class="mb-3.5 flex min-h-[30px] items-center gap-2.5">
            <h2 class="text-[14.5px] font-semibold text-highlighted">{{ title }}</h2>
            <div class="ms-auto flex items-center gap-2"><slot name="control" /></div>
        </div>
        <template v-if="overview">
            <span class="text-xs text-muted">Total sales<template v-if="showRange"> · {{ range }}</template></span>
            <div class="text-[26px] leading-[1.15] font-semibold tracking-[-0.025em] text-highlighted tabular">{{ formatMoney(overview.sales, 'whole') }}</div>
            <div class="my-3.5 grid grid-cols-2 overflow-hidden rounded-[10px] border border-default">
                <component
                    :is="clickable ? 'button' : 'div'"
                    :type="clickable ? 'button' : undefined"
                    class="group flex flex-col items-center px-3.5 py-3 text-center"
                    :class="clickable ? 'hm-focus cursor-pointer transition-colors hover:bg-elevated' : ''"
                    @click="clickable ? emit('drill', 'rooms') : undefined"
                >
                    <UIcon name="i-solar-bed-bold-duotone" class="size-[18px] text-muted" aria-hidden="true" />
                    <span class="mt-1 inline-flex items-center gap-0.5 text-[11.5px] text-muted">Rooms<UIcon v-if="clickable" name="i-solar-alt-arrow-right-linear" class="size-3 opacity-60 transition-transform group-hover:translate-x-0.5" aria-hidden="true" /></span>
                    <b class="block text-[17px] font-semibold tracking-[-0.01em] tabular">{{ formatMoney(overview.rooms, 'whole') }}</b>
                </component>
                <component
                    :is="clickable ? 'button' : 'div'"
                    :type="clickable ? 'button' : undefined"
                    class="group flex flex-col items-center border-l border-default px-3.5 py-3 text-center"
                    :class="clickable ? 'hm-focus cursor-pointer transition-colors hover:bg-elevated' : ''"
                    @click="clickable ? emit('drill', 'extras') : undefined"
                >
                    <UIcon name="i-solar-cart-large-bold-duotone" class="size-[18px] text-muted" aria-hidden="true" />
                    <span class="mt-1 inline-flex items-center gap-0.5 text-[11.5px] text-muted">Extras<UIcon v-if="clickable" name="i-solar-alt-arrow-right-linear" class="size-3 opacity-60 transition-transform group-hover:translate-x-0.5" aria-hidden="true" /></span>
                    <b class="block text-[17px] font-semibold tracking-[-0.01em] tabular">{{ formatMoney(overview.extras, 'whole') }}</b>
                </component>
            </div>
            <ul>
                <li v-for="r in rows" :key="r.key" class="grid grid-cols-[90px_minmax(0,1fr)_46px] items-center gap-3 py-1.5 text-[12.5px]" :title="r.text">
                    <span>{{ r.name }}</span>
                    <span class="h-1.5 overflow-hidden rounded-full bg-elevated" role="img" :aria-label="`${r.name} ${r.pct}%, ${r.text}`">
                        <i class="block h-full rounded-full" :style="{ width: `${r.pct}%`, background: r.fill }" />
                    </span>
                    <b class="text-right font-semibold tabular">{{ r.pct }}%</b>
                </li>
            </ul>
        </template>
        <div v-else aria-hidden="true">
            <USkeleton class="h-4 w-32" />
            <USkeleton class="mt-2 h-7 w-28" />
            <USkeleton class="mt-3.5 h-[74px] w-full" />
            <USkeleton v-for="i in 3" :key="i" class="mt-2.5 h-4 w-full" />
        </div>
    </section>
</template>
