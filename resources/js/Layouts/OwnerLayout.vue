<script setup lang="ts">
import StatusLabel from '@/Components/StatusLabel.vue';
import AppShell from './AppShell.vue';
import { ownerNav } from './nav';

/** Owner shell (DESIGN_SYSTEM §6.1): "All branches" or the opened branch. Static nav until Phase 2. */
withDefaults(defineProps<{ current?: string; title?: string; branch?: string; user?: { name: string; role: string } }>(), {
    current: undefined,
    title: undefined,
    branch: 'All branches',
    user: undefined,
});
</script>

<template>
    <AppShell :nav="ownerNav" :current="current" :title="title" :user="user">
        <template #status><StatusLabel tone="primary" :label="branch" /></template>
        <slot />
    </AppShell>
</template>
