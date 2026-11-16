<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import type { FlashData } from '@/Composables/useShared';

/**
 * The red dialog for an unexpected server error (TRD §8, SYSTEM_DESIGN §8): "Something went
 * wrong. Nothing was saved." with the request id, so it can be read out to whoever looks at the
 * log. The form behind it keeps what was typed. Mounted once by every layout.
 */
const page = usePage();
const open = ref(false);
const requestId = ref<string | null>(null);

watch(
    () => page.flash as FlashData | undefined,
    (flash) => {
        if (flash?.error?.code === 'server_error') {
            requestId.value = flash.error.request_id ?? null;
            open.value = true;
        }
    },
    { immediate: true },
);
</script>

<template>
    <UModal v-model:open="open" title="Something went wrong" :ui="{ content: 'max-w-md', title: 'text-error' }" data-server-error>
        <template #body>
            <p class="text-default">Nothing was saved. Try again; if it happens again, tell your admin the request id below.</p>
            <p v-if="requestId" class="mt-3 text-sm text-muted">
                Request id <b class="font-mono text-highlighted" data-request-id>{{ requestId }}</b>
            </p>
        </template>
        <template #footer>
            <div class="flex w-full justify-end">
                <UButton label="OK" @click="open = false" />
            </div>
        </template>
    </UModal>
</template>
