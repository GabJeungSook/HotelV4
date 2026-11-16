<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { Tone } from '@/types/ui';
import { toneBlock } from '@/types/ui';
import PersonAvatar from './PersonAvatar.vue';

/**
 * A week of shifts (DESIGN_SYSTEM §0, mockup v6 Shifts card on 61 and the Calendar tab of 37a):
 * days across, one band per shift period (AM, PM) down, and a block per front desk spanning the
 * days they worked in a row. A green dot marks someone on duty now; a dashed block is a shift
 * not opened yet. The server builds the blocks; this only places them.
 */
export interface CalendarDay {
    date: string;
    weekday: string;
    day: number;
    today?: boolean;
}

export interface CalendarBlock {
    key: string;
    /** 1-based first day column and the number of days it spans. */
    start: number;
    span: number;
    name?: string;
    sub?: string;
    tone?: Tone;
    live?: boolean;
    future?: boolean;
    href?: string;
}

export interface CalendarBand {
    label: string;
    /** Each row is one line of blocks that do not overlap. */
    rows: CalendarBlock[][];
}

withDefaults(defineProps<{ days: CalendarDay[]; bands: CalendarBand[]; emptyText?: string }>(), { emptyText: 'No shifts this week' });
</script>

<template>
    <div data-shift-calendar>
        <div class="-mx-0.5 grid grid-cols-7 border-b border-default pb-2.5 text-center">
            <div v-for="d in days" :key="d.date" :data-today="d.today || undefined">
                <span class="block text-[10.5px]" :class="d.today ? 'font-semibold text-primary-ink' : 'text-faint'">{{ d.weekday }}</span>
                <b class="mt-0.5 inline-block text-[13px] leading-[1.2] font-semibold" :class="d.today ? 'rounded-[7px] bg-primary px-[7px] py-0.5 text-on-primary' : 'text-highlighted'">{{ d.day }}</b>
            </div>
        </div>
        <p v-if="bands.length === 0" class="py-6 text-center text-[12.5px] text-muted">{{ emptyText }}</p>
        <section v-for="band in bands" :key="band.label" class="relative mt-3">
            <h3 class="mb-2 flex items-center gap-2 text-[11px] font-medium text-faint before:flex-1 before:border-t before:border-dashed before:border-accented after:flex-1 after:border-t after:border-dashed after:border-accented">
                {{ band.label }}
            </h3>
            <div v-for="(row, r) in band.rows" :key="r" class="relative mb-[5px] grid min-h-[36px] grid-cols-7 gap-1">
                <i v-for="c in 6" :key="c" class="pointer-events-none absolute inset-y-0 border-l border-muted" :style="{ left: `calc(${(c * 100) / 7}% - 0.5px)` }" aria-hidden="true" />
                <component
                    :is="block.href ? Link : 'span'"
                    v-for="block in row"
                    :key="block.key"
                    :href="block.href"
                    class="relative flex min-w-0 items-center gap-[7px] rounded-lg border py-[5px] ps-[5px] text-[11.5px] leading-tight"
                    :class="[
                        block.future ? 'border-dashed border-accented bg-default ps-2 text-muted' : toneBlock[block.tone ?? 'ok'],
                        block.live ? 'pe-5' : 'pe-2',
                        block.href ? 'hm-focus transition-colors duration-120 ease-out hover:border-accented' : '',
                    ]"
                    :style="{ gridColumn: `${block.start} / span ${block.span}` }"
                    :title="[block.name, block.sub].filter(Boolean).join(' · ')"
                    :data-live="block.live || undefined"
                    :data-future="block.future || undefined"
                    data-block
                >
                    <PersonAvatar v-if="block.name" :name="block.name" :size="22" />
                    <span class="min-w-0">
                        <b v-if="block.name" class="block truncate font-semibold text-highlighted">{{ block.name }}</b>
                        <small v-if="block.sub" class="block truncate text-[10.5px] text-muted">{{ block.sub }}</small>
                    </span>
                    <span v-if="block.live" class="live absolute top-1/2 right-2 -mt-[3.5px] !mr-0" role="img" aria-label="on duty now" />
                </component>
            </div>
        </section>
    </div>
</template>
