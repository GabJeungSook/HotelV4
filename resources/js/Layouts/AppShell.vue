<script setup lang="ts">
import { useLocalStorage } from '@vueuse/core';
import { Head } from '@inertiajs/vue3';
import { computed, provide, reactive, ref } from 'vue';
import ServerErrorDialog from '@/Components/ServerErrorDialog.vue';
import SidebarNav, { type SidebarBranch } from '@/Components/SidebarNav.vue';
import TopBar from '@/Components/TopBar.vue';
import { useConnection } from '@/Composables/useConnection';
import { PAGE_HEADER, type PageHeaderState } from '@/Composables/usePageHeader';
import type { NavGroup, NavItem } from '@/types/ui';

/**
 * The desktop app shell shared by the four staff layouts (DESIGN_SYSTEM §0, §6.1; mockup v6): a
 * 232 px white sidebar that collapses to a 64 px rail (remembered), a drawer under 768 px, the
 * 56 px top bar with breadcrumbs, and the page header (ink icon tile, title, one sub-line, the
 * page's actions) above the grey page with 28 px side padding (16 px under 768 px).
 */
const props = withDefaults(
    defineProps<{
        nav: NavGroup[];
        current?: string;
        title?: string;
        subtitle?: string;
        user?: { name: string; role: string };
        canSwitch?: boolean;
        branch?: SidebarBranch;
        searchRoute?: string;
        /** Pages that draw their own heading (none today) can switch the page header off. */
        header?: boolean;
    }>(),
    { current: undefined, title: undefined, subtitle: undefined, user: undefined, canSwitch: false, branch: undefined, searchRoute: undefined, header: true },
);

const emit = defineEmits<{ signOut: []; switchUser: [] }>();

const startCollapsed = typeof window !== 'undefined' && window.innerWidth < 1100;
const collapsed = useLocalStorage('homi.sidebar.collapsed', startCollapsed);
const drawer = ref(false);
const help = ref(false);
const { online } = useConnection();

const state = reactive<PageHeaderState>({ sub: undefined });
provide(PAGE_HEADER, state);

/** The current nav entry, its parent (for a folded child) and its group heading. */
const where = computed<{ item?: NavItem; parent?: NavItem; heading?: string }>(() => {
    for (const g of props.nav) {
        for (const item of g.items) {
            if (item.key === props.current) {
                return { item, heading: g.heading };
            }

            const child = item.children?.find((c) => c.key === props.current);

            if (child) {
                return { item: child, parent: item, heading: g.heading };
            }
        }
    }

    return {};
});

const pageTitle = computed(() => props.title ?? where.value.item?.label ?? '');
const icon = computed(() => where.value.parent?.icon ?? where.value.item?.icon ?? 'i-solar-list-bold-duotone');
// The nav item's sub-line belongs to the page of that name only, not to pages below it (37 under 37a).
const sub = computed(() => state.sub ?? props.subtitle ?? (pageTitle.value === where.value.item?.label ? where.value.item?.subtitle : null) ?? props.branch?.name ?? '');
const crumbs = computed(() => {
    const w = where.value;
    const short = pageTitle.value.split(' · ')[0].split(' — ')[0];
    const parts = [w.heading, w.parent?.label, w.item?.label, short].filter((x): x is string => !!x);

    return parts.filter((x, i) => parts.indexOf(x) === i);
});

const keys = [
    ['Enter', 'Saves or confirms the form you are in'],
    ['Esc', 'Closes a dialog or side panel'],
    ['Tab', 'Moves to the next field or button'],
    ['Ctrl K', 'Searches rooms and guests (front desk)'],
];
</script>

<template>
    <div class="flex min-h-dvh bg-muted">
        <ServerErrorDialog />
        <Head v-if="pageTitle" :title="pageTitle" />
        <aside
            data-shell
            class="sticky top-0 h-dvh shrink-0 border-r border-default transition-[width] duration-160 ease-out max-md:hidden"
            :class="collapsed ? 'w-16' : 'w-[232px]'"
        >
            <SidebarNav
                :groups="nav"
                :current="current"
                :collapsed="collapsed"
                :branch="branch"
                :user="user"
                :can-switch="canSwitch"
                @toggle="collapsed = !collapsed"
                @sign-out="emit('signOut')"
                @switch-user="emit('switchUser')"
                @help="help = true"
            />
        </aside>
        <USlideover v-model:open="drawer" side="left" title="Menu" :ui="{ content: 'max-w-[min(280px,85vw)]', header: 'sr-only', body: 'p-0' }">
            <template #body>
                <div class="flex h-full flex-col">
                    <div class="flex flex-wrap gap-2 border-b border-default px-4 py-3"><slot name="status" /></div>
                    <SidebarNav
                        class="flex-1"
                        :groups="nav"
                        :current="current"
                        :branch="branch"
                        :user="user"
                        :can-switch="canSwitch"
                        drawer
                        @navigate="drawer = false"
                        @sign-out="emit('signOut')"
                        @switch-user="emit('switchUser')"
                        @help="help = true"
                    />
                </div>
            </template>
        </USlideover>
        <div class="flex min-w-0 flex-1 flex-col">
            <TopBar :crumbs="crumbs" :search-route="searchRoute" @menu="drawer = true">
                <template #status><slot name="status" /></template>
            </TopBar>
            <slot name="bars" />
            <div v-if="header && pageTitle" class="no-print flex items-center gap-3.5 px-7 pt-6 max-md:px-4" data-page-header>
                <span class="grid size-11 shrink-0 place-items-center rounded-[11px] bg-primary text-inverted" aria-hidden="true">
                    <UIcon :name="icon" class="size-[21px]" />
                </span>
                <div class="min-w-0 flex-1">
                    <h1 class="flex flex-wrap items-center gap-x-2 text-[21px] leading-[1.2] font-semibold tracking-[-0.018em] text-highlighted">
                        <span class="min-w-0 truncate">{{ pageTitle }}</span>
                        <span id="page-status" class="inline-flex items-center gap-2 empty:hidden" />
                    </h1>
                    <small v-if="sub" class="mt-[3px] block truncate text-[12.5px] text-muted" data-page-sub>{{ sub }}</small>
                </div>
                <div id="page-actions" class="flex shrink-0 flex-wrap items-center justify-end gap-2 empty:hidden" />
            </div>
            <h1 v-if="!header && pageTitle" class="sr-only">{{ pageTitle }}</h1>
            <main class="min-w-0 flex-1 px-7 pt-5 pb-7 max-md:px-4" :class="online ? '' : 'opacity-50'">
                <!-- While the connection bar shows, every control on the page is disabled (50). -->
                <fieldset :disabled="!online" class="contents"><slot /></fieldset>
            </main>
        </div>
        <UModal v-model:open="help" title="Help" description="Keys that work on every page. For the one-page guide for your role, ask your branch admin.">
            <template #body>
                <dl class="grid grid-cols-[88px_1fr] gap-x-4 gap-y-2.5 text-[13px]">
                    <template v-for="[k, text] in keys" :key="k">
                        <dt><kbd class="rounded-[5px] border border-default bg-muted px-1.5 py-0.5 text-[11.5px] font-medium text-default">{{ k }}</kbd></dt>
                        <dd class="text-default">{{ text }}</dd>
                    </template>
                </dl>
            </template>
        </UModal>
    </div>
</template>
