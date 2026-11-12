<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import ConnectionBar from '@/Components/ConnectionBar.vue';
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
    }>(),
    { current: undefined, title: undefined, branch: undefined, shift: undefined, user: undefined, canSwitch: undefined },
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

function signOut(): void {
    router.post(route('logout'));
}
</script>

<template>
    <AppShell :nav="nav" :current="current" :title="title" :user="me" :can-switch="switchable" @sign-out="signOut" @switch-user="switching = true">
        <template #status>
            <StatusLabel v-if="branchName" tone="primary" :label="branchName" />
            <StatusLabel v-if="shiftChip" :tone="shiftChip.tone" :label="shiftChip.label" />
            <UTooltip v-if="shiftChip?.closable" text="Closing the shift is not built yet" :delay-duration="400">
                <UButton color="neutral" variant="outline" size="sm" label="Close shift" disabled />
            </UTooltip>
        </template>
        <template #bars>
            <ConnectionBar />
            <slot name="bars" />
        </template>
        <slot />
        <PinSwitch v-if="shared.auth" v-model:open="switching" :members="members" :locked="shared.pinLocked" />
    </AppShell>
</template>
