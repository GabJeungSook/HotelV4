<!-- Mockup 2b -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import MoneyRow from '@/Components/MoneyRow.vue';
import { formatDateTime } from '@/Composables/useDateTime';
import { firstName, shortName, useShared } from '@/Composables/useShared';
import FrontDeskLayout from '@/Layouts/FrontDeskLayout.vue';

/** Join an open shift (2b): one drawer and one count, shared with the members already on it. */
const props = defineProps<{
    shift: { id: number; period_name: string; business_date: string; opened_at: string | null; opened_by: string | null; status: string };
    members: { id: number; name: string }[];
    frontdesks: number;
    max_frontdesks: number;
}>();

const { props: shared, flash } = useShared();
const form = useForm({});
const full = computed(() => props.frontdesks >= props.max_frontdesks);
const partners = computed(() => props.members.map((m) => firstName(m.name)).join(' and '));
const ordinal = computed(() => ['first', 'second', 'third', 'fourth', 'fifth'][props.frontdesks] ?? 'next');
</script>

<template>
    <FrontDeskLayout :title="`Join the ${shift.period_name} shift`">
        <div class="grid place-items-center pt-6">
            <section class="w-full max-w-[400px] rounded-card border border-default bg-default p-6">
                <h2 class="text-lg font-semibold text-highlighted">Join the {{ shift.period_name }} shift</h2>
                <p class="mt-1 mb-4 text-sm text-muted">A shift is already open for {{ shared.branch?.name }}.</p>
                <div class="flex flex-col gap-1.5">
                    <MoneyRow label="Business date" :text="formatDateTime(`${shift.business_date}T12:00:00+08:00`, 'businessDate')" />
                    <MoneyRow label="Opened" :text="`${formatDateTime(shift.opened_at, 'time')} by ${shortName(shift.opened_by ?? '')}`" />
                    <MoneyRow label="On shift now" :text="`${frontdesks} of ${max_frontdesks}`" />
                </div>
                <UAlert v-if="flash.error" class="mt-4" color="error" variant="subtle" icon="i-lucide-triangle-alert" :description="flash.error.message" />
                <UAlert
                    v-else-if="full"
                    class="mt-4"
                    color="warning"
                    variant="subtle"
                    icon="i-lucide-users"
                    :description="`This shift already has ${max_frontdesks} front desks.`"
                />
                <UButton class="mt-5" size="xl" block :label="`Join as ${ordinal} front desk`" :disabled="full" :loading="form.processing" @click="form.post(route('shift.join'))" />
                <p class="mt-2 text-center text-[13px] text-muted">You share one drawer and one cash count with {{ partners }}.</p>
            </section>
        </div>
    </FrontDeskLayout>
</template>
