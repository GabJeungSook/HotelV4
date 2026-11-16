<!-- Mockup 32 (queue) and 35 (nothing to clean) -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import TaskCard from '@/Components/TaskCard.vue';
import { formatDateTime } from '@/Composables/useDateTime';
import { usePolling } from '@/Composables/usePolling';
import { useShared } from '@/Composables/useShared';
import PhoneLayout from '@/Layouts/PhoneLayout.vue';

/**
 * The housekeeper's queue (32) and its empty state (35), APP_FLOW §4.17. Oldest check-out first
 * on the user's floors; only as many rooms as there are housekeepers online can be started, so
 * nobody skips ahead. It reloads every 20 seconds (each load is the last_seen_at heartbeat).
 */
interface Task {
    id: number;
    room: string;
    type: string | null;
    detail: string;
    waiting_minutes: number;
    urgent: boolean;
    tone: 'neutral' | 'warn' | 'bad';
    startable: boolean;
    next_in_line: boolean;
}

const props = defineProps<{
    user_name: string;
    floors: string;
    online: number;
    tasks: Task[];
    current: { id: number; room: string; started_at: string | null } | null;
}>();

const { refresh } = usePolling(['tasks', 'online', 'current'], 20);
// Browser Back restores this page from history with old props (a room started meanwhile would
// still offer "Start cleaning"), so ask the server again as soon as the page is shown.
onMounted(refresh);

const { flash } = useShared();
const starting = ref<number | null>(null);
const error = computed(() => flash.value.error?.message);
const subtitle = computed(() => (props.tasks.length ? `${props.user_name} · ${props.online} ${props.online === 1 ? 'housekeeper' : 'housekeepers'} online` : props.user_name));

function start(task: Task): void {
    starting.value = task.id;
    router.post(route('housekeeping.start', task.id), {}, { preserveScroll: true, onFinish: () => (starting.value = null) });
}
</script>

<template>
    <PhoneLayout :title="`To clean · ${floors}`" :subtitle="subtitle" current="queue">
        <UAlert v-if="error" color="error" variant="subtle" icon="i-solar-danger-triangle-bold-duotone" :description="error" />

        <article v-if="current" class="rounded-[10px] border border-default bg-violet-50 p-3" data-current-task>
            <b class="text-xl leading-7 font-semibold text-highlighted tabular">Cleaning Room {{ current.room }}</b>
            <p v-if="current.started_at" class="text-[13px] leading-[18px] text-muted">Started {{ formatDateTime(current.started_at, 'time') }} · finish it before you start another room</p>
            <UButton class="mt-3 h-12 justify-center" size="xl" block :to="route('housekeeping.show', current.id)" :label="`Back to Room ${current.room}`" />
        </article>

        <TaskCard
            v-for="t in tasks"
            :key="t.id"
            :room="t.room"
            :waiting-minutes="t.waiting_minutes"
            :tone="t.tone"
            :detail="t.detail"
            :startable="t.startable"
            :urgent="t.urgent"
            :next-in-line="t.next_in_line"
            :processing="starting === t.id"
            @start="start(t)"
        />

        <EmptyState
            v-if="!tasks.length && !current"
            class="mt-16"
            icon="i-solar-broom-bold-duotone"
            title="All rooms are clean"
            text="New rooms show up here as soon as a guest checks out."
        />
    </PhoneLayout>
</template>
