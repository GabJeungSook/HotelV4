<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useShared } from '@/Composables/useShared';

/**
 * The owner's branch picker (ROADMAP Phase 9, mockup 39 "The branch picker at the top is on
 * every report"): in the top bar of the back office shell, for owners only. On a report it sets
 * `?branch=` (ReportController reads it); elsewhere it opens that branch, as "Open" on 55 does,
 * and lands on its dashboard (61).
 */
const { props: shared } = useShared();

const branches = computed(() => shared.value.ownerBranches ?? []);
const show = computed(() => shared.value.auth?.role === 'owner' && branches.value.length > 0);
const items = computed(() => branches.value.map((b) => ({ label: b.name, value: String(b.id) })));

const current = computed(() => {
    const fromUrl = typeof window === 'undefined' ? null : new URLSearchParams(window.location.search).get('branch');

    return fromUrl ?? (shared.value.branch ? String(shared.value.branch.id) : undefined);
});

function pick(value: string | number): void {
    const id = Number(value);

    if (route().current('reports.*')) {
        const query = Object.fromEntries(new URLSearchParams(window.location.search));
        router.get(window.location.pathname, { ...query, branch: id }, { preserveScroll: true });

        return;
    }

    router.post(route('owner.branches.open', id));
}
</script>

<template>
    <USelect v-if="show" :model-value="current" :items="items" placeholder="Choose a branch" size="sm" class="w-48" aria-label="Branch" @update:model-value="pick" />
</template>
