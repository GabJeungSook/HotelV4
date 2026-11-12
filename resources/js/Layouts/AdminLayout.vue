<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, onMounted } from 'vue';
import ConnectionBar from '@/Components/ConnectionBar.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import { useConnection } from '@/Composables/useConnection';
import { useFlashToast } from '@/Composables/useFlashToast';
import { useShared } from '@/Composables/useShared';
import AppShell from './AppShell.vue';
import { adminNav } from './nav';

/** Branch admin shell (DESIGN_SYSTEM §6.1), fed by the shared props; the props override them in the gallery. */
const props = withDefaults(defineProps<{ current?: string; title?: string; branch?: string; user?: { name: string; role: string } }>(), {
    current: undefined,
    title: undefined,
    branch: undefined,
    user: undefined,
});

const { props: shared } = useShared();
useFlashToast();
onMounted(() => useConnection().start(shared.value.app?.heartbeat ?? 5));

const nav = computed(() => (shared.value.nav?.length ? shared.value.nav : adminNav));
const branchName = computed(() => props.branch ?? shared.value.branch?.name);
const me = computed(() => props.user ?? (shared.value.auth ? { name: shared.value.auth.user.name, role: shared.value.auth.roleLabel } : undefined));
</script>

<template>
    <AppShell :nav="nav" :current="current" :title="title" :user="me" @sign-out="router.post(route('logout'))">
        <template #status><StatusLabel v-if="branchName" tone="primary" :label="branchName" /></template>
        <template #bars><ConnectionBar /></template>
        <slot />
    </AppShell>
</template>
