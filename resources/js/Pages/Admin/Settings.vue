<!-- Mockup 46 -->
<script setup lang="ts">
import type { UseFormSubmitOptions } from '@inertiajs/core';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import MoneyInput from '@/Components/MoneyInput.vue';
import PeriodSegments from '@/Components/PeriodSegments.vue';
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
    hourly_blocks: number[];
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
    denomination_count_enabled: boolean;
    max_frontdesks_per_shift: number;
    shift_overdue_warn_minutes: number;
    shift_stale_block_minutes: number;
    kiosk_enabled: boolean;
    kiosk_hold_minutes: number;
    kiosk_checkout_enabled: boolean;
    reservations_enabled: boolean;
    reservation_release_minutes: number;
    event_hall_enabled: boolean;
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
    branch: Settings;
    methods: { value: string; label: string }[];
    active_discounts: number;
    discounts: Discount[];
    shifts: Shift[];
    lists: { key: string; label: string; rows: ListRow[] }[];
}>();

const GROUP_KEYS: Record<Group, (keyof Settings)[]> = {
    stays: [
        'hourly_enabled',
        'hourly_blocks',
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
    money: ['deposit_enabled', 'deposit_name', 'deposit_amount', 'lost_key_amount', 'lost_remote_amount', 'payment_methods', 'denomination_count_enabled'],
    shifts: ['max_frontdesks_per_shift', 'shift_overdue_warn_minutes', 'shift_stale_block_minutes'],
    modules: [
        'kiosk_enabled',
        'kiosk_hold_minutes',
        'kiosk_checkout_enabled',
        'reservations_enabled',
        'reservation_release_minutes',
        'event_hall_enabled',
        'pos_enabled',
        'housekeeping_enabled',
        'cleaning_deadline_minutes',
        'cleaning_target_minutes',
        'cleaning_urgent_minutes',
    ],
    vat: ['vat_enabled', 'vat_rate_percent', 'tin', 'registered_name'],
    printing: ['name', 'slip_header', 'address', 'house_rules'],
};
const GROUP_TITLE: Record<Group, string> = { stays: 'Stays', money: 'Money', shifts: 'Shifts', modules: 'Modules', vat: 'VAT', printing: 'Branch details' };

// One section at a time, as a table (46 redesign).
type Tab = 'stays' | 'money' | 'modules' | 'vat' | 'shifts' | 'discounts' | 'details';
const SUMMARY_TABS: Tab[] = ['stays', 'money', 'modules', 'vat'];
const tab = ref<Tab>('stays');
const tabItems: { value: Tab; label: string }[] = [
    { value: 'stays', label: 'Stays' },
    { value: 'money', label: 'Money' },
    { value: 'modules', label: 'Modules' },
    { value: 'vat', label: 'VAT' },
    { value: 'shifts', label: 'Shifts' },
    { value: 'discounts', label: 'Discounts' },
    { value: 'details', label: 'Lists & printing' },
];
const tabLabel = computed(() => tabItems.find((t) => t.value === tab.value)?.label ?? '');
const isSummaryTab = computed(() => SUMMARY_TABS.includes(tab.value));
// Each setting row is edited on its own (one focused dialog per row).
type RowKey =
    | 'hourly' | 'overnight' | 'long_stay' | 'floor_room' | 'extension' | 'due_soon'
    | 'deposit' | 'deposit_name' | 'lost' | 'payment_methods' | 'cash_count'
    | 'kiosk' | 'reservations' | 'event_halls' | 'pos' | 'housekeeping'
    | 'vat' | 'tin' | 'registered_name'
    | 'frontdesks' | 'overdue' | 'stale';
interface Row {
    key: RowKey;
    label: string;
    value: string;
    hint: string;
}
// The group each row saves through (the server validates per group).
const ROW_GROUP: Record<RowKey, Group> = {
    hourly: 'stays', overnight: 'stays', long_stay: 'stays', floor_room: 'stays', extension: 'stays', due_soon: 'stays',
    deposit: 'money', deposit_name: 'money', lost: 'money', payment_methods: 'money', cash_count: 'money',
    kiosk: 'modules', reservations: 'modules', event_halls: 'modules', pos: 'modules', housekeeping: 'modules',
    vat: 'vat', tin: 'vat', registered_name: 'vat',
    frontdesks: 'shifts', overdue: 'shifts', stale: 'shifts',
};
const ROW_TITLE: Record<RowKey, string> = {
    hourly: 'Hourly stays', overnight: 'Overnight stays', long_stay: 'Long stay per day', floor_room: 'Floor & room rates', extension: 'Extension pricing', due_soon: 'Due-soon warning',
    deposit: 'Deposit at check-in', deposit_name: 'Deposit name', lost: 'Lost key / lost remote', payment_methods: 'Payment methods', cash_count: 'Shift cash count',
    kiosk: 'Kiosk', reservations: 'Reservations', event_halls: 'Event halls', pos: 'POS and stock', housekeeping: 'Housekeeping',
    vat: 'VAT', tin: 'TIN', registered_name: 'Registered name',
    frontdesks: 'Front desks per shift', overdue: 'Shift overdue warning', stale: 'Stale shift stops payments',
};

const summaryRows = computed<Row[]>(() => (isSummaryTab.value ? summary.value[tab.value as 'stays' | 'money' | 'modules' | 'vat'] : []));
const shiftSettingRows = computed<Row[]>(() => [
    { key: 'frontdesks', label: 'Front desks per shift', value: `Up to ${props.branch.max_frontdesks_per_shift}`, hint: 'How many front desk staff can share one open shift.' },
    { key: 'overdue', label: 'Shift overdue warning', value: `${minutes(props.branch.shift_overdue_warn_minutes)} past its end`, hint: 'Warn the desk when a shift runs past its end by this long.' },
    { key: 'stale', label: 'Stale shift stops payments', value: `${minutes(props.branch.shift_stale_block_minutes)} past its end`, hint: 'Block new charges once a shift is this far past its end.' },
]);

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

// Each tab's rows: the value shown and a plain-language sub-label; `key` picks the per-row editor.
const summary = computed<Record<'stays' | 'money' | 'modules' | 'vat', Row[]>>(() => ({
    stays: [
        { key: 'hourly', label: 'Hourly stays', value: b.value.hourly_enabled ? `On · ${[...b.value.hourly_blocks].sort((x, y) => x - y).join(', ')} hrs` : 'Off', hint: 'Sell rooms by the hour. Add or remove the hour blocks you offer.' },
        { key: 'overnight', label: 'Overnight stays', value: b.value.overnight_enabled ? `On · ${clock(b.value.overnight_checkin_time)} to ${clock(b.value.overnight_checkout_time)}` : 'Off', hint: 'One overnight rate with a fixed check-in and check-out time.' },
        { key: 'long_stay', label: 'Long stay per day', value: b.value.long_stay_enabled ? `On · up to ${b.value.long_stay_max_days} days` : 'Off', hint: 'Whole-day stays, priced per day from the 24-hour rate.' },
        { key: 'floor_room', label: 'Floor & room rates', value: b.value.floor_room_rates_enabled ? 'On' : 'Off', hint: 'Let the rate table set a price for a whole floor or one room.' },
        {
            key: 'extension',
            label: 'Extension pricing',
            value: b.value.extension_rule === 'cycle' ? `${b.value.extension_cycle_hours}-hour cycle` : b.value.extension_rule === 'per_hour' ? `${formatMoney(b.value.extension_per_hour_amount)} per hour` : ruleLabel.block,
            hint: 'How a guest who stays past check-out is charged for the extra time.',
        },
        { key: 'due_soon', label: 'Due-soon warning', value: `${b.value.due_soon_minutes} minutes`, hint: 'Flag a room as “due out” this long before its check-out time.' },
    ],
    money: [
        { key: 'deposit', label: 'Deposit at check-in', value: b.value.deposit_enabled ? `On · ${formatMoney(b.value.deposit_amount)}` : 'Off', hint: 'A refundable deposit collected when the guest checks in.' },
        { key: 'deposit_name', label: 'Deposit name', value: b.value.deposit_name, hint: 'What the deposit is called on the slip and the folio.' },
        { key: 'lost', label: 'Lost key / lost remote', value: `${formatMoney(b.value.lost_key_amount, 'tile')} / ${formatMoney(b.value.lost_remote_amount, 'tile')}`, hint: 'Charged when a guest does not return the key or the remote.' },
        { key: 'payment_methods', label: 'Payment methods', value: b.value.payment_methods.map((m) => methodLabel.value[m] ?? m).join(' · '), hint: 'The ways the front desk is allowed to accept payment.' },
        { key: 'cash_count', label: 'Shift cash count', value: b.value.denomination_count_enabled ? 'By denomination' : 'Total only', hint: 'How the cash drawer is counted when a shift is closed.' },
    ],
    modules: [
        { key: 'kiosk', label: 'Kiosk', value: b.value.kiosk_enabled ? `On · hold ${b.value.kiosk_hold_minutes} min${b.value.kiosk_checkout_enabled ? ' · checkout' : ''}` : 'Off', hint: 'The lobby self-service screen where guests hold a room (and can request checkout).' },
        { key: 'reservations', label: 'Reservations', value: b.value.reservations_enabled ? `On · release after ${minutes(b.value.reservation_release_minutes)}` : 'Off', hint: 'Book a room ahead of time and hold it until a release time.' },
        { key: 'event_halls', label: 'Event halls', value: b.value.event_hall_enabled ? 'On' : 'Off', hint: 'Book function halls for events, with deposits and packages.' },
        { key: 'pos', label: 'POS and stock', value: b.value.pos_enabled ? 'On' : 'Off', hint: 'Sell food and drinks at the desk and track their stock.' },
        { key: 'housekeeping', label: 'Housekeeping', value: b.value.housekeeping_enabled ? `On · ${minutes(b.value.cleaning_deadline_minutes)} / ${minutes(b.value.cleaning_target_minutes)}` : 'Off', hint: 'Rooms go to cleaning at check-out for the roomboys to clean.' },
    ],
    vat: [
        { key: 'vat', label: 'VAT-registered', value: b.value.vat_enabled ? `Yes · ${b.value.vat_rate_percent}%` : 'No', hint: 'Adds the VAT block to the Z-report; prices are VAT-inclusive.' },
        { key: 'tin', label: 'TIN', value: b.value.tin || '—', hint: 'Your BIR taxpayer identification number, shown on receipts.' },
        { key: 'registered_name', label: 'Registered name', value: b.value.registered_name || '—', hint: 'The registered business name printed on official receipts.' },
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

// Per-row dialog: edit one setting at a time, saved through its group ------------------------
const editRow = ref<RowKey | null>(null);
const rowOpen = computed({ get: () => editRow.value !== null, set: (v: boolean) => !v && (editRow.value = null) });

function openRow(r: RowKey): void {
    Object.assign(form, { ...props.branch, payment_methods: [...props.branch.payment_methods] });
    form.clearErrors();
    formError.value = undefined;
    editRow.value = r;
}

function saveRow(): void {
    const r = editRow.value;

    if (!r) {
        return;
    }

    const g = ROW_GROUP[r];
    form.transform((data) => ({ group: g, ...Object.fromEntries(GROUP_KEYS[g].map((k) => [k, data[k]])) }));
    form.put(route('admin.settings.update'), done(() => (editRow.value = null)));
}

// Hourly blocks (editing the 'hourly' row): add or remove an hour the branch sells.
const newBlock = ref<number | null>(null);

function addBlock(): void {
    const h = Math.round(newBlock.value ?? 0);

    if (h >= 1 && h <= 24 && !form.hourly_blocks.includes(h)) {
        form.hourly_blocks = [...form.hourly_blocks, h].sort((a, b) => a - b);
    }

    newBlock.value = null;
}

function removeBlock(h: number): void {
    form.hourly_blocks = form.hourly_blocks.filter((x) => x !== h);
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

// Discounts ----------------------------------------------------------------------------------
const togglingId = ref<number | null>(null);

function discountValue(d: Discount): string {
    return d.kind === 'percent' ? `${d.value}%` : `${formatMoney(d.value, 'tile')} off`;
}

/** Flip a discount on or off in place; the full discount is sent so the audited action records only the active change. New check-ins use it; open stays keep their price. */
function toggleDiscount(d: Discount, on: boolean): void {
    togglingId.value = d.id;
    router.put(
        route('admin.discounts.update', d.id),
        { name: d.name, kind: d.kind, value: d.value, all_rates: d.all_rates, kiosk_enabled: d.kiosk_enabled, needs_id: d.needs_id, is_vat_exempt: d.is_vat_exempt, is_active: on, rate_ids: d.rate_ids },
        { preserveScroll: true, onFinish: () => (togglingId.value = null) },
    );
}
</script>

<template>
    <AdminLayout current="settings" :title="`Branch settings · ${branch.name.replace(/^Hotel /, '')}`">
        <Head title="Branch settings" />

        <PeriodSegments v-model="tab" label="Settings section" :items="tabItems" class="mb-4" />

        <div class="rounded-card border border-default bg-default" :data-group="tab">
            <div class="flex items-center justify-between gap-2 border-b border-default px-5 py-3.5">
                <h2 class="text-[15px] font-semibold text-highlighted">{{ tabLabel }}</h2>
                <UButton v-if="tab === 'discounts'" color="neutral" variant="ghost" size="sm" icon="i-solar-pen-bold-duotone" label="Edit in Rates" :to="route('admin.rates')" />
                <span v-else-if="tab !== 'details'" class="text-[12px] text-muted">Tap a row to edit</span>
            </div>

            <!-- Stays · Money · Modules · VAT -->
            <table v-if="isSummaryTab" class="w-full">
                <tbody>
                    <tr
                        v-for="r in summaryRows"
                        :key="r.key"
                        class="group hm-focus cursor-pointer border-b border-default transition-colors last:border-b-0 hover:bg-elevated"
                        tabindex="0"
                        :aria-label="`Edit ${r.label}`"
                        @click="openRow(r.key)"
                        @keydown.enter="openRow(r.key)"
                    >
                        <th scope="row" class="px-5 py-3.5 text-left align-top">
                            <div class="text-[13.5px] font-medium text-highlighted">{{ r.label }}</div>
                            <div class="mt-0.5 max-w-prose text-[12px] leading-snug font-normal text-muted">{{ r.hint }}</div>
                        </th>
                        <td class="px-3 py-3.5 text-right align-top text-[13.5px] font-semibold whitespace-nowrap text-highlighted">{{ r.value }}</td>
                        <td class="w-10 py-3.5 pr-4 text-right align-top">
                            <UIcon name="i-solar-pen-2-linear" class="size-4 text-dimmed transition-colors group-hover:text-muted" aria-hidden="true" />
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Shifts -->
            <template v-else-if="tab === 'shifts'">
                <table class="w-full">
                    <tbody>
                        <tr
                            v-for="s in shifts"
                            :key="s.id"
                            class="hm-focus cursor-pointer border-b border-default transition-colors hover:bg-elevated"
                            tabindex="0"
                            :aria-label="`Edit the ${s.name} shift`"
                            @click="openShift(s)"
                            @keydown.enter="openShift(s)"
                        >
                            <th scope="row" class="px-5 py-3.5 text-left align-top">
                                <div class="flex items-center gap-1.5 text-[13.5px] font-medium text-highlighted">
                                    {{ s.name }} shift
                                    <StatusLabel v-if="!s.is_active" label="Off" />
                                </div>
                                <div class="mt-0.5 text-[12px] leading-snug font-normal text-muted">When this shift runs. The clock starts at the first open.</div>
                            </th>
                            <td class="px-3 py-3.5 text-right align-top text-[13.5px] font-semibold whitespace-nowrap text-highlighted">{{ clock(s.starts_at) }} – {{ clock(s.ends_at) }}</td>
                            <td class="w-10 py-3.5 pr-4 text-right align-top"><UIcon name="i-solar-pen-2-linear" class="size-4 text-dimmed" aria-hidden="true" /></td>
                        </tr>
                        <tr
                            v-for="row in shiftSettingRows"
                            :key="row.key"
                            class="hm-focus cursor-pointer border-b border-default transition-colors last:border-b-0 hover:bg-elevated"
                            tabindex="0"
                            :aria-label="`Edit ${row.label}`"
                            @click="openRow(row.key)"
                            @keydown.enter="openRow(row.key)"
                        >
                            <th scope="row" class="px-5 py-3.5 text-left align-top">
                                <div class="text-[13.5px] font-medium text-highlighted">{{ row.label }}</div>
                                <div class="mt-0.5 max-w-prose text-[12px] leading-snug font-normal text-muted">{{ row.hint }}</div>
                            </th>
                            <td class="px-3 py-3.5 text-right align-top text-[13.5px] font-semibold whitespace-nowrap text-highlighted">{{ row.value }}</td>
                            <td class="w-10 py-3.5 pr-4 text-right align-top"><UIcon name="i-solar-pen-2-linear" class="size-4 text-dimmed" aria-hidden="true" /></td>
                        </tr>
                    </tbody>
                </table>
                <div class="border-t border-default px-5 py-3">
                    <UButton color="neutral" variant="outline" size="sm" icon="i-solar-add-square-linear" label="Add shift" @click="openShift(null)" />
                </div>
            </template>

            <!-- Discounts -->
            <template v-else-if="tab === 'discounts'">
                <table v-if="discounts.length" class="w-full">
                    <tbody>
                        <tr v-for="d in discounts" :key="d.id" class="border-b border-default last:border-b-0">
                            <td class="px-5 py-3">
                                <span class="flex flex-wrap items-center gap-1.5 text-[13px]">
                                    <span class="font-semibold" :class="d.is_active ? 'text-highlighted' : 'text-dimmed'">{{ d.name }}</span>
                                    <span class="num text-muted">{{ discountValue(d) }}</span>
                                    <StatusLabel v-if="d.kiosk_enabled && d.needs_id" tone="primary" label="Kiosk · guest tick" />
                                    <StatusLabel v-else-if="d.kiosk_enabled" tone="primary" label="Kiosk · auto" />
                                    <StatusLabel v-if="d.is_vat_exempt" tone="info" label="VAT-exempt" />
                                </span>
                            </td>
                            <td class="px-5 py-3 text-right">
                                <USwitch :model-value="d.is_active" :disabled="togglingId === d.id" :aria-label="`${d.name} active`" @update:model-value="(v) => toggleDiscount(d, v === true)" />
                            </td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState v-else compact title="No discounts yet." class="py-10" />
                <p class="border-t border-default px-5 py-3 text-[12px] text-muted">Turn a discount on or off here. Set its amount or percent, rates and kiosk options in Rates.</p>
            </template>

            <!-- Lists & printing -->
            <div v-else-if="tab === 'details'" class="flex flex-wrap gap-2 p-5">
                <UButton v-for="l in lists" :key="l.key" color="neutral" variant="soft" size="sm" :label="`${l.label} · ${l.rows.filter((r) => r.is_active).length}`" @click="listKey = l.key" />
                <UButton color="neutral" variant="soft" size="sm" :label="`House rules · ${houseRuleLines} lines`" @click="edit('printing')" />
                <UButton color="neutral" variant="soft" size="sm" label="Branch name, slip & address" @click="edit('printing')" />
            </div>
        </div>

        <!-- Per-row editor: one setting at a time -->
        <UModal v-model:open="rowOpen" :title="editRow ? ROW_TITLE[editRow] : ''" :ui="{ content: 'max-w-[460px]' }">
            <template #body>
                <div class="flex flex-col gap-4">
                    <UAlert v-if="formError" color="error" variant="subtle" icon="i-solar-danger-triangle-bold-duotone" :description="formError" data-form-error />

                    <template v-if="editRow === 'hourly'">
                        <USwitch v-model="form.hourly_enabled" label="Hourly stays" description="Sell rooms by the hour. Off removes the hourly options from check-in, the kiosk and the rate table." />
                        <template v-if="form.hourly_enabled">
                            <div>
                                <div class="mb-1.5 text-[13px] font-medium text-default">Hour blocks offered</div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span
                                        v-for="h in [...form.hourly_blocks].sort((a, b) => a - b)"
                                        :key="h"
                                        class="inline-flex items-center gap-1.5 rounded-control border border-default bg-elevated py-1 pr-1 pl-2.5 text-[13px] font-medium text-highlighted"
                                    >
                                        {{ h }} hrs
                                        <button
                                            type="button"
                                            class="hm-focus grid size-5 place-items-center rounded-full text-muted hover:bg-default hover:text-bad"
                                            :aria-label="`Remove the ${h}-hour block`"
                                            @click="removeBlock(h)"
                                        >
                                            <UIcon name="i-solar-close-circle-linear" class="size-4" />
                                        </button>
                                    </span>
                                    <span v-if="!form.hourly_blocks.length" class="text-[12.5px] text-muted">No blocks yet — add one below.</span>
                                </div>
                                <p v-if="form.errors.hourly_blocks" class="mt-1.5 text-[12px] font-semibold text-error">{{ form.errors.hourly_blocks }}</p>
                            </div>
                            <div class="flex items-end gap-2">
                                <UFormField label="Add an hour block" class="flex-1">
                                    <UInput v-model.number="newBlock" type="number" :min="1" :max="24" placeholder="e.g. 3" class="w-full" @keydown.enter.prevent="addBlock" />
                                </UFormField>
                                <UButton color="neutral" variant="outline" icon="i-solar-add-square-linear" label="Add" :disabled="!newBlock" @click="addBlock" />
                            </div>
                            <p class="text-[12px] text-muted">Set the price for each block per room type in Setup → Rates. Removing a block clears its prices (not allowed while an open stay is using it).</p>
                        </template>
                    </template>
                    <template v-else-if="editRow === 'overnight'">
                        <USwitch v-model="form.overnight_enabled" label="Overnight stays" description="One overnight rate with a fixed check-in and check-out time." />
                        <div v-if="form.overnight_enabled" class="grid grid-cols-2 gap-3">
                            <UFormField label="Check-in from" :error="form.errors.overnight_checkin_time"><UInput v-model="form.overnight_checkin_time" type="time" class="w-full" /></UFormField>
                            <UFormField label="Check-out by" :error="form.errors.overnight_checkout_time"><UInput v-model="form.overnight_checkout_time" type="time" class="w-full" /></UFormField>
                        </div>
                    </template>
                    <template v-else-if="editRow === 'long_stay'">
                        <USwitch v-model="form.long_stay_enabled" label="Long stay per day" description="Priced from the 24-hour rate." />
                        <UFormField v-if="form.long_stay_enabled" label="Longest stay (days)" :error="form.errors.long_stay_max_days"><UInput v-model.number="form.long_stay_max_days" type="number" :min="1" :max="31" class="w-28" /></UFormField>
                    </template>
                    <template v-else-if="editRow === 'floor_room'">
                        <USwitch v-model="form.floor_room_rates_enabled" label="Floor &amp; room rates" description="Lets the rate table set a flat rate for a whole floor, or a rate for one room, on top of the room-type rates." />
                    </template>
                    <template v-else-if="editRow === 'extension'">
                        <UFormField label="Extension pricing" :error="form.errors.extension_rule">
                            <USelect v-model="form.extension_rule" :items="[{ label: '24-hour cycle', value: 'cycle' }, { label: 'Per hour', value: 'per_hour' }, { label: 'Per block', value: 'block' }]" class="w-full" />
                        </UFormField>
                        <UFormField v-if="form.extension_rule === 'cycle'" label="Cycle (hours)" :error="form.errors.extension_cycle_hours"><UInput v-model.number="form.extension_cycle_hours" type="number" :min="1" :max="24" class="w-28" /></UFormField>
                        <UFormField v-else-if="form.extension_rule === 'per_hour'" label="Amount per hour" :error="form.errors.extension_per_hour_amount"><MoneyInput v-model="form.extension_per_hour_amount" /></UFormField>
                    </template>
                    <template v-else-if="editRow === 'due_soon'">
                        <UFormField label="Due-soon warning (minutes before check-out)" :error="form.errors.due_soon_minutes"><UInput v-model.number="form.due_soon_minutes" type="number" :min="1" class="w-28" /></UFormField>
                    </template>

                    <template v-else-if="editRow === 'deposit'">
                        <USwitch v-model="form.deposit_enabled" label="Deposit at check-in" />
                        <UFormField v-if="form.deposit_enabled" label="Deposit amount" :error="form.errors.deposit_amount"><MoneyInput v-model="form.deposit_amount" /></UFormField>
                    </template>
                    <template v-else-if="editRow === 'deposit_name'">
                        <UFormField label="Deposit name" :error="form.errors.deposit_name" help="Shown on the slip and the folio."><UInput v-model="form.deposit_name" maxlength="40" class="w-full" /></UFormField>
                    </template>
                    <template v-else-if="editRow === 'lost'">
                        <div class="grid grid-cols-2 gap-3">
                            <UFormField label="Lost key" :error="form.errors.lost_key_amount"><MoneyInput v-model="form.lost_key_amount" /></UFormField>
                            <UFormField label="Lost remote" :error="form.errors.lost_remote_amount"><MoneyInput v-model="form.lost_remote_amount" /></UFormField>
                        </div>
                    </template>
                    <template v-else-if="editRow === 'payment_methods'">
                        <UFormField label="Payment methods accepted" :error="form.errors.payment_methods" help="At least one.">
                            <div class="flex flex-wrap gap-4">
                                <UCheckbox v-for="m in methods" :key="m.value" :label="m.label" :model-value="form.payment_methods.includes(m.value)" @update:model-value="(v) => toggleMethod(m.value, v === true)" />
                            </div>
                        </UFormField>
                    </template>
                    <template v-else-if="editRow === 'cash_count'">
                        <USwitch v-model="form.denomination_count_enabled" label="Count cash by denomination at close" description="Off: the front desk types one counted total — faster. On: they type the pieces of each bill and the coins, and the system adds them up." />
                    </template>

                    <template v-else-if="editRow === 'kiosk'">
                        <USwitch v-model="form.kiosk_enabled" label="Kiosk" description="The lobby self-service screen where guests hold a room." />
                        <UFormField v-if="form.kiosk_enabled" label="Hold a kiosk room for (minutes)" :error="form.errors.kiosk_hold_minutes"><UInput v-model.number="form.kiosk_hold_minutes" type="number" :min="1" class="w-28" /></UFormField>
                        <USwitch
                            v-if="form.kiosk_enabled"
                            v-model="form.kiosk_checkout_enabled"
                            label="Guests can request checkout at the kiosk"
                            description="Guests enter their room and stay code, review the bill, and confirm — the front desk still takes payment, returns the deposit and does the real checkout."
                        />
                    </template>
                    <template v-else-if="editRow === 'reservations'">
                        <USwitch v-model="form.reservations_enabled" label="Reservations" />
                        <UFormField v-if="form.reservations_enabled" label="Release a no-show after (minutes)" :error="form.errors.reservation_release_minutes"><UInput v-model.number="form.reservation_release_minutes" type="number" :min="1" class="w-28" /></UFormField>
                    </template>
                    <template v-else-if="editRow === 'event_halls'">
                        <USwitch v-model="form.event_hall_enabled" label="Event halls" description="Book function halls for events, with deposits and packages. Manage halls in Setup → Event halls." />
                    </template>
                    <template v-else-if="editRow === 'pos'">
                        <USwitch v-model="form.pos_enabled" label="POS and stock" />
                    </template>
                    <template v-else-if="editRow === 'housekeeping'">
                        <USwitch v-model="form.housekeeping_enabled" label="Housekeeping" description="Off: a room goes straight to Available at check-out." />
                        <div v-if="form.housekeeping_enabled" class="grid grid-cols-3 gap-3">
                            <UFormField label="Clean within (min)" :error="form.errors.cleaning_deadline_minutes"><UInput v-model.number="form.cleaning_deadline_minutes" type="number" :min="1" class="w-full" /></UFormField>
                            <UFormField label="Target (min)" :error="form.errors.cleaning_target_minutes"><UInput v-model.number="form.cleaning_target_minutes" type="number" :min="1" class="w-full" /></UFormField>
                            <UFormField label="Urgent after (min)" :error="form.errors.cleaning_urgent_minutes"><UInput v-model.number="form.cleaning_urgent_minutes" type="number" :min="1" class="w-full" /></UFormField>
                        </div>
                    </template>

                    <template v-else-if="editRow === 'vat'">
                        <USwitch v-model="form.vat_enabled" label="VAT-registered" description="Adds the VAT block to the Z-report; prices are VAT-inclusive." />
                        <UFormField v-if="form.vat_enabled" label="VAT rate (%)" :error="form.errors.vat_rate_percent"><UInput v-model.number="form.vat_rate_percent" type="number" :min="1" :max="100" class="w-28" /></UFormField>
                    </template>
                    <template v-else-if="editRow === 'tin'">
                        <UFormField label="TIN" :error="form.errors.tin"><UInput v-model="form.tin" maxlength="20" class="w-full" placeholder="000-000-000-000" /></UFormField>
                    </template>
                    <template v-else-if="editRow === 'registered_name'">
                        <UFormField label="Registered name" :error="form.errors.registered_name"><UInput v-model="form.registered_name" maxlength="120" class="w-full" /></UFormField>
                    </template>

                    <template v-else-if="editRow === 'frontdesks'">
                        <UFormField label="Front desks per shift" :error="form.errors.max_frontdesks_per_shift"><UInput v-model.number="form.max_frontdesks_per_shift" type="number" :min="1" :max="10" class="w-28" /></UFormField>
                    </template>
                    <template v-else-if="editRow === 'overdue'">
                        <UFormField label="Warn when a shift is past its end by (minutes)" :error="form.errors.shift_overdue_warn_minutes"><UInput v-model.number="form.shift_overdue_warn_minutes" type="number" :min="1" class="w-28" /></UFormField>
                    </template>
                    <template v-else-if="editRow === 'stale'">
                        <UFormField label="Stop payments on a shift past its end by (minutes)" :error="form.errors.shift_stale_block_minutes" help="The stale shift must then be closed before more money is taken (54)."><UInput v-model.number="form.shift_stale_block_minutes" type="number" :min="1" class="w-28" /></UFormField>
                    </template>
                </div>
            </template>
            <template #footer>
                <div class="flex w-full justify-end gap-2">
                    <UButton color="neutral" variant="ghost" label="Cancel" @click="editRow = null" />
                    <UButton label="Save" :loading="form.processing" @click="saveRow" />
                </div>
            </template>
        </UModal>

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
                        <USwitch v-model="form.denomination_count_enabled" label="Count cash by denomination at close" description="Off: the front desk types one counted total — faster. On: they type the pieces of each bill and the coins, and the system adds them up." />
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
                        <USwitch v-model="form.event_hall_enabled" label="Event halls" description="Book function halls for events, with deposits and packages. Manage halls in Setup → Event halls." />
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
