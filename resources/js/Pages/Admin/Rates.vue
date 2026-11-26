<!-- Mockup 45 -->
<script setup lang="ts">
import type { UseFormSubmitOptions } from '@inertiajs/core';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import MoneyInput from '@/Components/MoneyInput.vue';
import { formatMoney } from '@/Composables/useMoney';
import type { FlashData } from '@/Composables/useShared';
import AdminLayout from '@/Layouts/AdminLayout.vue';

/**
 * Rates (45, DATABASE_SCHEMA §4.5): the price table by room type and stay option, the extension
 * blocks and the discounts. The columns are only the options the branch sells (46). A blank cell
 * is "not set": that option is not offered at check-in (6), extend (12) or the kiosk (28), and
 * nothing is ever charged ₱0. A rate quoted by an open stay cannot be removed.
 */
interface Column {
    key: string;
    kind: 'hourly' | 'overnight';
    hours: number;
    label: string;
}

type Cells = Record<string, { id: number; amount: number } | null>;

interface Row {
    room_type_id: number;
    name: string;
    is_active: boolean;
    cells: Cells;
    limited: string[];
}

interface Option {
    value: number;
    label: string;
}

interface Discount {
    id: number;
    name: string;
    kind: 'fixed' | 'percent';
    value: number;
    all_rates: boolean;
    kiosk_enabled: boolean;
    needs_id: boolean;
    is_vat_exempt: boolean;
    is_active: boolean;
    rate_ids: number[];
}

const props = defineProps<{
    columns: Column[];
    rows: Row[];
    floor_room_rates_enabled: boolean;
    floors: Option[];
    rooms: Option[];
    floor_cells: Record<number, Cells>;
    room_cells: Record<number, Cells>;
    extension: { id: number; hours: number; amount: number }[];
    discounts: Discount[];
    rate_options: { value: number; label: string }[];
    extension_rule: 'cycle' | 'per_hour' | 'block';
}>();

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

// Rate cell ----------------------------------------------------------------------------------
type Scope = 'type' | 'floor' | 'room';
const cellOpen = ref(false);
const cell = ref<{ scope: Scope; title: string; column: Column; current: { id: number; amount: number } | null } | null>(null);
const rateForm = useForm({
    room_type_id: null as number | null,
    floor_id: null as number | null,
    room_id: null as number | null,
    stay_kind: 'hourly' as Column['kind'],
    hours: 0,
    amount: 0,
});

function openCell(scope: Scope, id: number, title: string, column: Column, cells: Cells): void {
    const current = cells[column.key] ?? null;
    cell.value = { scope, title, column, current };
    formError.value = undefined;
    rateForm.clearErrors();
    rateForm.room_type_id = scope === 'type' ? id : null;
    rateForm.floor_id = scope === 'floor' ? id : null;
    rateForm.room_id = scope === 'room' ? id : null;
    rateForm.stay_kind = column.kind;
    rateForm.hours = column.hours;
    rateForm.amount = current?.amount ?? 0;
    cellOpen.value = true;
}

function saveRate(remove = false): void {
    rateForm.transform((d) => ({ ...d, amount: remove || d.amount <= 0 ? null : d.amount }));
    rateForm.put(route('admin.rates.update'), done(() => (cellOpen.value = false)));
}

const cellIsSet = computed(() => cell.value?.current != null);

// Floor & room overrides ---------------------------------------------------------------------
const scopeKind = ref<'floor' | 'room'>('floor');
const scopeId = ref<number | null>(null);
const scopeOptions = computed<Option[]>(() => (scopeKind.value === 'floor' ? props.floors : props.rooms));
const scopeCells = computed<Cells | null>(() =>
    scopeId.value == null ? null : (scopeKind.value === 'floor' ? props.floor_cells : props.room_cells)[scopeId.value] ?? null,
);
const scopeLabel = computed(() => scopeOptions.value.find((o) => o.value === scopeId.value)?.label ?? '');

function pickScopeKind(kind: 'floor' | 'room'): void {
    scopeKind.value = kind;
    scopeId.value = null;
}

// Extension block ----------------------------------------------------------------------------
const extOpen = ref(false);
const extEditing = ref(false);
const extForm = useForm({ hours: 3, amount: 0 });

function openExtension(block: { hours: number; amount: number } | null): void {
    extEditing.value = block !== null;
    formError.value = undefined;
    extForm.clearErrors();
    extForm.hours = block?.hours ?? 3;
    extForm.amount = block?.amount ?? 0;
    extOpen.value = true;
}

function saveExtension(remove = false): void {
    extForm.transform((d) => ({ ...d, amount: remove || d.amount <= 0 ? null : d.amount }));
    extForm.put(route('admin.extension-rates.update'), done(() => (extOpen.value = false)));
}

// Discount -----------------------------------------------------------------------------------
const discOpen = ref(false);
const discEditing = ref<Discount | null>(null);
const discForm = useForm({
    name: '',
    kind: 'fixed' as Discount['kind'],
    value: 0,
    all_rates: true,
    kiosk_enabled: false,
    needs_id: false,
    is_vat_exempt: false,
    is_active: true,
    rate_ids: [] as number[],
});

function openDiscount(d: Discount | null): void {
    discEditing.value = d;
    formError.value = undefined;
    discForm.clearErrors();
    discForm.name = d?.name ?? '';
    discForm.kind = d?.kind ?? 'fixed';
    discForm.value = d?.value ?? 0;
    discForm.all_rates = d?.all_rates ?? true;
    discForm.kiosk_enabled = d?.kiosk_enabled ?? false;
    discForm.needs_id = d?.needs_id ?? false;
    discForm.is_vat_exempt = d?.is_vat_exempt ?? false;
    discForm.is_active = d?.is_active ?? true;
    discForm.rate_ids = [...(d?.rate_ids ?? [])];
    discOpen.value = true;
}

function saveDiscount(): void {
    const d = discEditing.value;
    discForm.transform((data) => ({ ...data, rate_ids: data.all_rates ? [] : data.rate_ids }));
    discForm.submit(d ? 'put' : 'post', d ? route('admin.discounts.update', d.id) : route('admin.discounts.store'), done(() => (discOpen.value = false)));
}

function discountValue(d: Pick<Discount, 'kind' | 'value'>): string {
    return d.kind === 'percent' ? `${d.value}%` : `${formatMoney(d.value, 'tile')} off`;
}

const discountValid = computed(
    () => discForm.name.trim() !== '' && discForm.value > 0 && (discForm.kind === 'fixed' || discForm.value <= 100) && (discForm.all_rates || discForm.rate_ids.length > 0),
);

const hourItems = Array.from({ length: 24 }, (_, i) => ({ label: `${i + 1} hrs`, value: i + 1 }));
</script>

<template>
    <AdminLayout current="rates" title="Rates">
        <Head title="Rates" />

        <section class="overflow-x-auto rounded-card border border-default bg-default p-4">
            <EmptyState
                v-if="columns.length === 0"
                icon="i-solar-banknote-2-bold-duotone"
                title="No hourly or overnight stays are sold"
                text="Switch on hourly or overnight stays in Settings to set their rates. A long stay is priced from the 24-hour rate."
            />
            <EmptyState v-else-if="rows.length === 0" icon="i-solar-bed-bold-duotone" title="No room types yet" text="Add room types on the Rooms page, then set their rates here." />
            <table v-else class="dtable w-full">
                <thead>
                    <tr>
                        <th>Room type</th>
                        <th v-for="c in columns" :key="c.key" class="text-right">{{ c.label }}</th>
                        <th>Limited discounts</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in rows" :key="r.room_type_id" :class="r.is_active ? '' : 'text-dimmed'">
                        <td class="text-highlighted">{{ r.name }}<span v-if="!r.is_active" class="ms-1 text-xs text-muted">(off)</span></td>
                        <td v-for="c in columns" :key="c.key" class="text-right">
                            <button
                                type="button"
                                class="hm-focus num cursor-pointer rounded px-1 hover:underline"
                                :class="r.cells[c.key] ? 'text-highlighted' : 'text-bad'"
                                :data-cell="`${r.room_type_id}-${c.key}`"
                                :aria-label="`${r.name} ${c.label}: ${r.cells[c.key] ? formatMoney(r.cells[c.key]!.amount) : 'not set'}. Edit`"
                                @click="openCell('type', r.room_type_id, r.name, c, r.cells)"
                            >
                                {{ r.cells[c.key] ? formatMoney(r.cells[c.key]!.amount, 'column') : 'not set' }}
                            </button>
                        </td>
                        <td class="!whitespace-normal text-muted">{{ r.limited.length ? r.limited.join(' · ') : '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section v-if="floor_room_rates_enabled && rows.length" class="mt-6 rounded-card border border-default bg-default p-4">
            <h3 class="text-[15px] font-semibold text-highlighted">Floor &amp; room rates</h3>
            <p class="mt-1 mb-3 text-[13px] text-muted">
                A floor rate is flat for every room on that floor; a room rate is for that one room. Both win over the room-type rate, and a blank cell falls back to the type rate.
            </p>
            <div class="flex flex-wrap items-end gap-3">
                <UFormField label="Set a rate for a">
                    <UButtonGroup>
                        <UButton :color="scopeKind === 'floor' ? 'primary' : 'neutral'" :variant="scopeKind === 'floor' ? 'solid' : 'outline'" label="Floor" @click="pickScopeKind('floor')" />
                        <UButton :color="scopeKind === 'room' ? 'primary' : 'neutral'" :variant="scopeKind === 'room' ? 'solid' : 'outline'" label="Room" @click="pickScopeKind('room')" />
                    </UButtonGroup>
                </UFormField>
                <UFormField :label="scopeKind === 'floor' ? 'Floor' : 'Room'">
                    <USelect v-model="scopeId" :items="scopeOptions" value-key="value" :placeholder="scopeKind === 'floor' ? 'Pick a floor' : 'Pick a room'" class="min-w-56" />
                </UFormField>
            </div>

            <table v-if="scopeCells" class="dtable mt-4 w-full">
                <thead>
                    <tr>
                        <th>{{ scopeLabel }}</th>
                        <th v-for="c in columns" :key="c.key" class="text-right">{{ c.label }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="text-muted">Rate</td>
                        <td v-for="c in columns" :key="c.key" class="text-right">
                            <button
                                type="button"
                                class="hm-focus num cursor-pointer rounded px-1 hover:underline"
                                :class="scopeCells[c.key] ? 'text-highlighted' : 'text-muted'"
                                :data-cell="`${scopeKind}-${scopeId}-${c.key}`"
                                :aria-label="`${scopeLabel} ${c.label}: ${scopeCells[c.key] ? formatMoney(scopeCells[c.key]!.amount) : 'uses the room-type rate'}. Edit`"
                                @click="openCell(scopeKind, scopeId!, scopeLabel, c, scopeCells!)"
                            >
                                {{ scopeCells[c.key] ? formatMoney(scopeCells[c.key]!.amount, 'column') : 'type rate' }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <h3 class="mt-6 mb-2 text-[15px] font-semibold text-highlighted">Extension rates (all room types)</h3>
        <div class="flex flex-wrap items-stretch gap-2.5">
            <UButton
                v-for="e in extension"
                :key="e.id"
                color="neutral"
                variant="outline"
                class="min-h-[56px] min-w-[104px] flex-col items-start justify-center gap-0.5 px-4"
                :aria-label="`${e.hours} hours extension ${formatMoney(e.amount)}. Edit`"
                @click="openExtension(e)"
            >
                <span class="text-[14px] font-semibold text-highlighted">{{ e.hours }} hrs</span>
                <small class="num text-[12.5px] text-muted">{{ formatMoney(e.amount, 'tile') }}</small>
            </UButton>
            <UButton color="neutral" variant="outline" class="min-h-[56px] border-dashed px-4" icon="i-solar-add-square-linear" label="Add" @click="openExtension(null)" />
        </div>

        <h3 class="mt-6 mb-2 text-[15px] font-semibold text-highlighted">Discounts</h3>
        <div class="flex flex-wrap items-stretch gap-2.5">
            <UButton
                v-for="d in discounts"
                :key="d.id"
                color="neutral"
                variant="outline"
                class="min-h-[56px] min-w-[104px] flex-col items-start justify-center gap-0.5 px-4"
                :class="d.is_active ? '' : 'opacity-60'"
                :aria-label="`${d.name} ${discountValue(d)}. Edit`"
                @click="openDiscount(d)"
            >
                <span class="text-[14px] font-semibold text-highlighted">{{ d.name }}</span>
                <small class="num text-[12.5px] text-muted">{{ discountValue(d) }}{{ d.is_active ? '' : ' · off' }}</small>
            </UButton>
            <UButton color="neutral" variant="outline" class="min-h-[56px] border-dashed px-4" icon="i-solar-add-square-linear" label="Add" @click="openDiscount(null)" />
        </div>

        <p class="mt-4 text-[13px] text-muted">A blank rate means that option is not offered. Changing a rate affects new check-ins only; open stays keep the price they were quoted.</p>

        <!-- Rate cell -->
        <UModal v-model:open="cellOpen" :title="cell ? `${cell.title} · ${cell.column.label}` : ''" :ui="{ content: 'max-w-[380px]' }">
            <template #body>
                <div class="flex flex-col gap-4">
                    <UAlert v-if="formError" color="error" variant="subtle" icon="i-solar-danger-triangle-bold-duotone" :description="formError" data-form-error />
                    <UFormField
                        label="Rate"
                        name="amount"
                        :error="rateForm.errors.amount"
                        :help="cell?.scope === 'type' ? 'Leave it blank to stop selling this option. It is never ₱0.' : 'Leave it blank to fall back to the room-type rate. It is never ₱0.'"
                    >
                        <MoneyInput v-model="rateForm.amount" autofocus />
                    </UFormField>
                </div>
            </template>
            <template #footer>
                <div class="flex w-full items-center gap-2">
                    <UButton
                        v-if="cellIsSet"
                        color="error"
                        variant="ghost"
                        :label="cell?.scope === 'type' ? 'Remove (not set)' : 'Remove (use type rate)'"
                        :loading="rateForm.processing"
                        @click="saveRate(true)"
                    />
                    <div class="ms-auto flex gap-2">
                        <UButton color="neutral" variant="ghost" label="Cancel" @click="cellOpen = false" />
                        <UButton label="Save rate" :disabled="rateForm.amount <= 0 && !cellIsSet" :loading="rateForm.processing" @click="saveRate()" />
                    </div>
                </div>
            </template>
        </UModal>

        <!-- Extension block -->
        <UModal v-model:open="extOpen" :title="extEditing ? `Extension · ${extForm.hours} hrs` : 'Add extension block'" :ui="{ content: 'max-w-[380px]' }">
            <template #body>
                <div class="flex flex-col gap-4">
                    <UAlert v-if="formError" color="error" variant="subtle" icon="i-solar-danger-triangle-bold-duotone" :description="formError" data-form-error />
                    <UFormField label="Hours" name="hours" :error="extForm.errors.hours">
                        <USelect v-model="extForm.hours" :items="hourItems" :disabled="extEditing" class="w-32" />
                    </UFormField>
                    <UFormField label="Price" name="amount" :error="extForm.errors.amount">
                        <MoneyInput v-model="extForm.amount" />
                    </UFormField>
                </div>
            </template>
            <template #footer>
                <div class="flex w-full items-center gap-2">
                    <UButton v-if="extEditing" color="error" variant="ghost" label="Remove" :loading="extForm.processing" @click="saveExtension(true)" />
                    <div class="ms-auto flex gap-2">
                        <UButton color="neutral" variant="ghost" label="Cancel" @click="extOpen = false" />
                        <UButton label="Save" :disabled="extForm.amount <= 0" :loading="extForm.processing" @click="saveExtension()" />
                    </div>
                </div>
            </template>
        </UModal>

        <!-- Discount -->
        <UModal v-model:open="discOpen" :title="discEditing ? discEditing.name : 'Add discount'" :ui="{ content: 'max-w-[480px]' }">
            <template #body>
                <div class="flex flex-col gap-4">
                    <UAlert v-if="formError" color="error" variant="subtle" icon="i-solar-danger-triangle-bold-duotone" :description="formError" data-form-error />
                    <UFormField label="Name" name="name" :error="discForm.errors.name">
                        <UInput v-model="discForm.name" class="w-full" maxlength="40" placeholder="Senior citizen" />
                    </UFormField>
                    <div class="grid grid-cols-2 gap-3">
                        <UFormField label="Kind" name="kind" :error="discForm.errors.kind">
                            <USelect
                                v-model="discForm.kind"
                                :items="[
                                    { label: 'Fixed amount', value: 'fixed' },
                                    { label: 'Percent', value: 'percent' },
                                ]"
                                class="w-full"
                                @update:model-value="discForm.value = 0"
                            />
                        </UFormField>
                        <UFormField :label="discForm.kind === 'percent' ? 'Percent off' : 'Amount off'" name="value" :error="discForm.errors.value">
                            <MoneyInput v-if="discForm.kind === 'fixed'" v-model="discForm.value" />
                            <UInput v-else v-model.number="discForm.value" type="number" inputmode="numeric" :min="1" :max="100" class="w-full">
                                <template #trailing><span class="text-muted">%</span></template>
                            </UInput>
                        </UFormField>
                    </div>
                    <USwitch v-model="discForm.all_rates" label="Applies to every rate" />
                    <UFormField v-if="!discForm.all_rates" label="Only these rates" name="rate_ids" :error="discForm.errors.rate_ids">
                        <USelectMenu v-model="discForm.rate_ids" :items="rate_options" value-key="value" multiple placeholder="Pick rates" class="w-full" />
                    </UFormField>
                    <USwitch v-model="discForm.kiosk_enabled" label="Offered on the kiosk" description="A promo applies automatically. A guest-ticked discount (below) is offered as a toggle on the chosen rate." />
                    <USwitch
                        v-if="discForm.kiosk_enabled"
                        v-model="discForm.needs_id"
                        label="Guests tick it (needs ID)"
                        description="Shown on the kiosk as a toggle the guest ticks (senior citizen, PWD). It applies to the bill, then the front desk verifies the ID and can revoke it. Off: the discount applies automatically."
                    />
                    <USwitch v-model="discForm.is_vat_exempt" label="VAT-exempt (senior citizen, PWD)" />
                    <USwitch v-model="discForm.is_active" label="Active" />
                </div>
            </template>
            <template #footer>
                <div class="flex w-full justify-end gap-2">
                    <UButton color="neutral" variant="ghost" label="Cancel" @click="discOpen = false" />
                    <UButton :label="discEditing ? 'Save discount' : 'Add discount'" :disabled="!discountValid" :loading="discForm.processing" @click="saveDiscount" />
                </div>
            </template>
        </UModal>
    </AdminLayout>
</template>
