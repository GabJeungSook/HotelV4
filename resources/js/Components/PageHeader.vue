<script setup lang="ts">
import { onMounted, ref } from 'vue';

/**
 * A page's actions (DESIGN_SYSTEM §5.2). By default they are teleported into the top bar beside the
 * title; with `title` set (wizards, pages outside the shell) it draws an in-page title row instead.
 */
withDefaults(defineProps<{ title?: string }>(), { title: undefined });

const target = ref<HTMLElement | null>(null);
onMounted(() => (target.value = document.getElementById('page-actions')));
</script>

<template>
    <div v-if="title" class="mb-4 flex flex-wrap items-center gap-3">
        <h2 class="text-[18px] leading-[26px] font-semibold tracking-[-0.005em] text-highlighted">{{ title }}</h2>
        <div class="ms-auto flex items-center gap-2"><slot /></div>
    </div>
    <Teleport v-else-if="target" :to="target"><slot /></Teleport>
</template>
