<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import ConnectionBar from '@/Components/ConnectionBar.vue';
import ServerErrorDialog from '@/Components/ServerErrorDialog.vue';
import { useFlashToast } from '@/Composables/useFlashToast';

/**
 * Housekeeper phone layout, 360 px and wider (DESIGN_SYSTEM §6.9): a flat coloured header band
 * with two links (queue, my cleans), then one column with 12 px padding. Follows the colour mode.
 * Owns the connection bar (50) and the flash toasts, like the desktop layouts.
 */
withDefaults(
    defineProps<{
        title: string;
        subtitle?: string;
        tone?: 'primary' | 'violet';
        current?: 'queue' | 'history';
    }>(),
    { subtitle: undefined, tone: 'primary', current: 'queue' },
);

useFlashToast();

const links = [
    { key: 'queue', label: 'Queue', href: '/housekeeping' },
    { key: 'history', label: 'My cleans', href: '/housekeeping/history' },
];
</script>

<template>
    <div class="min-h-dvh bg-muted text-base leading-6 text-default">
        <ConnectionBar />
        <ServerErrorDialog />
        <header data-shell class="p-4 text-on-primary" :class="tone === 'violet' ? 'bg-violet' : 'bg-primary'">
            <div class="mx-auto flex max-w-xl items-start justify-between gap-3">
                <div class="min-w-0">
                    <h1 class="truncate text-lg leading-6 font-semibold">{{ title }}</h1>
                    <p v-if="subtitle" class="truncate text-[13px] leading-[18px] opacity-90">{{ subtitle }}</p>
                </div>
                <nav aria-label="Housekeeping" class="flex shrink-0 gap-1">
                    <Link
                        v-for="link in links"
                        :key="link.key"
                        :href="link.href"
                        class="flex h-12 items-center rounded-md px-3 text-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-(--hm-card)"
                        :class="current === link.key ? 'underline underline-offset-4' : 'opacity-90'"
                        :aria-current="current === link.key ? 'page' : undefined"
                        >{{ link.label }}</Link
                    >
                </nav>
            </div>
        </header>
        <main class="mx-auto flex max-w-xl flex-col gap-3 p-3"><slot /></main>
    </div>
</template>
