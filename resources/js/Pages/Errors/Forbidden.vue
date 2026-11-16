<!-- Mockup 51 -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import { useShared } from '@/Composables/useShared';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout.vue';
import FrontDeskLayout from '@/Layouts/FrontDeskLayout.vue';
import OwnerLayout from '@/Layouts/OwnerLayout.vue';

/** No permission (51): names the account and offers the way back to the role's home. */
const props = defineProps<{ account: { name: string; role: string }; home: string; homeLabel: string }>();

const { props: shared } = useShared();
const shell = computed(() => {
    const role = shared.value.auth?.role;

    return role === 'frontdesk' || role === 'supervisor' ? FrontDeskLayout : role === 'owner' ? OwnerLayout : BackOfficeLayout;
});
</script>

<template>
    <component :is="shell" title="No access" :header="false">
        <div class="mt-12">
            <EmptyState
                icon="i-lucide-lock"
                title="You do not have access to this page"
                :text="`This page is not open to your account. You are signed in as ${props.account.name} (${props.account.role}).`"
                :action-label="homeLabel"
                @action="router.visit(home)"
            />
        </div>
    </component>
</template>
