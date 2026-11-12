<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import ConnectionBar from '@/Components/ConnectionBar.vue';
import OverdueBar from '@/Components/OverdueBar.vue';
import PinSwitch from '@/Components/PinSwitch.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import { useConnection } from '@/Composables/useConnection';
import { formatDateTime, formatDuration } from '@/Composables/useDateTime';
import { useFlashToast } from '@/Composables/useFlashToast';
import { useShared } from '@/Composables/useShared';
import type { Tone } from '@/types/ui';
import AppShell from './AppShell.vue';
import { frontDeskNav } from './nav';

/**
 * Front desk and supervisor shell (DESIGN_SYSTEM §6.1). Navigation, branch, shift and user come
 * from the shared props the server sends (SYSTEM_DESIGN §4.3); the props below only override
 * them in the component gallery.
 */
const props = withDefaults(
    defineProps<{
        current?: string;
        title?: string;
        branch?: string;
        shift?: { label: string; tone: Tone; closable?: boolean };
        user?: { name: string; role: string };
        canSwitch?: boolean;
        /** The close guide (22–25) itself: no Close shift button and no closing notice. */
        guide?: boolean;
    }>(),
    { current: undefined, title: undefined, branch: undefined, shift: undefined, user: undefined, canSwitch: undefined, guide: false },
);

const { props: shared } = useShared();
const switching = ref(false);

useFlashToast();
onMounted(() => useConnection().start(shared.value.app?.heartbeat ?? 5));

const nav = computed(() => (shared.value.nav?.length ? shared.value.nav : frontDeskNav));
const branchName = computed(() => props.branch ?? shared.value.branch?.name);
const me = computed(() => props.user ?? (shared.value.auth ? { name: shared.value.auth.user.name, role: shared.value.auth.roleLabel } : undefined));
const members = computed(() => (shared.value.shift?.members ?? []).filter((m) => m.id !== shared.value.auth?.user.id));
const switchable = computed(() => props.canSwitch ?? members.value.length > 0);

const shiftChip = computed<{ label: string; tone: Tone; closable?: boolean } | undefined>(() => {
    if (props.shift) {
        return props.shift;
    }

    const s = shared.value.shift;

    if (!s) {
        return shared.value.auth ? { label: 'No open shift', tone: 'neutral' } : undefined;
    }

    if (s.status === 'closing') {
        return { label: `${s.period_name} shift · closing`, tone: 'warn' };
    }

    if (s.overdue) {
        return { label: `${s.period_name} shift · ${formatDuration(s.minutes_past_end, 'min')} over`, tone: 'warn', closable: true };
    }

    return { label: `${s.period_name} shift · ${formatDateTime(`${s.business_date}T12:00:00+08:00`, 'shiftDate')}`, tone: 'ok', closable: true };
});

// Close step 1 (22) is open to the members and supervisors of an open shift (APP_FLOW §4.16).
const canClose = computed(() => {
    const s = shared.value.shift;
    const me = shared.value.auth;

    return !props.shift && !props.guide && !!s && !!me && (me.role === 'supervisor' || s.members.some((m) => m.id === me.user.id));
});
const overdue = computed(() => (!props.shift && shared.value.shift?.overdue ? shared.value.shift : null));
// "… is closing the shift" (APP_FLOW §3) for the other member, on every front desk page but the guide itself.
const closingNotice = computed(() => {
    const s = shared.value.shift;

    if (props.shift || !s || s.status !== 'closing' || props.guide) {
        return undefined;
    }

    return `${s.closing_by ?? 'A front desk'} is closing the shift. New payments, charges and cash entries are paused until the close is finished or stopped.`;
});

function closeShift(): void {
    router.visit(route('shift.close'));
}

function signOut(): void {
    router.post(route('logout'));
}
</script>

<template>
    <AppShell :nav="nav" :current="current" :title="title" :user="me" :can-switch="switchable" @sign-out="signOut" @switch-user="switching = true">
        <template #status>
            <StatusLabel v-if="branchName" tone="primary" :label="branchName" />
            <StatusLabel v-if="shiftChip" :tone="shiftChip.tone" :label="shiftChip.label" />
            <UButton v-if="props.shift ? props.shift.closable : canClose" color="neutral" variant="outline" size="sm" label="Close shift" data-close-shift @click="closeShift" />
        </template>
        <template #bars>
            <ConnectionBar />
            <OverdueBar
                v-if="overdue"
                :period-name="overdue.period_name"
                :ended-at="overdue.defined_end"
                :blocked="overdue.stale"
                :minutes-past="overdue.minutes_past_end"
                :closable="!guide"
                @close="closeShift"
            />
            <slot name="bars" />
        </template>
        <UAlert v-if="closingNotice" class="no-print mb-4" color="warning" variant="subtle" icon="i-lucide-lock" :description="closingNotice" data-closing-notice />
        <slot />
        <PinSwitch v-if="shared.auth" v-model:open="switching" :members="members" :locked="shared.pinLocked" />
    </AppShell>
</template>
