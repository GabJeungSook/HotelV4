<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import StatusLabel from '@/Components/StatusLabel.vue';
import type { Tone } from '@/types/ui';

/**
 * Close step 1 — check (22). What the next team inherits, and the papers to have in hand before
 * counting, as counts only: no peso amount reaches this page, so nothing hints at the expected
 * cash. Amber items do not block. "Next: count cash" sets the shift to closing.
 */
export interface CheckRow {
    tone: Tone;
    label: string;
    text: string;
    stay_id?: number;
}

export interface Paper {
    label: string;
    count: string;
}

defineProps<{ rows: CheckRow[]; papers: Paper[] }>();

const form = useForm({ step: 'begin' });

function next(): void {
    form.post(route('shift.close.store'), { preserveScroll: true });
}
</script>

<template>
    <div class="grid grid-cols-2 gap-4 max-[900px]:grid-cols-1">
        <section class="rounded-card border border-default bg-default p-5">
            <h2 class="mb-2 text-sm font-semibold text-highlighted">Passed on to the next shift</h2>
            <table class="dtable dense w-full">
                <tbody>
                    <tr v-for="(row, i) in rows" :key="i" data-check-row>
                        <td class="w-24"><StatusLabel :tone="row.tone" :label="row.label" /></td>
                        <td>
                            <div class="flex items-center justify-between gap-2">
                                <span>{{ row.text }}</span>
                                <UButton v-if="row.stay_id" size="xs" color="neutral" variant="outline" label="Open folio" @click="router.visit(route('stays.show', row.stay_id))" />
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
        <section class="rounded-card border border-default bg-default p-5">
            <h2 class="mb-2 text-sm font-semibold text-highlighted">Have these with you before you count</h2>
            <table class="dtable dense w-full">
                <tbody>
                    <tr v-for="paper in papers" :key="paper.label" data-paper>
                        <td>{{ paper.label }}</td>
                        <td class="r">{{ paper.count }}</td>
                    </tr>
                </tbody>
            </table>
            <p class="mt-2 text-[13px] text-muted">Counts only. No peso amounts are shown before the drawer is counted.</p>
        </section>
    </div>
    <div class="mt-4 flex justify-end gap-2">
        <UButton color="neutral" variant="ghost" label="Not yet" @click="router.visit(route('today'))" />
        <UButton label="Next: count cash" :loading="form.processing" data-next @click="next" />
    </div>
</template>
