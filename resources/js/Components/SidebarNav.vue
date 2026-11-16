<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui';
import { Link } from '@inertiajs/vue3';
import { useColorMode } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import type { NavGroup, NavItem } from '@/types/ui';
import HomiLogo from './HomiLogo.vue';
import PersonAvatar from './PersonAvatar.vue';

/**
 * The sidebar (DESIGN_SYSTEM §0, §6.1; mockup v6 `.side`): logo and collapse button, the branch
 * switcher card, uppercase group labels, links with icons (a parent with children folds open with
 * an indented guide line), then Notifications and Help, and the user card whose menu holds Switch
 * user, Appearance and Sign out. Collapses to a 64 px icon rail with tooltips.
 */
export interface SidebarBranch {
    name: string;
    sub?: string;
    /** Owner only: "All branches" and each branch, to open one. */
    items?: DropdownMenuItem[];
}

const props = withDefaults(
    defineProps<{
        groups: NavGroup[];
        current?: string;
        collapsed?: boolean;
        drawer?: boolean;
        branch?: SidebarBranch;
        user?: { name: string; role: string };
        canSwitch?: boolean;
        notifications?: { route: string; count?: number };
    }>(),
    { current: undefined, collapsed: false, drawer: false, branch: undefined, user: undefined, canSwitch: false, notifications: undefined },
);

const emit = defineEmits<{ toggle: []; navigate: []; signOut: []; switchUser: []; help: [] }>();
const mode = useColorMode({ emitAuto: true });

const isOn = (item: NavItem) => item.key === props.current;
const hasCurrentChild = (item: NavItem) => !!item.children?.some((c) => c.key === props.current);

/** Parents start open when one of their children is the current page; the user can fold them. */
const open = ref<Record<string, boolean>>({});
watch(
    () => props.current,
    () => {
        props.groups.forEach((g) => g.items.forEach((i) => i.children && hasCurrentChild(i) && (open.value[i.key] = true)));
    },
    { immediate: true },
);

const initials = computed(() => {
    const name = props.branch?.name ?? '';

    if (name === 'All branches') {
        return 'AB';
    }

    const words = name.replace(/^Hotel\s+/i, '').split(/\s+/).filter(Boolean);

    return words
        .slice(0, 2)
        .map((w) => w[0])
        .join('')
        .toUpperCase();
});
const branchLabel = computed(() => (props.branch?.name ?? '').replace(/^Hotel\s+/i, ''));

const userItems = computed<DropdownMenuItem[][]>(() => [
    [{ label: props.user?.role ?? '', type: 'label' }],
    ...(props.canSwitch ? [[{ label: 'Switch user', icon: 'i-lucide-users', onSelect: () => emit('switchUser') }]] : []),
    [
        { label: 'Appearance', type: 'label' },
        { label: 'Light', icon: 'i-lucide-sun', type: 'checkbox', checked: mode.value === 'light', onSelect: () => (mode.value = 'light') },
        { label: 'Dark', icon: 'i-lucide-moon', type: 'checkbox', checked: mode.value === 'dark', onSelect: () => (mode.value = 'dark') },
        { label: 'System', icon: 'i-lucide-monitor', type: 'checkbox', checked: mode.value === 'auto', onSelect: () => (mode.value = 'auto') },
    ],
    [{ label: 'Sign out', icon: 'i-lucide-log-out', onSelect: () => emit('signOut') }],
]);

const linkBase =
    'relative flex items-center gap-2.5 rounded-lg border px-2 text-[13px] leading-5 whitespace-nowrap outline-none transition-colors duration-120 ease-out focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-inset';
</script>

<template>
    <nav aria-label="Main" class="flex h-full flex-col overflow-y-auto bg-sidebar px-3 pt-3.5 pb-3 text-[13px]">
        <div class="flex items-center justify-between px-1.5 pb-3.5" :class="collapsed ? 'flex-col gap-3 px-0' : ''">
            <img v-if="collapsed" src="/favicon.svg" width="28" height="28" alt="Homi" class="block size-7" />
            <HomiLogo v-else :variant="mode === 'dark' ? 'white' : 'blue'" :width="72" :height="22" />
            <UTooltip v-if="!drawer" :text="collapsed ? 'Expand sidebar' : 'Collapse sidebar'" :delay-duration="400">
                <button
                    type="button"
                    class="hm-focus grid size-[26px] cursor-pointer place-items-center rounded-[7px] border border-default text-muted hover:bg-elevated"
                    :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                    @click="emit('toggle')"
                >
                    <UIcon name="i-lucide-panel-left" class="size-3.5" aria-hidden="true" />
                </button>
            </UTooltip>
        </div>

        <template v-if="branch && !collapsed">
            <UDropdownMenu v-if="branch.items?.length" :items="branch.items" :content="{ align: 'start' }" :ui="{ content: 'w-[208px]' }">
                <button type="button" class="hm-focus flex w-full cursor-pointer items-center gap-2.5 rounded-[10px] border border-default px-2.5 py-2 text-left shadow-xs hover:bg-elevated" data-branch-switcher>
                    <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-primary-50 text-xs font-semibold text-primary">{{ initials }}</span>
                    <span class="min-w-0">
                        <b class="block truncate text-[13px] leading-tight font-semibold">{{ branchLabel }}</b>
                        <small v-if="branch.sub" class="block truncate text-[11.5px] leading-tight text-muted">{{ branch.sub }}</small>
                    </span>
                    <UIcon name="i-lucide-chevrons-up-down" class="ms-auto size-3.5 shrink-0 text-faint" aria-hidden="true" />
                </button>
            </UDropdownMenu>
            <div v-else class="flex items-center gap-2.5 rounded-[10px] border border-default px-2.5 py-2 shadow-xs" data-branch-switcher>
                <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-primary-50 text-xs font-semibold text-primary" aria-hidden="true">{{ initials }}</span>
                <span class="min-w-0">
                    <b class="block truncate text-[13px] leading-tight font-semibold">{{ branchLabel }}</b>
                    <small v-if="branch.sub" class="block truncate text-[11.5px] leading-tight text-muted">{{ branch.sub }}</small>
                </span>
            </div>
        </template>

        <div v-for="group in groups" :key="group.heading">
            <p v-if="!collapsed" class="px-2 pt-[18px] pb-2 text-[11px] leading-none font-medium tracking-[0.04em] text-faint uppercase">{{ group.heading }}</p>
            <USeparator v-else class="my-2" />
            <ul class="flex flex-col gap-0.5">
                <li v-for="item in group.items" :key="item.key">
                    <template v-if="item.children && !collapsed">
                        <button
                            type="button"
                            :class="[linkBase, 'h-[34px] w-full cursor-pointer border-transparent font-medium text-default hover:bg-elevated']"
                            :aria-expanded="!!open[item.key]"
                            @click="open[item.key] = !open[item.key]"
                        >
                            <UIcon :name="item.icon" class="size-[17px] shrink-0" :class="hasCurrentChild(item) ? 'text-primary' : 'text-muted'" aria-hidden="true" />
                            <span class="truncate">{{ item.label }}</span>
                            <UIcon :name="open[item.key] ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'" class="ms-auto size-3.5 text-faint" aria-hidden="true" />
                        </button>
                        <ul v-if="open[item.key]" class="mt-0.5 mb-1 ms-4 flex flex-col gap-0.5 border-l border-default ps-3">
                            <li v-for="child in item.children" :key="child.key">
                                <Link
                                    :href="child.route"
                                    :class="[linkBase, 'h-[30px] border-transparent', isOn(child) ? 'bg-nav-active font-medium text-highlighted' : 'text-muted hover:bg-elevated hover:text-default']"
                                    :aria-current="isOn(child) ? 'page' : undefined"
                                    @click="emit('navigate')"
                                    >{{ child.label }}</Link
                                >
                            </li>
                        </ul>
                    </template>
                    <UTooltip v-else :text="item.label" :disabled="!collapsed" :delay-duration="400" :content="{ side: 'right' }">
                        <Link
                            :href="item.route"
                            :class="[
                                linkBase,
                                'h-[34px] font-medium max-md:h-11',
                                isOn(item) || hasCurrentChild(item) ? 'border-default bg-nav-active text-highlighted' : 'border-transparent text-default hover:bg-elevated',
                                collapsed ? 'justify-center' : '',
                            ]"
                            :aria-current="isOn(item) ? 'page' : undefined"
                            :aria-label="collapsed ? item.label : undefined"
                            @click="emit('navigate')"
                        >
                            <UIcon :name="item.icon" class="size-[17px] shrink-0" :class="isOn(item) || hasCurrentChild(item) ? 'text-primary' : 'text-muted'" aria-hidden="true" />
                            <span v-if="!collapsed" class="truncate">{{ item.label }}</span>
                            <span v-if="item.count && !collapsed" class="ms-auto rounded-full bg-bad px-1.5 text-[10.5px] leading-[17px] font-semibold text-on-primary">{{ item.count }}</span>
                            <span v-if="item.count && collapsed" class="absolute top-1.5 right-1.5 size-1.5 rounded-full bg-bad" aria-hidden="true" />
                        </Link>
                    </UTooltip>
                </li>
            </ul>
        </div>

        <div class="mt-auto flex flex-col gap-0.5 pt-3">
            <UTooltip v-if="notifications" text="Notifications" :disabled="!collapsed" :content="{ side: 'right' }">
                <Link
                    :href="notifications.route"
                    :class="[linkBase, 'h-[34px] border-transparent font-medium text-default hover:bg-elevated', collapsed ? 'justify-center' : '']"
                    :aria-label="collapsed ? 'Notifications' : undefined"
                    @click="emit('navigate')"
                >
                    <UIcon name="i-lucide-bell" class="size-[17px] shrink-0 text-muted" aria-hidden="true" />
                    <span v-if="!collapsed">Notifications</span>
                    <span v-if="notifications.count && !collapsed" class="ms-auto rounded-full bg-bad px-1.5 text-[10.5px] leading-[17px] font-semibold text-on-primary">{{ notifications.count }}</span>
                </Link>
            </UTooltip>
            <UTooltip text="Help" :disabled="!collapsed" :content="{ side: 'right' }">
                <button
                    type="button"
                    :class="[linkBase, 'h-[34px] w-full cursor-pointer border-transparent font-medium text-default hover:bg-elevated', collapsed ? 'justify-center' : '']"
                    :aria-label="collapsed ? 'Help' : undefined"
                    @click="emit('help')"
                >
                    <UIcon name="i-lucide-circle-help" class="size-[17px] shrink-0 text-muted" aria-hidden="true" />
                    <span v-if="!collapsed">Help</span>
                </button>
            </UTooltip>

            <UDropdownMenu v-if="user" :items="userItems" :content="{ align: collapsed ? 'start' : 'end', side: collapsed ? 'right' : 'top' }">
                <button
                    type="button"
                    class="hm-focus mt-2 flex cursor-pointer items-center gap-2.5 border-t border-default px-1.5 pt-3 text-left"
                    :class="collapsed ? 'justify-center px-0' : ''"
                    :aria-label="`${user.name}, ${user.role}: open the user menu`"
                    data-user-card
                >
                    <PersonAvatar :name="user.name" :size="32" />
                    <span v-if="!collapsed" class="min-w-0">
                        <b class="block truncate text-[13px] leading-tight font-semibold">{{ user.name }}</b>
                        <small class="block truncate text-[11.5px] text-muted">{{ user.role }}</small>
                    </span>
                    <UIcon v-if="!collapsed" name="i-lucide-ellipsis-vertical" class="ms-auto size-4 shrink-0 text-faint" aria-hidden="true" />
                </button>
            </UDropdownMenu>
        </div>
    </nav>
</template>
