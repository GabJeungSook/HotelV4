<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, onMounted } from 'vue';
import ConnectionBar from '@/Components/ConnectionBar.vue';
import { useConnection } from '@/Composables/useConnection';
import { useFlashToast } from '@/Composables/useFlashToast';
import { useShared } from '@/Composables/useShared';
import { useShell } from '@/Composables/useShell';
import AppShell from './AppShell.vue';
import { ownerNav } from './nav';

/** Owner shell (DESIGN_SYSTEM §6.1): "All branches" or the opened branch, fed by the shared props. */
const props = withDefaults(defineProps<{ current?: string; title?: string; branch?: string; user?: { name: string; role: string }; header?: boolean }>(), {
    header: true,
    current: undefined,
    title: undefined,
    branch: undefined,
    user: undefined,
});

const { props: shared } = useShared();
useFlashToast();
onMounted(() => useConnection().start(shared.value.app?.heartbeat ?? 5));

const nav = computed(() => (shared.value.nav?.length ? shared.value.nav : ownerNav));
const shell = useShell({ branch: () => props.branch, user: () => props.user });
</script>

<template>
    <AppShell
        :nav="nav"
        :current="current"
        :title="title"
        :user="shell.user.value"
        :branch="shell.branch.value"
        :header="header"
        @sign-out="router.post(route('logout'))"
    >
        <template #bars><ConnectionBar /></template>
        <slot />
    </AppShell>
</template>
