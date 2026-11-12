<script setup lang="ts">
import { useLocalStorage } from '@vueuse/core';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import SidebarNav from '@/Components/SidebarNav.vue';
import TopBar from '@/Components/TopBar.vue';
import { useConnection } from '@/Composables/useConnection';
import type { NavGroup } from '@/types/ui';

/**
 * The desktop app shell shared by the four staff layouts (DESIGN_SYSTEM §6.1): a 248 px sidebar
 * that collapses to a 64 px rail (remembered), a drawer under 768 px, the 56 px top bar, and the
 * grey page with 24 px padding (16 px under 768 px).
 */
const props = withDefaults(
    defineProps<{
        nav: NavGroup[];
        current?: string;
        title?: string;
        subtitle?: string;
        user?: { name: string; role: string };
        canSwitch?: boolean;
    }>(),
    { current: undefined, title: undefined, subtitle: undefined, user: undefined, canSwitch: false },
);

const emit = defineEmits<{ signOut: []; switchUser: [] }>();

const startCollapsed = typeof window !== 'undefined' && window.innerWidth < 1100;
const collapsed = useLocalStorage('homi.sidebar.collapsed', startCollapsed);
const drawer = ref(false);
const { online } = useConnection();

const fallbackTitle = () => props.nav.flatMap((g) => g.items).find((i) => i.key === props.current)?.label ?? '';
</script>

<template>
    <div class="flex min-h-dvh bg-muted">
        <Head v-if="title ?? fallbackTitle()" :title="title ?? fallbackTitle()" />
        <aside
            data-shell
            class="sticky top-0 h-dvh shrink-0 border-r border-default transition-[width] duration-160 ease-out max-md:hidden"
            :class="collapsed ? 'w-16' : 'w-62'"
        >
            <SidebarNav :groups="nav" :current="current" :collapsed="collapsed" @toggle="collapsed = !collapsed" @sign-out="emit('signOut')" />
        </aside>
        <USlideover v-model:open="drawer" side="left" title="Menu" :ui="{ content: 'max-w-[min(280px,85vw)]', header: 'sr-only', body: 'p-0' }">
            <template #body>
                <div class="flex h-full flex-col">
                    <div class="flex flex-wrap gap-2 border-b border-default px-4 py-3"><slot name="status" /></div>
                    <SidebarNav class="flex-1" :groups="nav" :current="current" drawer @navigate="drawer = false" @sign-out="emit('signOut')" />
                </div>
            </template>
        </USlideover>
        <div class="flex min-w-0 flex-1 flex-col">
            <TopBar :title="title ?? fallbackTitle()" :subtitle="subtitle" :user="user" :can-switch="canSwitch" @menu="drawer = true" @switch-user="emit('switchUser')">
                <template #status><slot name="status" /></template>
            </TopBar>
            <slot name="bars" />
            <main class="min-w-0 flex-1 p-6 max-md:p-4" :class="online ? '' : 'opacity-50'">
                <!-- While the connection bar shows, every control on the page is disabled (50). -->
                <fieldset :disabled="!online" class="contents"><slot /></fieldset>
            </main>
        </div>
    </div>
</template>
