<!-- Mockup 34 -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import { formatDateTime } from '@/Composables/useDateTime';
import PhoneLayout from '@/Layouts/PhoneLayout.vue';

/**
 * My cleans today (34): the housekeeper's own record for the day. "Late" means finished after the
 * deadline counted from the check-out; each clean is counted once, against the stay that left.
 */
const props = defineProps<{
    date: string;
    cleans: { id: number; room: string; finished_at: string; took_minutes: number | null; late: boolean; problem: boolean }[];
    count: number;
    late: number;
    average_minutes: number | null;
    late_note: string | null;
}>();

const subtitle = computed(() => {
    const day = formatDateTime(`${props.date}T12:00:00+08:00`, 'shiftDate');

    return `${day} · ${props.count} ${props.count === 1 ? 'room' : 'rooms'} · ${props.late} late`;
});
</script>

<template>
    <PhoneLayout title="My cleans today" :subtitle="subtitle" current="history">
        <div class="grid grid-cols-2 gap-3">
            <StatCard label="Average" :value="average_minutes === null ? '—' : `${average_minutes} min`" />
            <StatCard label="Late" :value="String(late)" :tone="late > 0 ? 'bad' : undefined" />
        </div>

        <section class="overflow-x-auto rounded-[10px] border border-default bg-default p-4">
            <table v-if="cleans.length" class="dtable w-full">
                <thead>
                    <tr>
                        <th>Room</th>
                        <th>Finished</th>
                        <th class="text-right">Took</th>
                        <th><span class="sr-only">Status</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in cleans" :key="c.id">
                        <td class="tabular text-highlighted">{{ c.room }}</td>
                        <td class="tabular">{{ formatDateTime(c.finished_at, 'time') }}</td>
                        <td class="num text-right">{{ c.took_minutes === null ? '—' : `${c.took_minutes} m` }}</td>
                        <td class="text-right">
                            <StatusLabel v-if="c.late" tone="bad" label="Late" />
                            <StatusLabel v-else-if="c.problem" tone="warn" label="Problem" />
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-else icon="i-solar-broom-bold-duotone" title="No rooms cleaned yet today" text="Finished rooms show up here." />
        </section>
        <p v-if="late_note" class="text-[13px] text-muted">{{ late_note }}</p>
        <!-- Not in the mockup: the phone needs a way out at the end of the day (the header keeps its two links). -->
        <UButton class="mt-4 h-12 justify-center" size="xl" color="neutral" variant="ghost" block icon="i-solar-logout-2-bold-duotone" label="Sign out" @click="router.post(route('logout'))" />
    </PhoneLayout>
</template>
