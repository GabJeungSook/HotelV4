<!-- Event halls (function-hall module) -->
<script setup lang="ts">
import type { UseFormSubmitOptions } from '@inertiajs/core';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import MoneyInput from '@/Components/MoneyInput.vue';
import PeriodSegments from '@/Components/PeriodSegments.vue';
import { formatMoney } from '@/Composables/useMoney';
import type { FlashData } from '@/Composables/useShared';
import AdminLayout from '@/Layouts/AdminLayout.vue';

/**
 * Event halls (DATABASE_SCHEMA §4.14): the halls the branch can book, and the packages a booking is
 * built from (venue, food, host, other — per pax or flat). Front desk books them on Function halls.
 */
type Tab = 'halls' | 'packages';

interface HallRow {
    id: number;
    name: string;
    capacity: number;
    base_rate_amount: number | null;
    is_active: boolean;
}

interface PackageRow {
    id: number;
    name: string;
    kind: 'venue' | 'food' | 'host' | 'other';
    pricing: 'per_pax' | 'flat';
    unit_amount: number;
    is_active: boolean;
}

const props = defineProps<{ tab: Tab; halls: HallRow[]; packages: PackageRow[] }>();

const tab = ref<Tab>(props.tab);
watch(tab, (t) => router.get(route('admin.halls'), t === 'halls' ? {} : { tab: t }, { preserveState: true, preserveScroll: true, replace: true }));

const formError = ref<string | undefined>(undefined);
function done(close: () => void): UseFormSubmitOptions {
    formError.value = undefined;

    return {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (page) => {
            const e = (page.flash as FlashData | undefined)?.error;
            if (e) {
                formError.value = e.message;

                return;
            }
            close();
        },
    };
}

const KIND_LABEL = { venue: 'Venue', food: 'Food', host: 'Host', other: 'Other' } as const;
const kindItems = [
    { label: 'Venue', value: 'venue' },
    { label: 'Food', value: 'food' },
    { label: 'Host', value: 'host' },
    { label: 'Other', value: 'other' },
];
const pricingItems = [
    { label: 'Per pax', value: 'per_pax' },
    { label: 'Flat', value: 'flat' },
];

// Hall ---------------------------------------------------------------------------------------
const hallOpen = ref(false);
const hallEditing = ref<HallRow | null>(null);
const hallForm = useForm({ name: '', capacity: 100, base_rate_amount: 0, is_active: true });

function openHall(h: HallRow | null): void {
    hallEditing.value = h;
    formError.value = undefined;
    hallForm.clearErrors();
    hallForm.name = h?.name ?? '';
    hallForm.capacity = h?.capacity ?? 100;
    hallForm.base_rate_amount = h?.base_rate_amount ?? 0;
    hallForm.is_active = h?.is_active ?? true;
    hallOpen.value = true;
}

function saveHall(): void {
    const h = hallEditing.value;
    hallForm.submit(h ? 'put' : 'post', h ? route('admin.halls.update', h.id) : route('admin.halls.store'), done(() => (hallOpen.value = false)));
}

// Package ------------------------------------------------------------------------------------
const packOpen = ref(false);
const packEditing = ref<PackageRow | null>(null);
const packForm = useForm({ name: '', kind: 'food' as PackageRow['kind'], pricing: 'per_pax' as PackageRow['pricing'], unit_amount: 0, is_active: true });

function openPackage(p: PackageRow | null): void {
    packEditing.value = p;
    formError.value = undefined;
    packForm.clearErrors();
    packForm.name = p?.name ?? '';
    packForm.kind = p?.kind ?? 'food';
    packForm.pricing = p?.pricing ?? 'per_pax';
    packForm.unit_amount = p?.unit_amount ?? 0;
    packForm.is_active = p?.is_active ?? true;
    packOpen.value = true;
}

function savePackage(): void {
    const p = packEditing.value;
    packForm.submit(p ? 'put' : 'post', p ? route('admin.hall-packages.update', p.id) : route('admin.hall-packages.store'), done(() => (packOpen.value = false)));
}
</script>

<template>
    <AdminLayout current="admin-halls" title="Event halls">
        <Head title="Event halls" />

        <PeriodSegments
            v-model="tab"
            label="Show"
            :items="[
                { value: 'halls', label: `Halls (${halls.length})` },
                { value: 'packages', label: `Packages (${packages.length})` },
            ]"
            class="mb-4"
        />

        <section v-if="tab === 'halls'" class="overflow-x-auto rounded-card border border-default bg-default p-4">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-[15px] font-semibold text-highlighted">Halls</h3>
                <UButton icon="i-solar-add-square-linear" label="Add hall" @click="openHall(null)" />
            </div>
            <EmptyState v-if="halls.length === 0" icon="i-solar-confetti-bold-duotone" title="No halls yet" text="Add a function hall to start taking bookings." />
            <table v-else class="dtable w-full">
                <thead>
                    <tr>
                        <th>Hall</th>
                        <th class="text-right">Capacity</th>
                        <th class="text-right">Reference rate</th>
                        <th>Active</th>
                        <th><span class="sr-only">Edit</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="h in halls" :key="h.id" :class="h.is_active ? '' : 'text-dimmed'">
                        <td class="text-highlighted">{{ h.name }}</td>
                        <td class="num text-right">{{ h.capacity }}</td>
                        <td class="num text-right">{{ h.base_rate_amount ? formatMoney(h.base_rate_amount, 'column') : '—' }}</td>
                        <td><span :class="h.is_active ? 'font-medium text-ok' : 'font-medium text-bad'">{{ h.is_active ? 'Yes' : 'No' }}</span></td>
                        <td class="text-right"><UButton color="neutral" variant="ghost" size="sm" icon="i-solar-pen-2-linear" label="Edit" @click="openHall(h)" /></td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section v-else class="overflow-x-auto rounded-card border border-default bg-default p-4">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-[15px] font-semibold text-highlighted">Packages</h3>
                <UButton icon="i-solar-add-square-linear" label="Add package" @click="openPackage(null)" />
            </div>
            <EmptyState v-if="packages.length === 0" icon="i-solar-box-bold-duotone" title="No packages yet" text="Add venue, food, host or other packages to build bookings from." />
            <table v-else class="dtable w-full">
                <thead>
                    <tr>
                        <th>Package</th>
                        <th>Kind</th>
                        <th>Pricing</th>
                        <th class="text-right">Price</th>
                        <th>Active</th>
                        <th><span class="sr-only">Edit</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in packages" :key="p.id" :class="p.is_active ? '' : 'text-dimmed'">
                        <td class="text-highlighted">{{ p.name }}</td>
                        <td><span class="font-medium text-info">{{ KIND_LABEL[p.kind] }}</span></td>
                        <td>{{ p.pricing === 'per_pax' ? 'Per pax' : 'Flat' }}</td>
                        <td class="num text-right">{{ formatMoney(p.unit_amount, 'column') }}{{ p.pricing === 'per_pax' ? ' /pax' : '' }}</td>
                        <td><span :class="p.is_active ? 'font-medium text-ok' : 'font-medium text-bad'">{{ p.is_active ? 'Yes' : 'No' }}</span></td>
                        <td class="text-right"><UButton color="neutral" variant="ghost" size="sm" icon="i-solar-pen-2-linear" label="Edit" @click="openPackage(p)" /></td>
                    </tr>
                </tbody>
            </table>
        </section>

        <p class="mt-4 text-[13px] text-muted">A hall rate is a reference only — each booking is quoted from the packages and per-pax items you choose on the Function halls screen.</p>

        <!-- Hall modal -->
        <UModal v-model:open="hallOpen" :title="hallEditing ? hallEditing.name : 'Add hall'" :ui="{ content: 'max-w-[420px]' }">
            <template #body>
                <div class="flex flex-col gap-4">
                    <UAlert v-if="formError" color="error" variant="subtle" icon="i-solar-danger-triangle-bold-duotone" :description="formError" data-form-error />
                    <UFormField label="Name" :error="hallForm.errors.name"><UInput v-model="hallForm.name" class="w-full" maxlength="60" placeholder="Garden Pavilion" /></UFormField>
                    <div class="grid grid-cols-2 gap-3">
                        <UFormField label="Capacity" :error="hallForm.errors.capacity"><UInput v-model.number="hallForm.capacity" type="number" :min="0" class="w-full" /></UFormField>
                        <UFormField label="Reference rate" :error="hallForm.errors.base_rate_amount" help="A guide only."><MoneyInput v-model="hallForm.base_rate_amount" /></UFormField>
                    </div>
                    <USwitch v-model="hallForm.is_active" label="Available for booking" />
                </div>
            </template>
            <template #footer>
                <div class="flex w-full justify-end gap-2">
                    <UButton color="neutral" variant="ghost" label="Cancel" @click="hallOpen = false" />
                    <UButton :label="hallEditing ? 'Save hall' : 'Add hall'" :disabled="!hallForm.name.trim()" :loading="hallForm.processing" @click="saveHall" />
                </div>
            </template>
        </UModal>

        <!-- Package modal -->
        <UModal v-model:open="packOpen" :title="packEditing ? packEditing.name : 'Add package'" :ui="{ content: 'max-w-[440px]' }">
            <template #body>
                <div class="flex flex-col gap-4">
                    <UAlert v-if="formError" color="error" variant="subtle" icon="i-solar-danger-triangle-bold-duotone" :description="formError" data-form-error />
                    <UFormField label="Name" :error="packForm.errors.name"><UInput v-model="packForm.name" class="w-full" maxlength="60" placeholder="Buffet set A" /></UFormField>
                    <div class="grid grid-cols-2 gap-3">
                        <UFormField label="Kind" :error="packForm.errors.kind"><USelect v-model="packForm.kind" :items="kindItems" class="w-full" /></UFormField>
                        <UFormField label="Pricing" :error="packForm.errors.pricing"><USelect v-model="packForm.pricing" :items="pricingItems" class="w-full" /></UFormField>
                    </div>
                    <UFormField :label="packForm.pricing === 'per_pax' ? 'Price per pax' : 'Price'" :error="packForm.errors.unit_amount"><MoneyInput v-model="packForm.unit_amount" /></UFormField>
                    <USwitch v-model="packForm.is_active" label="Offered" />
                </div>
            </template>
            <template #footer>
                <div class="flex w-full justify-end gap-2">
                    <UButton color="neutral" variant="ghost" label="Cancel" @click="packOpen = false" />
                    <UButton :label="packEditing ? 'Save package' : 'Add package'" :disabled="!packForm.name.trim() || packForm.unit_amount <= 0" :loading="packForm.processing" @click="savePackage" />
                </div>
            </template>
        </UModal>
    </AdminLayout>
</template>
