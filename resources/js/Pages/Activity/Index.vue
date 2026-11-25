<!-- Activity log (admin + superadmin): every action of a business day, no mockup number. -->
<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import DateNav from '@/Components/DateNav.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PersonPill from '@/Components/PersonPill.vue';
import { formatDateTime } from '@/Composables/useDateTime';
import { useShared } from '@/Composables/useShared';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import OwnerLayout from '@/Layouts/OwnerLayout.vue';

interface Row {
    id: string;
    at: string;
    by: string;
    branch: string;
    action: string;
    detail: string;
}

const props = defineProps<{
    rows: Row[];
    people: { id: number; name: string }[];
    date: string;
    user_id: number | null;
    cross_branch: boolean;
}>();

const { props: shared } = useShared();
const isOwner = computed(() => shared.value.auth?.role === 'owner');
const layout = computed(() => (isOwner.value ? OwnerLayout : AdminLayout));
const routeName = computed(() => (isOwner.value ? 'owner.activity' : 'admin.activity'));

const date = ref(props.date);
const person = ref<number | 'all'>(props.user_id ?? 'all');

watch([date, person], ([d, p]) => {
    router.get(route(routeName.value), { date: d, user: p === 'all' ? undefined : p }, { preserveState: true, preserveScroll: true, replace: true });
});

const people = computed(() => [{ label: 'Everyone', value: 'all' as const }, ...props.people.map((p) => ({ label: p.name, value: p.id }))]);
</script>

<template>
    <component :is="layout" current="activity" title="Activity log">
        <Head title="Activity log" />
        <div class="no-print mb-4 flex flex-wrap items-center gap-3">
            <DateNav v-model="date" />
            <USelect v-model="person" :items="people" value-key="value" class="w-52" aria-label="Done by" />
        </div>

        <div class="overflow-x-auto rounded-card border border-default bg-default p-4">
            <EmptyState v-if="!rows.length" title="Nothing on this day" text="Actions taken on this day — sign-ins, check-ins, payments, changes — show up here." />
            <table v-else class="dtable">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Action</th>
                        <th>Details</th>
                        <th v-if="cross_branch">Branch</th>
                        <th>Done by</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id">
                        <td class="whitespace-nowrap">{{ formatDateTime(row.at, 'time') }}</td>
                        <td class="font-medium text-highlighted">{{ row.action }}</td>
                        <td class="!whitespace-normal text-muted">{{ row.detail }}</td>
                        <td v-if="cross_branch" class="text-muted">{{ row.branch }}</td>
                        <td><PersonPill v-if="row.by" :name="row.by" /><span v-else class="text-muted">—</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </component>
</template>
