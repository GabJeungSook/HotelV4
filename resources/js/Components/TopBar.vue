<script setup lang="ts">
import UserMenu from './UserMenu.vue';

/** 56 px white bar: the page's only <h1>, the teleported page actions, status labels, the user menu (DESIGN_SYSTEM §5.2). */
withDefaults(defineProps<{ title: string; subtitle?: string; user?: { name: string; role: string }; canSwitch?: boolean }>(), {
    subtitle: undefined,
    user: undefined,
    canSwitch: false,
});

const emit = defineEmits<{ menu: []; switchUser: [] }>();
</script>

<template>
    <header data-shell class="flex h-14 shrink-0 items-center gap-3 border-b border-default bg-default px-6 max-md:px-4">
        <UButton class="md:hidden" color="neutral" variant="ghost" icon="i-lucide-menu" aria-label="Open menu" @click="emit('menu')" />
        <h1 class="truncate text-[19px] leading-6 font-semibold tracking-[-0.01em] whitespace-nowrap text-highlighted">{{ title }}</h1>
        <span v-if="subtitle" class="truncate text-sm text-muted max-lg:hidden">{{ subtitle }}</span>
        <div id="page-actions" class="flex items-center gap-2" />
        <div class="ms-auto flex items-center gap-2">
            <div class="flex items-center gap-2 max-md:hidden"><slot name="status" /></div>
            <UserMenu v-if="user" :user="user" :can-switch="canSwitch" @switch-user="emit('switchUser')" />
        </div>
    </header>
</template>
