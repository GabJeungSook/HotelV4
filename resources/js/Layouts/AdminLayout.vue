<script setup lang="ts">
import StatusLabel from '@/Components/StatusLabel.vue';
import AppShell from './AppShell.vue';
import { adminNav } from './nav';

/** Branch admin shell (DESIGN_SYSTEM §6.1). Static nav until the server sends it (Phase 2). */
withDefaults(defineProps<{ current?: string; title?: string; branch?: string; user?: { name: string; role: string } }>(), {
    current: undefined,
    title: undefined,
    branch: undefined,
    user: undefined,
});
</script>

<template>
    <AppShell :nav="adminNav" :current="current" :title="title" :user="user">
        <template #status><StatusLabel v-if="branch" tone="primary" :label="branch" /></template>
        <slot />
    </AppShell>
</template>
