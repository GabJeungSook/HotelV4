<script setup lang="ts">
import { inject, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { PAGE_HEADER, type PageHeaderState } from '@/Composables/usePageHeader';

/**
 * A page's part of the page header (DESIGN_SYSTEM §0, §5.2). The shell draws the header itself:
 * the ink icon tile, the `<h1>` title and one sub-line. A page uses this component to put its
 * actions on the right (default slot: quiet buttons, `ExportMenu`, then the one solid primary),
 * a status label after the title (`#status`), and its own sub-line (`sub`).
 * With `title` set it draws an in-page title row instead (wizard steps, pages outside the shell).
 */
const props = withDefaults(defineProps<{ title?: string; sub?: string }>(), { title: undefined, sub: undefined });

const state = inject<PageHeaderState | null>(PAGE_HEADER, null);
const actions = ref<HTMLElement | null>(null);
const status = ref<HTMLElement | null>(null);

onMounted(() => {
    actions.value = document.getElementById('page-actions');
    status.value = document.getElementById('page-status');
});

watch(
    () => props.sub,
    (sub) => {
        if (state && !props.title) {
            state.sub = sub;
        }
    },
    { immediate: true },
);
onBeforeUnmount(() => {
    if (state && !props.title) {
        state.sub = undefined;
    }
});
</script>

<template>
    <div v-if="title" class="mb-4 flex flex-wrap items-center gap-3">
        <h2 class="text-[17px] leading-[26px] font-semibold tracking-[-0.01em] text-highlighted">{{ title }}</h2>
        <slot name="status" />
        <div class="ms-auto flex items-center gap-2"><slot /></div>
    </div>
    <template v-else>
        <Teleport v-if="actions" :to="actions"><slot /></Teleport>
        <Teleport v-if="status && $slots.status" :to="status"><slot name="status" /></Teleport>
    </template>
</template>
