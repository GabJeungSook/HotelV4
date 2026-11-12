<script setup lang="ts">
import StatusLabel from '@/Components/StatusLabel.vue';
import type { Tone } from '@/types/ui';
import AppShell from './AppShell.vue';
import { frontDeskNav } from './nav';

/** Front desk and supervisor shell (DESIGN_SYSTEM §6.1). Static nav until the server sends it (Phase 2). */
withDefaults(
    defineProps<{
        current?: string;
        title?: string;
        branch?: string;
        shift?: { label: string; tone: Tone; closable?: boolean };
        user?: { name: string; role: string };
        canSwitch?: boolean;
    }>(),
    { current: undefined, title: undefined, branch: undefined, shift: undefined, user: undefined, canSwitch: false },
);
</script>

<template>
    <AppShell :nav="frontDeskNav" :current="current" :title="title" :user="user" :can-switch="canSwitch">
        <template #status>
            <StatusLabel v-if="branch" tone="primary" :label="branch" />
            <StatusLabel v-if="shift" :tone="shift.tone" :label="shift.label" />
            <UButton v-if="shift?.closable" color="neutral" variant="outline" label="Close shift" />
        </template>
        <template #bars><slot name="bars" /></template>
        <slot />
    </AppShell>
</template>
