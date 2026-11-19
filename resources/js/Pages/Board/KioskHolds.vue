<!-- Mockup 5 (now a permanent rail on the board, not a sheet) -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { useIntervalFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import MoneyRow from '@/Components/MoneyRow.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import { useConfirmDialog } from '@/Composables/useConfirmDialog';
import { formatDateTime, formatDuration } from '@/Composables/useDateTime';

/**
 * Kiosk holds (5): guests who picked a room at the kiosk and are walking to the desk. Lives as a
 * permanent rail on the board; the board reloads `holds` every 5 s (StayQuery::kioskHolds) so the
 * list is live without a refresh, and the time left counts down between reloads. "Take payment"
 * opens the confirm screen (7); "Release room" cancels the hold after the confirm dialog. An
 * expired hold is shown greyed as "Expired 9:41 AM" for an hour.
 */
export interface KioskHold {
    id: number;
    code: string;
    room: string | null;
    guest: string;
    type: string;
    stay: string;
    to_collect: number;
    expires_at: string | null;
    seconds_left: number;
    expired: boolean;
}

const props = defineProps<{ holds: KioskHold[] }>();

const confirm = useConfirmDialog();
const elapsed = ref(0);
const releasing = ref<number | null>(null);

useIntervalFn(() => (elapsed.value += 1), 1000);
watch(
    () => props.holds,
    () => (elapsed.value = 0),
);

const left = (h: KioskHold) => Math.max(0, h.seconds_left - elapsed.value);
const live = computed(() => props.holds.filter((h) => !h.expired));

async function release(hold: KioskHold): Promise<void> {
    const ok = await confirm({
        title: `Release Room ${hold.room}?`,
        description: `${hold.guest}'s hold is cancelled and the room goes back on sale. Nothing was paid, so nothing is refunded.`,
        confirmLabel: 'Release room',
        cancelLabel: 'Keep hold',
        confirmColor: 'error',
    });

    if (!ok) {
        return;
    }

    releasing.value = hold.id;
    router.post(route('stays.release', hold.id), {}, { preserveScroll: true, onFinish: () => (releasing.value = null) });
}
</script>

<template>
    <div>
        <p v-if="holds.length === 0" class="py-8 text-center text-sm text-muted" data-test="no-holds">No kiosk holds right now.</p>
        <div v-else class="flex flex-col gap-3">
            <article
                v-for="h in holds"
                :key="h.id"
                class="rounded-card border p-3.5"
                :class="h.expired || left(h) === 0 ? 'border-default opacity-60' : 'border-primary'"
                :data-hold="h.code"
            >
                <div class="flex items-center justify-between gap-2">
                    <b class="text-[15px] font-semibold text-highlighted tabular">Room {{ h.room }}</b>
                    <StatusLabel v-if="h.expired || left(h) === 0" :label="`Expired ${formatDateTime(h.expires_at, 'time')}`" />
                    <StatusLabel v-else tone="warn" :label="`${formatDuration(left(h), 'countdown')} left`" />
                </div>
                <template v-if="!h.expired && left(h) > 0">
                    <p class="mt-1 text-sm">{{ h.guest }} · {{ h.type }} · {{ h.stay }}</p>
                    <MoneyRow class="mt-2" label="To collect" :amount="h.to_collect" />
                    <div class="mt-3 flex gap-2">
                        <UButton size="sm" label="Take payment" @click="router.visit(route('stays.confirm', h.id))" />
                        <UButton size="sm" color="neutral" variant="outline" label="Release" :loading="releasing === h.id" @click="release(h)" />
                    </div>
                </template>
                <p v-else class="mt-1 text-xs text-muted">{{ h.guest }} · released automatically, room is back on sale</p>
            </article>
        </div>
        <p v-if="live.length" class="mt-4 text-xs text-muted">A scanned hold code in the board search opens the hold too.</p>
    </div>
</template>
