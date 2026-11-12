<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useColorMode } from '@vueuse/core';
import type { NavGroup } from '@/types/ui';
import HomiLogo from './HomiLogo.vue';

/** The sidebar (DESIGN_SYSTEM §5.2, §6.1): logo, grouped links, a rule, Sign out. Collapses to a 64 px rail. */
withDefaults(defineProps<{ groups: NavGroup[]; current?: string; collapsed?: boolean; drawer?: boolean }>(), { current: undefined, collapsed: false, drawer: false });

const emit = defineEmits<{ toggle: []; navigate: []; signOut: [] }>();
const mode = useColorMode();
</script>

<template>
    <nav aria-label="Main" class="flex h-full flex-col overflow-y-auto bg-sidebar" :class="collapsed ? 'px-3 py-4' : 'px-3 py-4'">
        <div class="flex items-center justify-between px-2 pb-3.5" :class="collapsed ? 'flex-col gap-3 px-0' : ''">
            <img v-if="collapsed" src="/favicon.svg" width="32" height="32" alt="Homi" class="block size-8" />
            <HomiLogo v-else :variant="mode === 'dark' ? 'white' : 'blue'" />
            <UTooltip v-if="!drawer" :text="collapsed ? 'Expand sidebar' : 'Collapse sidebar'" :delay-duration="400">
                <UButton color="neutral" variant="ghost" size="sm" icon="i-lucide-panel-left" :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'" @click="emit('toggle')" />
            </UTooltip>
        </div>
        <div v-for="group in groups" :key="group.heading" class="mt-2">
            <p v-if="!collapsed" class="px-2 pt-3 pb-1.5 text-[11px] leading-4 font-semibold tracking-[0.07em] text-muted uppercase">{{ group.heading }}</p>
            <USeparator v-else class="my-2" />
            <ul class="flex flex-col gap-0.5">
                <li v-for="item in group.items" :key="item.key">
                    <UTooltip :text="item.label" :disabled="!collapsed" :delay-duration="400" :content="{ side: 'right' }">
                        <Link
                            :href="item.route"
                            class="relative flex h-9 items-center gap-2.5 rounded-md px-2 text-sm leading-5 outline-none transition-colors duration-120 ease-out focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-inset max-md:h-12"
                            :class="[current === item.key ? 'bg-nav-active font-medium text-highlighted' : 'text-default hover:bg-elevated', collapsed ? 'justify-center' : '']"
                            :aria-current="current === item.key ? 'page' : undefined"
                            :aria-label="collapsed ? item.label : undefined"
                            @click="emit('navigate')"
                        >
                            <UIcon :name="item.icon" class="size-[18px] shrink-0" :class="current === item.key ? 'text-primary' : 'text-muted'" aria-hidden="true" />
                            <span v-if="!collapsed" class="truncate">{{ item.label }}</span>
                            <UBadge v-if="item.count && !collapsed" :label="String(item.count)" color="neutral" variant="subtle" size="sm" class="ms-auto" />
                            <span v-if="item.count && collapsed" class="absolute top-1.5 right-1.5 size-1.5 rounded-full bg-bad" aria-hidden="true" />
                        </Link>
                    </UTooltip>
                </li>
            </ul>
        </div>
        <div class="mt-auto pt-4">
            <USeparator class="mb-2" />
            <button
                type="button"
                class="flex h-9 w-full cursor-pointer items-center gap-2.5 rounded-md px-2 text-sm text-default outline-none hover:bg-elevated focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-inset max-md:h-12"
                :class="collapsed ? 'justify-center' : ''"
                :aria-label="collapsed ? 'Sign out' : undefined"
                @click="emit('signOut')"
            >
                <UIcon name="i-lucide-x" class="size-[18px] text-muted" aria-hidden="true" />
                <span v-if="!collapsed">Sign out</span>
            </button>
        </div>
    </nav>
</template>
