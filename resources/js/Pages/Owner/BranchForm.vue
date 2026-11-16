<!-- Mockup 56 -->
<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import { useShared } from '@/Composables/useShared';
import OwnerLayout from '@/Layouts/OwnerLayout.vue';
import type { Tone } from '@/types/ui';

/**
 * Owner — add branch (56): name, address, a unique three-character stay-code prefix and the first
 * admin; optionally copy rates, settings, products and lists from an existing branch. The server
 * validates everything (APP_FLOW §4.20); nothing is saved while a field is wrong.
 */
const props = defineProps<{ sources: { value: number; label: string }[] }>();

const { flash } = useShared();

const form = useForm({
    name: '',
    address: '',
    code_prefix: '',
    admin_name: '',
    admin_username: '',
    copy_from_id: (props.sources[0]?.value ?? null) as number | null,
});

const sourceItems = computed(() => [{ value: null, label: 'None — start from the defaults' }, ...props.sources]);
const copying = computed(() => form.copy_from_id !== null);

const plan = computed<{ tone: Tone; chip: string; text: string }[]>(() => [
    { tone: copying.value ? 'ok' : 'neutral', chip: copying.value ? 'Copy' : 'Default', text: 'Room types, rates and extension rates' },
    { tone: copying.value ? 'ok' : 'neutral', chip: copying.value ? 'Copy' : 'Default', text: 'Branch settings (deposit, shifts, kiosk hold, cleaning times)' },
    { tone: copying.value ? 'ok' : 'neutral', chip: copying.value ? 'Copy' : 'Default', text: 'Products and prices, with stock at 0' },
    { tone: copying.value ? 'ok' : 'neutral', chip: copying.value ? 'Copy' : 'Default', text: 'Transfer reasons and expense categories' },
    { tone: 'neutral', chip: 'Skip', text: 'Rooms and floors — added by the new admin' },
    { tone: 'neutral', chip: 'Never', text: 'Guests, stays, money and staff' },
]);

const ready = computed(
    () =>
        form.name.trim() !== '' &&
        form.address.trim() !== '' &&
        form.code_prefix.trim().length === 3 &&
        form.admin_name.trim().length >= 3 &&
        form.admin_username.trim().length >= 3,
);

function submit(): void {
    if (!ready.value) {
        return;
    }

    form.post(route('owner.branches.store'), { preserveScroll: true });
}
</script>

<template>
    <OwnerLayout current="branches" title="Add branch">
        <Head title="Add branch" />
        <PageHeader>
            <UButton color="neutral" variant="ghost" icon="i-lucide-arrow-left" label="Branches" :to="route('owner.branches.index')" />
        </PageHeader>

        <UAlert v-if="flash.error" color="error" variant="subtle" icon="i-lucide-triangle-alert" class="mb-4" :description="flash.error.message" />

        <form class="grid grid-cols-2 gap-4 max-[900px]:grid-cols-1" @submit.prevent="submit">
            <section class="rounded-card border border-default bg-default p-5">
                <h2 class="mb-3 text-base font-semibold text-highlighted">Branch</h2>
                <UFormField label="Name" name="name" :error="form.errors.name">
                    <UInput v-model="form.name" class="w-full" autofocus autocomplete="off" />
                </UFormField>
                <UFormField label="Address (printed on slips)" name="address" class="mt-3" :error="form.errors.address">
                    <UInput v-model="form.address" class="w-full" autocomplete="off" />
                </UFormField>
                <UFormField label="Stay code prefix" name="code_prefix" class="mt-3" hint="3 characters, like DGS" :error="form.errors.code_prefix">
                    <UInput v-model="form.code_prefix" class="w-32 font-mono uppercase" maxlength="3" autocomplete="off" />
                </UFormField>

                <h2 class="mt-5 mb-3 text-base font-semibold text-highlighted">First admin of this branch</h2>
                <div class="grid grid-cols-2 gap-3">
                    <UFormField label="Full name" name="admin_name" :error="form.errors.admin_name">
                        <UInput v-model="form.admin_name" class="w-full" autocomplete="off" />
                    </UFormField>
                    <UFormField label="Username" name="admin_username" :error="form.errors.admin_username">
                        <UInput v-model="form.admin_username" class="w-full" autocomplete="off" />
                    </UFormField>
                </div>
                <p class="mt-2 text-xs text-muted">A temporary password is shown once after saving. They set their PIN at first sign-in.</p>
            </section>

            <section class="rounded-card border border-default bg-default p-5">
                <h2 class="mb-3 text-base font-semibold text-highlighted">Start from an existing branch</h2>
                <UFormField label="Copy from" name="copy_from_id" :error="form.errors.copy_from_id">
                    <USelect v-model="form.copy_from_id" :items="sourceItems" value-key="value" class="w-full" />
                </UFormField>
                <table class="dtable mt-3">
                    <tbody>
                        <tr v-for="row in plan" :key="row.text">
                            <td class="w-20"><StatusLabel :tone="row.tone" :label="row.chip" /></td>
                            <td class="!whitespace-normal">{{ row.text }}</td>
                        </tr>
                    </tbody>
                </table>
                <UButton type="submit" block size="lg" class="mt-4" label="Create branch" :disabled="!ready" :loading="form.processing" />
            </section>
        </form>
    </OwnerLayout>
</template>
