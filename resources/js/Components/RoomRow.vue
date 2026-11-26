<script setup lang="ts">
import { computed } from 'vue';
import { formatDateTime, formatDuration } from '@/Composables/useDateTime';
import { formatMoney } from '@/Composables/useMoney';
import { roomStatus, type RoomDisplayStatus } from '@/types/ui';
import PersonPill from './PersonPill.vue';
import StatusLabel from './StatusLabel.vue';

/** One room on the board (DESIGN_SYSTEM §2.3, §5.2). Rows are never tinted; only the label has colour. */
export interface BoardRoom {
    id: number;
    number: string;
    type: string;
    /** Display key from BoardQuery; the page never works it out. */
    status: RoomDisplayStatus;
    /** Initial + surname ("R. Manalastas"). */
    guest?: string | null;
    /** A reserved stay arriving today on a Vacant room (ISO time). */
    reservedAt?: string | null;
    /** expected_checkout_at (ISO). */
    dueOutAt?: string | null;
    /** Minutes left (negative = over) for occupied rooms; minutes waiting or cleaning for housekeeping. */
    minutes?: number | null;
    /** Seconds left on a kiosk hold. */
    holdSeconds?: number | null;
    /** Unpaid balance in centavos. */
    balance?: number | null;
    /** The guest asked to check out at the kiosk; the desk still finalises it. */
    checkoutRequested?: boolean;
}

const props = withDefaults(defineProps<{ room: BoardRoom; now?: string | number | Date }>(), { now: () => Date.now() });
const emit = defineEmits<{ select: [room: BoardRoom] }>();

const status = computed(() => roomStatus[props.room.status]);
const actionable = computed(() => !['to_clean', 'cleaning', 'blocked'].includes(props.room.status));

// `now` ticks every second (the board passes a live clock), so the countdown runs on its own
// without waiting for a server refresh. Seconds come straight from the room's checkout time.
const nowMs = computed(() => new Date(props.now as string | number | Date).getTime());
const secondsLeft = computed<number | null>(() => {
    if (!['occupied', 'due_out', 'overdue'].includes(props.room.status) || !props.room.dueOutAt) {
        return null;
    }

    return Math.round((new Date(props.room.dueOutAt).getTime() - nowMs.value) / 1000);
});

/** An in-house room with an hour or less left, or already over: the whole row goes red (like v2). */
const nearlyDue = computed(() => secondsLeft.value !== null && secondsLeft.value <= 3600);

const timeTone = computed(() => {
    if (secondsLeft.value === null) {
        return 'text-muted';
    }

    return secondsLeft.value < 0 ? 'font-semibold text-bad' : secondsLeft.value <= 3600 ? 'font-semibold text-warn' : 'text-muted';
});

const guestText = computed(() => {
    if (props.room.guest) {
        return props.room.guest;
    }

    if (props.room.status === 'vacant' && props.room.reservedAt) {
        return `Reserved · ${formatDateTime(props.room.reservedAt, 'time')}`;
    }

    return '—';
});

const dueOut = computed(() => (props.room.dueOutAt ? formatDateTime(props.room.dueOutAt, 'auto', props.now) : '—'));

const timeText = computed(() => {
    const { status: s, minutes, holdSeconds } = props.room;

    if (s === 'held' && holdSeconds !== null && holdSeconds !== undefined) {
        return `hold ${formatDuration(holdSeconds, 'countdown')}`;
    }

    if (secondsLeft.value !== null) {
        return formatDuration(secondsLeft.value, 'live');
    }

    if (minutes === null || minutes === undefined) {
        return '';
    }

    if (s === 'to_clean') {
        return `waiting ${formatDuration(minutes, 'board').replace(' left', '')}`;
    }

    if (s === 'cleaning') {
        return `cleaning ${formatDuration(minutes, 'board').replace(' left', '')}`;
    }

    return '';
});

const accessibleName = computed(() => {
    const parts = [`Room ${props.room.number}`, props.room.type, status.value.label];

    if (props.room.guest) {
        parts.push(props.room.guest);
    }

    if (props.room.dueOutAt) {
        parts.push(`due ${dueOut.value}`);
    }

    if (secondsLeft.value !== null) {
        const mins = Math.round(Math.abs(secondsLeft.value) / 60);
        const h = Math.floor(mins / 60);
        const m = mins % 60;
        const span = h > 0 ? `${h} hour${h === 1 ? '' : 's'}${m ? ` ${m} minute${m === 1 ? '' : 's'}` : ''}` : `${m} minute${m === 1 ? '' : 's'}`;
        parts.push(secondsLeft.value < 0 ? `${span} over` : `${span} left`);
    } else if (timeText.value) {
        parts.push(timeText.value.replace(/(\d+)h/, '$1 hours').replace(/(\d+)m\b/, '$1 minutes'));
    }

    if (props.room.balance) {
        parts.push(`balance ${formatMoney(props.room.balance)}`);
    }

    if (props.room.checkoutRequested) {
        parts.push('checkout requested at kiosk');
    }

    return parts.join(', ');
});

function open(): void {
    if (actionable.value) {
        emit('select', props.room);
    }
}
</script>

<template>
    <tr
        :tabindex="actionable ? 0 : undefined"
        :aria-label="accessibleName"
        class="group text-[12.5px] leading-5 outline-none focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary"
        :class="[
            actionable ? 'cursor-pointer' : '',
            nearlyDue ? 'bg-bad-100' : actionable ? 'hover:bg-(--hm-row-hover)' : '',
            room.status === 'blocked' ? 'text-muted' : 'text-default',
        ]"
        :data-status="room.status"
        @click="open"
        @keydown.enter.prevent="open"
    >
        <td class="w-16 py-[9px] ps-5 pe-2 text-[13px] font-semibold tabular" :class="room.status === 'blocked' ? 'text-muted' : 'text-highlighted'">{{ room.number }}</td>
        <td class="px-2 py-2.5 whitespace-nowrap">{{ room.type }}</td>
        <td class="px-2 py-2.5"><StatusLabel :tone="status.tone" :label="status.label" /></td>
        <td class="max-w-[17rem] px-2 py-[7px]" :class="room.reservedAt && !room.guest ? 'text-muted' : ''">
            <div class="flex items-center gap-2">
                <span class="min-w-0 truncate">
                    <PersonPill v-if="room.guest" :name="room.guest" />
                    <template v-else>{{ guestText }}</template>
                </span>
                <StatusLabel v-if="room.checkoutRequested" tone="info" label="Checkout requested" class="shrink-0" />
            </div>
        </td>
        <td class="px-2 py-2.5 whitespace-nowrap tabular">{{ dueOut }}</td>
        <td
            class="px-2 py-2.5 whitespace-nowrap tabular"
            :class="timeTone"
            data-time
        >
            {{ timeText }}
        </td>
        <td class="px-2 py-2.5 text-right whitespace-nowrap num" :class="room.status === 'blocked' ? 'text-muted' : ''">{{ room.balance ? formatMoney(room.balance) : '' }}</td>
        <td class="w-20 py-2.5 ps-2 pe-5 text-right whitespace-nowrap">
            <span v-if="room.status === 'vacant'" class="font-medium text-primary">Check in</span>
            <span v-else-if="actionable" class="text-muted" aria-hidden="true">›</span>
        </td>
    </tr>
</template>
