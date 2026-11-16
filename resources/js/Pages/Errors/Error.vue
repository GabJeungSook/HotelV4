<!-- Error pages: 404, 500, 503 (TRD §8, APP_FLOW §6 case 55) -->
<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import HomiLogo from '@/Components/HomiLogo.vue';
import { useShared } from '@/Composables/useShared';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout.vue';
import FrontDeskLayout from '@/Layouts/FrontDeskLayout.vue';
import OwnerLayout from '@/Layouts/OwnerLayout.vue';

/**
 * A page that could not be shown. Signed-in staff see it inside their own layout with the way
 * back to their home; anyone else sees it on a plain page with the way to the front page. An
 * unexpected error says nothing was saved and gives the request id from the log.
 */
const props = defineProps<{ status: number; requestId: string | null; home: string; homeLabel: string }>();

const { props: shared } = useShared();
const role = computed(() => shared.value.auth?.role ?? null);
const shell = computed(() => {
    if (!role.value || !shared.value.nav?.length) {
        return null;
    }

    return role.value === 'frontdesk' || role.value === 'supervisor' ? FrontDeskLayout : role.value === 'owner' ? OwnerLayout : BackOfficeLayout;
});

const copy = computed(() => {
    switch (props.status) {
        case 404:
            return { title: 'This page does not exist', text: 'The link may be old, or the record was moved. Nothing was changed.', icon: 'i-lucide-map-pin-off' };
        case 503:
            return { title: 'Homi is being updated', text: 'It will be back in a few minutes. Nothing you saved before is lost.', icon: 'i-lucide-wrench' };
        default:
            return { title: 'Something went wrong', text: 'Nothing was saved. Try again; if it happens again, tell your admin the request id below.', icon: 'i-lucide-triangle-alert' };
    }
});
</script>

<template>
    <component :is="shell" v-if="shell" :title="copy.title">
        <div class="mt-12">
            <EmptyState :icon="copy.icon" :tone="status >= 500 && status !== 503 ? 'bad' : 'default'" :title="copy.title" :text="copy.text" :action-label="homeLabel" @action="router.visit(home)" />
            <p v-if="requestId" class="text-center text-sm text-muted" data-request-id>
                Request id <b class="font-mono text-highlighted">{{ requestId }}</b>
            </p>
        </div>
    </component>
    <div v-else class="flex min-h-dvh flex-col items-center justify-center bg-muted p-6">
        <Head :title="copy.title" />
        <div class="w-full max-w-md rounded-card border border-default bg-default p-8 text-center">
            <HomiLogo class="mx-auto" :width="112" :height="34" />
            <EmptyState :icon="copy.icon" :tone="status >= 500 && status !== 503 ? 'bad' : 'default'" :title="copy.title" :text="copy.text" />
            <p v-if="requestId" class="-mt-6 mb-6 text-sm text-muted" data-request-id>
                Request id <b class="font-mono text-highlighted">{{ requestId }}</b>
            </p>
            <UButton :href="home" :label="homeLabel" color="neutral" variant="outline" />
        </div>
    </div>
</template>
