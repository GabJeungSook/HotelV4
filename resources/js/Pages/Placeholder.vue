<!-- Mockup: none (a plain placeholder for a role home built in a later phase, ROADMAP Phase 4) -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout.vue';
import KioskLayout from '@/Layouts/KioskLayout.vue';
import OwnerLayout from '@/Layouts/OwnerLayout.vue';
import PhoneLayout from '@/Layouts/PhoneLayout.vue';

const props = defineProps<{ layout: 'backoffice' | 'admin' | 'owner' | 'phone' | 'kiosk'; current: string; title: string; text: string }>();

const shell = computed(() => ({ backoffice: BackOfficeLayout, admin: AdminLayout, owner: OwnerLayout, phone: PhoneLayout, kiosk: KioskLayout })[props.layout]);
const shellProps = computed(() => (props.layout === 'phone' ? { title: props.title } : props.layout === 'kiosk' ? {} : { current: props.current, title: props.title }));
</script>

<template>
    <component :is="shell" v-bind="shellProps">
        <EmptyState icon="i-lucide-hammer" :title="`${title} is not built yet`" :text="text" action-label="Sign out" @action="router.post(route('logout'))" />
    </component>
</template>
