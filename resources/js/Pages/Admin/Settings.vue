<!-- Mockup 46 -->
<script setup lang="ts">
import type { UseFormSubmitOptions } from '@inertiajs/core';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import MoneyInput from '@/Components/MoneyInput.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import { formatMoney } from '@/Composables/useMoney';
import type { FlashData } from '@/Composables/useShared';
import AdminLayout from '@/Layouts/AdminLayout.vue';

/**
 * Branch settings (46, DATABASE_SCHEMA §4.1): every value that was fixed for one hotel in V2 is a
 * setting here, grouped as stays, money, shifts, modules, VAT, and lists and printing. Each group
 * is edited in its own dialog and saved on its own. The reason lists (APP_FLOW §8.2) each have
 * two switches per row: needs a note, needs a supervisor PIN. Switching a stay or a module off
 * removes it from the screens that offer it.
 */
type Group = 'stays' | 'money' | 'shifts' | 'modules' | 'vat' | 'printing';

interface Settings {
    name: string;
    hourly_enabled: boolean;
    overnight_enabled: boolean;
    overnight_checkin_time: string;
    overnight_checkout_time: string;
    long_stay_enabled: boolean;
    floor_room_rates_enabled: boolean;
    long_stay_max_days: number;
    extension_rule: 'cycle' | 'per_hour' | 'block';
    extension_cycle_hours: number;
    extension_per_hour_amount: number;
    due_soon_minutes: number;
    deposit_enabled: boolean;
    deposit_name: string;
    deposit_amount: number;
    lost_key_amount: number;
    lost_remote_amount: number;
    payment_methods: string[];
    max_frontdesks_per_shift: number;
    shift_overdue_warn_minutes: number;
    shift_stale_block_minutes: number;
    kiosk_enabled: boolean;
    kiosk_hold_minutes: number;
    reservations_enabled: boolean;
    reservation_release_minutes: number;
    pos_enabled: boolean;
    housekeeping_enabled: boolean;
    cleaning_deadline_minutes: number;
    cleaning_target_minutes: number;
    cleaning_urgent_minutes: number;
    vat_enabled: boolean;
    vat_rate_percent: number;
    tin: string | null;
    registered_name: string | null;
    slip_header: string;
    address: string;
    house_rules: string | null;
}

interface ListRow {
    id: number;
    name: string;
    needs_note: boolean;
    needs_manager: boolean;
    is_active: boolean;
}

interface Shift {
    id: number;
    name: string;
    starts_at: string;
    ends_at: string;
    is_active: boolean;
}

const props = defineProps<{
    branch: Settings;
    methods: { value: string; label: string }[];
    active_discounts: number;
    shifts: Shift[];
    lists: { key: string; label: string; rows: ListRow[] }[];
}>();

const GROUP_KEYS: Record<Group, (keyof Settings)[]> = {
    stays: [
        'hourly_enabled',
        'overnight_enabled',
        'overnight_checkin_time',
        'overnight_checkout_time',
        'long_stay_enabled',
        'floor_room_rates_enabled',
        'long_stay_max_days',
        'extension_rule',
        'extension_cycle_hours',
        'extension_per_hour_amount',
        'due_soon_minutes',
    ],
    money: ['deposit_enabled', 'deposit_name', 'deposit_amount', 'lost_key_amount', 'lost_remote_amount', 'payment_methods'],
    shifts: ['max_frontdesks_per_shift', 'shift_overdue_warn_minutes', 'shift_stale_block_minutes'],
    modules: [
        'kiosk_enabled',
        'kiosk_hold_minutes',
        'reservations_enabled',
        'reservation_release_minutes',
        'pos_enabled',
        'housekeeping_enabled',
        'cleaning_deadline_minutes',
        'cleaning_target_minutes',
        'cleaning_urgent_minutes',
    ],
    vat: ['vat_enabled', 'vat_rate_percent', 'tin', 'registered_name'],
    printing: ['name', 'slip_header', 'address', 'house_rules'],
};
const CARD_GROUPS = ['stays', 'money', 'modules', 'vat'] as const;
const GROUP_TITLE: Record<Group, string> = { stays: 'Stays', money: 'Money', shifts: 'Shifts', modules: 'Modules', vat: 'VAT', printing: 'Branch details' };

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

/** "14:00" → "2 PM", "12:00" → "12 NN", "00:00" → "12 MN", "08:30" → "8:30 AM". */
function clock(hhmm: string): string {
    const [h, m] = hhmm.split(':').map((n) => Number.parseInt(n, 10));

    if (m === 0 && h === 12) {
        return '12 NN';
    }

    if (m === 0 && h === 0) {
        return '12 MN';
    }

    const hour = h % 12 === 0 ? 12 : h % 12;

    return `${hour}${m ? `:${String(m).padStart(2, '0')}` : ''} ${h < 12 ? 'AM' : 'PM'}`;
}

function minutes(n: number): string {
    return n % 60 === 0 ? `${n / 60} h` : n > 60 ? `${Math.floor(n / 60)} h ${n % 60} min` : `${n} min`;
}

const b = computed(() => props.branch);
const methodLabel = computed(() => Object.fromEntries(props.methods.map((m) => [m.value, m.label])));
const ruleLabel = { cycle: '24-hour cycle', per_hour: 'Per hour', block: 'Per block' } as const;

const summary = computed(() => ({
    stays: [
        ['Hourly stays', b.value.hourly_enabled ? 'On · 6, 12, 18, 24' : 'Off'],
        ['Overnight stays', b.value.overnight_enabled ? `On · ${clock(b.value.overnight_checkin_time)} to ${clock(b.value.overnight_checkout_time)}` : 'Off'],
        ['Long stay per day', b.value.long_stay_enabled ? `On · up to ${b.value.long_stay_max_days} days` : 'Off'],
        [
            'Extension pricing',
            b.value.extension_rule === 'cycle'
                ? `${b.value.extension_cycle_hours}-hour cycle`
                : b.value.extension_rule === 'per_hour'
                  ? `${formatMoney(b.value.extension_per_hour_amount)} per hour`
                  : ruleLabel.block,
        ],
        ['Due-soon warning', `${b.value.due_soon_minutes} minutes`],
    ],
    money: [
        ['Deposit at check-in', b.value.deposit_enabled ? `On · ${formatMoney(b.value.deposit_amount)}` : 'Off'],
        ['Deposit name', b.value.deposit_name],
        ['Lost key / lost remote', `${formatMoney(b.value.lost_key_amount, 'tile')} / ${formatMoney(b.value.lost_remote_amount, 'tile')}`],
        ['Payment methods', b.value.payment_methods.map((m) => methodLabel.value[m] ?? m).join(' · ')],
        ['Discounts', `${props.active_discounts} active`],
    ],
    modules: [
        ['Kiosk', b.value.kiosk_enabled ? `On · hold ${b.value.kiosk_hold_minutes} min` : 'Off'],
        ['Reservations', b.value.reservations_enabled ? `On · release after ${minutes(b.value.reservation_release_minutes)}` : 'Off'],
        ['POS and stock', b.value.pos_enabled ? 'On' : 'Off'],
        ['Housekeeping', b.value.housekeeping_enabled ? `On · ${minutes(b.value.cleaning_deadline_minutes)} / ${minutes(b.value.cleaning_target_minutes)}` : 'Off'],
    ],
    vat: [
        ['VAT-registered', b.value.vat_enabled ? `Yes · ${b.value.vat_rate_percent}%` : 'No'],
        ['TIN', b.value.tin || '—'],
        ['Registered name', b.value.registered_name || '—'],
    ],
}));

const houseRuleLines = computed(() => (b.value.house_rules ?? '').split('\n').filter((l) => l.trim() !== '').length);

// Group dialog -------------------------------------------------------------------------------
const group = ref<Group | null>(null);
const groupOpen = computed({ get: () => group.value !== null, set: (v: boolean) => !v && (group.value = null) });
const form = useForm<Settings>({ ...props.branch });

function edit(g: Group): void {
    Object.assign(form, { ...props.branch, payment_methods: [...props.branch.payment_methods] });
    form.clearErrors();
    formError.value = undefined;
    group.value = g;
}

function saveGroup(): void {
    const g = group.value;

    if (!g) {
        return;
    }

    form.transform((data) => ({ group: g, ...Object.fromEntries(GROUP_KEYS[g].map((k) => [k, data[k]])) }));
    form.put(route('admin.settings.update'), done(() => (group.value = null)));
}

function toggleMethod(value: string, on: boolean): void {
    form.payment_methods = on ? [...new Set([...form.payment_methods, value])] : form.payment_methods.filter((m) => m !== value);
}

// Shift dialog -------------------------------------------------------------------------------
const shiftOpen = ref(false);
const shiftEditing = ref<Shift | null>(null);
const shiftForm = useForm({ name: '', starts_at: '08:00', ends_at: '20:00', is_active: true });

function openShift(s: Shift | null): void {
    shiftEditing.value = s;
    formError.value = undefined;
    shiftForm.clearErrors();
    shiftForm.name = s?.name ?? '';
    shiftForm.starts_at = s?.starts_at ?? '08:00';
    shiftForm.ends_at = s?.ends_at ?? '20:00';
    shiftForm.is_active = s?.is_active ?? true;
    shiftOpen.value = true;
}

function saveShift(): void {
    const s = shiftEditing.value;
    shiftForm.submit(s ? 'put' : 'post', s ? route('admin.shift-definitions.update', s.id) : route('admin.shift-definitions.store'), done(() => (shiftOpen.value = false)));
}

// Reason lists -------------------------------------------------------------------------------
const listKey = ref<string | null>(null);
const listOpen = computed({ get: () => listKey.value !== null, set: (v: boolean) => !v && (listKey.value = null) });
const list = computed(() => props.lists.find((l) => l.key === listKey.value) ?? null);

const reasonOpen = ref(false);
const reasonEditing = ref<ListRow | null>(null);
const reasonForm = useForm({ name: '', needs_note: false, needs_manager: false, is_active: true });

function openReason(row: ListRow | null): void {
    reasonEditing.value = row;
    formError.value = undefined;
    reasonForm.clearErrors();
    reasonForm.name = row?.name ?? '';
    reasonForm.needs_note = row?.needs_note ?? false;
    reasonForm.needs_manager = row?.needs_manager ?? false;
    reasonForm.is_active = row?.is_active ?? true;
    reasonOpen.value = true;
}

function saveReason(): void {
    const row = reasonEditing.value;
    const key = listKey.value;

    if (!key) {
        return;
    }

    reasonForm.submit(row ? 'put' : 'post', row ? route('admin.lists.update', { list: key, reason: row.id }) : route('admin.lists.store', { list: key }), done(() => (reasonOpen.value = false)));
}
</script>

<template>
    <AdminLayout current="settings" :title="`Branch settings · ${branch.name.replace(/^Hotel /, '')}`">
        <Head title="Branch settings" />

        <div class="grid grid-cols-2 gap-4 max-[900px]:grid-cols-1">
            <section v-for="g in CARD_GROUPS" :key="g" class="rounded-card border border-default bg-default px-[18px] py-4" :data-group="g">
                <div class="mb-2 flex items-center">
                    <h3 class="text-[15px] font-semibold text-highlighted">{{ GROUP_TITLE[g] }}</h3>
                    <UButton class="ms-auto" color="neutral" variant="ghost" size="sm" icon="i-solar-pen-bold-duotone" label="Edit" @click="edit(g)" />
                </div>
                <dl class="flex flex-col">
                    <div v-for="[k, v] in summary[g]" :key="k" class="flex items-baseline justify-between gap-3 border-b border-default py-1.5 text-[13px] last:border-b-0">
                        <dt class="text-muted">{{ k }}</dt>
                        <dd class="text-right font-semibold text-highlighted">{{ v }}</dd>
                    </div>
                </dl>
            </section>

            <section class="rounded-card border border-default bg-default px-[18px] py-4" data-group="shifts">
                <div class="mb-2 flex items-center">
                    <h3 class="text-[15px] font-semibold text-highlighted">Shifts</h3>
                    <UButton class="ms-auto" color="neutral" variant="ghost" size="sm" icon="i-solar-pen-bold-duotone" label="Edit" @click="edit('shifts')" />
                </div>
                <dl class="flex flex-col">
                    <div v-for="s in shifts" :key="s.id" class="flex items-center justify-between gap-3 border-b border-default py-1.5 text-[13px]">
                        <dt class="text-muted">
                            {{ s.name }}
                            <StatusLabel v-if="!s.is_active" class="ms-1" label="Off" />
                        </dt>
                        <dd class="flex items-center gap-1 font-semibold text-highlighted">
                            {{ clock(s.starts_at) }} – {{ clock(s.ends_at) }}
                            <UButton color="neutral" variant="ghost" size="xs" icon="i-solar-pen-bold-duotone" :aria-label="`Edit the ${s.name} shift`" @click="openShift(s)" />
                        </dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3 border-b border-default py-1.5 text-[13px]">
                        <dt class="text-muted">Front desks per shift</dt>
                        <dd class="font-semibold text-highlighted">Up to {{ branch.max_frontdesks_per_shift }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3 border-b border-default py-1.5 text-[13px]">
                        <dt class="text-muted">Shift overdue warning</dt>
                        <dd class="font-semibold text-highlighted">{{ minutes(branch.shift_overdue_warn_minutes) }} past its end</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3 py-1.5 text-[13px]">
                        <dt class="text-muted">Stale shift stops payments</dt>
                        <dd class="font-semibold text-highlighted">{{ minutes(branch.shift_stale_block_minutes) }} past its end</dd>
                    </div>
                </dl>
                <UButton class="mt-2" color="neutral" variant="outline" size="sm" icon="i-solar-add-square-linear" label="Add shift" @click="openShift(null)" />
            </section>
        </div>

        <section class="mt-4 rounded-card border border-default bg-default px-[18px] py-4">
            <h3 class="mb-2 text-[15px] font-semibold text-highlighted">Lists and printing</h3>
            <div class="flex flex-wrap gap-2">
                <UButton v-for="l in lists" :key="l.key" color="neutral" variant="soft" size="sm" :label="`${l.label} · ${l.rows.filter((r) => r.is_active).length}`" @click="listKey = l.key" />
                <UButton color="neutral" variant="soft" size="sm" :label="`House rules · ${houseRuleLines} lines`" @click="edit('printing')" />
                <UButton color="neutral" variant="soft" size="sm" label="Branch name, slip & address" @click="edit('printing')" />
            </div>
        </section>

        <!-- Group dialog -->
        <UModal v-model:open="groupOpen" :title="group ? GROUP_TITLE[group] : ''" :ui="{ content: 'max-w-[520px]' }">
            <template #body>
                <div class="flex flex-col gap-4">
                    <UAlert v-if="formError" color="error" variant="subtle" icon="i-solar-danger-triangle-bold-duotone" :description="formError" data-form-error />

                    <template v-if="group === 'stays'">
                        <USwitch v-model="form.hourly_enabled" label="Hourly stays (6, 12, 18, 24 hours)" description="Off removes the hourly options from check-in, the kiosk and the rate table." />
                        <USwitch v-model="form.overnight_enabled" label="Overnight stays" />
                        <div v-if="form.overnight_enabled" class="grid grid-cols-2 gap-3">
                            <UFormField label="Check-in from" :error="form.errors.overnight_checkin_time"><UInput v-model="form.overnight_checkin_time" type="time" class="w-full" /></UFormField>
                            <UFormField label="Check-out by" :error="form.errors.overnight_checkout_time"><UInput v-model="form.overnight_checkout_time" type="time" class="w-full" /></UFormField>
                        </div>
                        <USwitch v-model="form.long_stay_enabled" label="Long stay per day" description="Priced from the 24-hour rate." />
                        <UFormField v-if="form.long_stay_enabled" label="Longest stay (days)" :error="form.errors.long_stay_max_days">
                            <UInput v-model.number="form.long_stay_max_days" type="number" :min="1" :max="31" class="w-28" />
                        </UFormField>
                        <USwitch v-model="form.floor_room_rates_enabled" label="Floor &amp; room rates" description="Lets the rate table set a flat rate for a whole floor, or a rate for one room, on top of the room-type rates." />
                        <div class="grid grid-cols-2 gap-3">
                            <UFormField label="Extension pricing" :error="form.errors.extension_rule">
                                <USelect
                                    v-model="form.extension_rule"
                                    :items="[
                                        { label: '24-hour cycle', value: 'cycle' },
                                        { label: 'Per hour', value: 'per_hour' },
                                        { label: 'Per block', value: 'block' },
                                    ]"
                                    class="w-full"
                                />
                            </UFormField>
                            <UFormField v-if="form.extension_rule === 'cycle'" label="Cycle (hours)" :error="form.errors.extension_cycle_hours">
                                <UInput v-model.number="form.extension_cycle_hours" type="number" :min="1" :max="24" class="w-full" />
                            </UFormField>
                            <UFormField v-else-if="form.extension_rule === 'per_hour'" label="Amount per hour" :error="form.errors.extension_per_hour_amount">
                                <MoneyInput v-model="form.extension_per_hour_amount" />
                            </UFormField>
                        </div>
                        <UFormField label="Due-soon warning (minutes before check-out)" :error="form.errors.due_soon_minutes">
                            <UInput v-model.number="form.due_soon_minutes" type="number" :min="1" class="w-28" />
                        </UFormField>
                    </template>

                    <template v-else-if="group === 'money'">
                        <USwitch v-model="form.deposit_enabled" label="Deposit at check-in" />
                        <div v-if="form.deposit_enabled" class="grid grid-cols-2 gap-3">
                            <UFormField label="Deposit name" :error="form.errors.deposit_name"><UInput v-model="form.deposit_name" maxlength="40" class="w-full" /></UFormField>
                            <UFormField label="Deposit" :error="form.errors.deposit_amount"><MoneyInput v-model="form.deposit_amount" /></UFormField>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <UFormField label="Lost key" :error="form.errors.lost_key_amount"><MoneyInput v-model="form.lost_key_amount" /></UFormField>
                            <UFormField label="Lost remote" :error="form.errors.lost_remote_amount"><MoneyInput v-model="form.lost_remote_amount" /></UFormField>
                        </div>
                        <UFormField label="Payment methods accepted" :error="form.errors.payment_methods" help="At least one.">
                            <div class="flex flex-wrap gap-4">
                                <UCheckbox
                                    v-for="m in methods"
                                    :key="m.value"
                                    :label="m.label"
                                    :model-value="form.payment_methods.includes(m.value)"
                                    @update:model-value="(v) => toggleMethod(m.value, v === true)"
                                />
                            </div>
                        </UFormField>
                    </template>

                    <template v-else-if="group === 'shifts'">
                        <UFormField label="Front desks per shift" :error="form.errors.max_frontdesks_per_shift">
                            <UInput v-model.number="form.max_frontdesks_per_shift" type="number" :min="1" :max="10" class="w-28" />
                        </UFormField>
                        <UFormField label="Warn when a shift is past its end by (minutes)" :error="form.errors.shift_overdue_warn_minutes">
                            <UInput v-model.number="form.shift_overdue_warn_minutes" type="number" :min="1" class="w-28" />
                        </UFormField>
                        <UFormField
                            label="Stop payments and charges on a shift past its end by (minutes)"
                            :error="form.errors.shift_stale_block_minutes"
                            help="The stale shift must then be closed before more money is taken (54)."
                        >
                            <UInput v-model.number="form.shift_stale_block_minutes" type="number" :min="1" class="w-28" />
                        </UFormField>
                    </template>

                    <template v-else-if="group === 'modules'">
                        <USwitch v-model="form.kiosk_enabled" label="Kiosk" />
                        <UFormField v-if="form.kiosk_enabled" label="Hold a kiosk room for (minutes)" :error="form.errors.kiosk_hold_minutes">
                            <UInput v-model.number="form.kiosk_hold_minutes" type="number" :min="1" class="w-28" />
                        </UFormField>
                        <USwitch v-model="form.reservations_enabled" label="Reservations" />
                        <UFormField v-if="form.reservations_enabled" label="Release a no-show after (minutes)" :error="form.errors.reservation_release_minutes">
                            <UInput v-model.number="form.reservation_release_minutes" type="number" :min="1" class="w-28" />
                        </UFormField>
                        <USwitch v-model="form.pos_enabled" label="POS and stock" />
                        <USwitch v-model="form.housekeeping_enabled" label="Housekeeping" description="Off: a room goes straight to Available at check-out." />
                        <div v-if="form.housekeeping_enabled" class="grid grid-cols-3 gap-3">
                            <UFormField label="Clean within (min)" :error="form.errors.cleaning_deadline_minutes"><UInput v-model.number="form.cleaning_deadline_minutes" type="number" :min="1" class="w-full" /></UFormField>
                            <UFormField label="Target (min)" :error="form.errors.cleaning_target_minutes"><UInput v-model.number="form.cleaning_target_minutes" type="number" :min="1" class="w-full" /></UFormField>
                            <UFormField label="Urgent after (min)" :error="form.errors.cleaning_urgent_minutes"><UInput v-model.number="form.cleaning_urgent_minutes" type="number" :min="1" class="w-full" /></UFormField>
                        </div>
                    </template>

                    <template v-else-if="group === 'vat'">
                        <USwitch v-model="form.vat_enabled" label="VAT-registered" description="Adds the VAT block to the Z-report; prices are VAT-inclusive." />
                        <template v-if="form.vat_enabled">
                            <UFormField label="VAT rate (%)" :error="form.errors.vat_rate_percent"><UInput v-model.number="form.vat_rate_percent" type="number" :min="1" :max="100" class="w-28" /></UFormField>
                            <UFormField label="TIN" :error="form.errors.tin"><UInput v-model="form.tin" maxlength="20" class="w-full" placeholder="000-000-000-000" /></UFormField>
                            <UFormField label="Registered name" :error="form.errors.registered_name"><UInput v-model="form.registered_name" maxlength="120" class="w-full" /></UFormField>
                        </template>
                    </template>

                    <template v-else-if="group === 'printing'">
                        <UFormField label="Branch name" :error="form.errors.name" help="Shown across the app and on reports."><UInput v-model="form.name" maxlength="80" class="w-full" /></UFormField>
                        <UFormField label="Slip header" :error="form.errors.slip_header"><UInput v-model="form.slip_header" maxlength="80" class="w-full" /></UFormField>
                        <UFormField label="Address" :error="form.errors.address"><UInput v-model="form.address" maxlength="160" class="w-full" /></UFormField>
                        <UFormField label="House rules" :error="form.errors.house_rules" help="One rule per line. Shown on the kiosk and the check-in slip.">
                            <UTextarea v-model="form.house_rules" :rows="5" class="w-full" />
                        </UFormField>
                    </template>
                </div>
            </template>
            <template #footer>
                <div class="flex w-full justify-end gap-2">
                    <UButton color="neutral" variant="ghost" label="Cancel" @click="group = null" />
                    <UButton label="Save" :loading="form.processing" @click="saveGroup" />
                </div>
            </template>
        </UModal>

        <!-- Shift dialog -->
        <UModal v-model:open="shiftOpen" :title="shiftEditing ? `${shiftEditing.name} shift` : 'Add shift'" :ui="{ content: 'max-w-[400px]' }">
            <template #body>
                <div class="flex flex-col gap-4">
                    <UAlert v-if="formError" color="error" variant="subtle" icon="i-solar-danger-triangle-bold-duotone" :description="formError" data-form-error />
                    <UFormField label="Name" :error="shiftForm.errors.name"><UInput v-model="shiftForm.name" maxlength="20" class="w-full" placeholder="Night" /></UFormField>
                    <div class="grid grid-cols-2 gap-3">
                        <UFormField label="Starts" :error="shiftForm.errors.starts_at"><UInput v-model="shiftForm.starts_at" type="time" class="w-full" /></UFormField>
                        <UFormField label="Ends" :error="shiftForm.errors.ends_at" help="May be the next morning."><UInput v-model="shiftForm.ends_at" type="time" class="w-full" /></UFormField>
                    </div>
                    <USwitch v-model="shiftForm.is_active" label="In use" />
                </div>
            </template>
            <template #footer>
                <div class="flex w-full justify-end gap-2">
                    <UButton color="neutral" variant="ghost" label="Cancel" @click="shiftOpen = false" />
                    <UButton :label="shiftEditing ? 'Save shift' : 'Add shift'" :disabled="!shiftForm.name.trim()" :loading="shiftForm.processing" @click="saveShift" />
                </div>
            </template>
        </UModal>

        <!-- Reason list -->
        <USlideover v-model:open="listOpen" :title="list?.label ?? ''" :ui="{ content: 'max-w-[480px]' }">
            <template #body>
                <EmptyState v-if="list && list.rows.length === 0" compact title="No reasons in this list yet." />
                <table v-else-if="list" class="dtable w-full">
                    <thead>
                        <tr>
                            <th>Reason</th>
                            <th>Needs</th>
                            <th><span class="sr-only">Edit</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in list.rows" :key="r.id" :class="r.is_active ? '' : 'text-dimmed'">
                            <td class="!whitespace-normal text-highlighted">{{ r.name }}</td>
                            <td class="flex flex-wrap gap-1">
                                <StatusLabel v-if="r.needs_note" tone="info" label="Note" />
                                <StatusLabel v-if="r.needs_manager" tone="warn" label="Supervisor PIN" />
                                <StatusLabel v-if="!r.is_active" label="Off" />
                                <span v-if="!r.needs_note && !r.needs_manager && r.is_active" class="text-muted">—</span>
                            </td>
                            <td class="text-right"><UButton color="neutral" variant="ghost" size="sm" icon="i-solar-pen-2-linear" label="Edit" @click="openReason(r)" /></td>
                        </tr>
                    </tbody>
                </table>
            </template>
            <template #footer>
                <UButton icon="i-solar-add-square-linear" label="Add reason" @click="openReason(null)" />
            </template>
        </USlideover>

        <UModal v-model:open="reasonOpen" :title="reasonEditing ? reasonEditing.name : `Add to ${list?.label.toLowerCase() ?? 'the list'}`" :ui="{ content: 'max-w-[420px]' }">
            <template #body>
                <div class="flex flex-col gap-4">
                    <UAlert v-if="formError" color="error" variant="subtle" icon="i-solar-danger-triangle-bold-duotone" :description="formError" data-form-error />
                    <UFormField label="Reason" :error="reasonForm.errors.name"><UInput v-model="reasonForm.name" maxlength="60" class="w-full" /></UFormField>
                    <USwitch v-model="reasonForm.needs_note" label="Needs a note" />
                    <USwitch v-model="reasonForm.needs_manager" label="Needs a supervisor PIN" />
                    <USwitch v-model="reasonForm.is_active" label="Shown in the list" />
                </div>
            </template>
            <template #footer>
                <div class="flex w-full justify-end gap-2">
                    <UButton color="neutral" variant="ghost" label="Cancel" @click="reasonOpen = false" />
                    <UButton :label="reasonEditing ? 'Save reason' : 'Add reason'" :disabled="!reasonForm.name.trim()" :loading="reasonForm.processing" @click="saveReason" />
                </div>
            </template>
        </UModal>
    </AdminLayout>
</template>
