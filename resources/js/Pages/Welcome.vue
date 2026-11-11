<!-- Phase 0 placeholder. The welcome page (Mockup 59) is built in Phase 10. -->
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{
    app: { name: string };
}>();
</script>

<template>
    <UApp>
        <Head title="Welcome" />
        <main class="flex min-h-screen items-center justify-center">
            <UButton label="Homi is set up" icon="i-lucide-check" />
        </main>
    </UApp>
</template>
