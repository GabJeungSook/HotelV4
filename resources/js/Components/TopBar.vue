<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { onKeyStroke } from '@vueuse/core';
import { ref } from 'vue';

/**
 * The 56 px top bar (DESIGN_SYSTEM §0, §6.1; mockup v6 `.tb`): breadcrumbs with the page in
 * primary, the search box ("Ctrl K" focuses it; Enter searches the room board) where the user can
 * search rooms, the status slot (shift label and Close shift on the front desk) and the bell.
 */
const props = withDefaults(defineProps<{ crumbs: string[]; searchRoute?: string; notifications?: string }>(), { searchRoute: undefined, notifications: undefined });

const emit = defineEmits<{ menu: [] }>();

const q = ref('');
const box = ref<{ inputRef?: HTMLInputElement } | null>(null);

onKeyStroke(
    (e) => (e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k',
    (e) => {
        if (!props.searchRoute) {
            return;
        }
        e.preventDefault();
        box.value?.inputRef?.focus();
    },
);

function search(): void {
    if (props.searchRoute && q.value.trim() !== '') {
        router.get(props.searchRoute, { q: q.value.trim() });
    }
}
</script>

<template>
    <header data-shell class="flex h-14 shrink-0 items-center gap-2.5 border-b border-default bg-default px-7 max-md:px-4">
        <UButton class="md:hidden" color="neutral" variant="ghost" icon="i-solar-hamburger-menu-linear" aria-label="Open menu" @click="emit('menu')" />
        <nav aria-label="Breadcrumb" class="flex min-w-0 flex-1 items-center gap-2 overflow-hidden text-[12.5px] whitespace-nowrap text-muted">
            <UIcon name="i-solar-home-2-bold-duotone" class="size-[15px] shrink-0 text-faint" aria-hidden="true" />
            <template v-for="(c, i) in crumbs" :key="`${c}-${i}`">
                <span class="text-(--hm-line-strong)" aria-hidden="true">/</span>
                <span v-if="i === crumbs.length - 1" class="truncate font-medium text-primary" aria-current="page">{{ c }}</span>
                <span v-else class="shrink-0">{{ c }}</span>
            </template>
        </nav>
        <form v-if="searchRoute" class="max-lg:hidden" role="search" @submit.prevent="search">
            <UInput
                ref="box"
                v-model="q"
                icon="i-solar-magnifer-linear"
                placeholder="Search rooms or guests"
                aria-label="Search rooms or guests"
                size="md"
                class="w-[200px]"
                :ui="{ base: 'h-[34px] ring-default text-[12.5px] pe-14', leadingIcon: 'size-[15px] text-faint', trailing: 'pe-1.5' }"
            >
                <template #trailing>
                    <kbd class="rounded-[5px] border border-default bg-muted px-1.5 text-[10.5px] leading-[18px] font-medium text-muted">Ctrl K</kbd>
                </template>
            </UInput>
        </form>
        <div class="flex shrink-0 items-center gap-2 max-md:hidden"><slot name="status" /></div>
        <Link
            v-if="notifications"
            :href="notifications"
            class="hm-focus grid size-[34px] shrink-0 place-items-center rounded-lg border border-default bg-default text-muted hover:bg-elevated"
            aria-label="Notifications"
        >
            <UIcon name="i-solar-bell-bold-duotone" class="size-4" aria-hidden="true" />
        </Link>
    </header>
</template>
