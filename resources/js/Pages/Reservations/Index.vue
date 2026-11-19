<!-- Mockup 57 · detail sheet (APP_FLOW §8.2, pattern of 5) · 14 for cancel and no-show -->
<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ApprovalPin from '@/Components/ApprovalPin.vue';
import type { ConfirmReason } from '@/Components/ConfirmDialog.vue';
import MoneyRow from '@/Components/MoneyRow.vue';
import OptionTiles from '@/Components/OptionTiles.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import PersonPill from '@/Components/PersonPill.vue';
import { useConfirmDialog } from '@/Composables/useConfirmDialog';
import { formatDateTime } from '@/Composables/useDateTime';
import { formatMoney } from '@/Composables/useMoney';
import { shortName, useShared } from '@/Composables/useShared';
import FrontDeskLayout from '@/Layouts/FrontDeskLayout.vue';
import type { Tone } from '@/types/ui';

/**
 * Reservations (57): the front desk's bookings by arrival day, in three tabs, with a five-day
 * strip and the rooms free on the picked day. "Check in" opens the check-in (6) filled in from
 * the reservation; "Open" shows the detail sheet, where a reservation is cancelled or a
 * no-show's money paid ahead is handed back or kept, each with a reason and a supervisor PIN (14).
 * The amounts are per reservation (what was paid ahead); there is no shift total on this page.
 */
interface Row {
    id: number;
    arrival_at: string | null;
    arrival_today: boolean;
    guest: string;
    room: string;
    stay: string;
    paid_ahead: number | null;
    status: string;
    tone: Tone;
    state: string;
    can_check_in: boolean;
    now: string;
}
interface Detail extends Row {
    code: string;
    mobile: string | null;
    checkout_at: string | null;
    booked_amount: number;
    held: number;
    paid_method: string | null;
    paid_reference: string | null;
    closed_reason: string | null;
    can_cancel: boolean;
    can_settle: boolean;
    stay_id: number | null;
    reasons: ConfirmReason[];
    approvers: { id: number; name: string; role: string }[];
}

const props = defineProps<{
    tab: 'today' | 'upcoming' | 'past';
    date: string | null;
    rows: Row[];
    counts: { today: number; upcoming: number };
    days: { value: string; label: string }[];
    free: { label: string; types: { name: string; free: number; of: number }[] };
    detail: Detail | null;
}>();

const { props: shared, flash } = useShared();
const confirm = useConfirmDialog();

const tabs = computed(() => [
    { value: 'today', label: 'Today', n: props.counts.today },
    { value: 'upcoming', label: 'Upcoming', n: props.counts.upcoming },
    { value: 'past', label: 'Past', n: null },
]);

function go(tab: string, date: string | null = null): void {
    router.get(route('reservations.index'), { tab, ...(date ? { date } : {}) }, { preserveScroll: true });
}

function pickDay(value: string): void {
    const today = props.days[0]?.value;
    go(value === today ? 'today' : 'upcoming', value === today ? null : value === props.date ? null : value);
}

function arrival(r: Row): string {
    return r.arrival_today ? `Today ${formatDateTime(r.arrival_at, 'time')}` : formatDateTime(r.arrival_at, 'dayTime');
}

function checkIn(r: { id: number }): void {
    router.visit(route('stays.create', { reservation: r.id }));
}

function openRow(r: Row): void {
    router.get(route('reservations.show', r.id), { tab: props.tab, ...(props.date ? { date: props.date } : {}) }, { preserveScroll: true, preserveState: true });
}

const firstArriving = computed(() => props.rows.find((r) => r.can_check_in)?.id ?? null);
const freeLine = computed(() => props.free.types.map((t) => `${t.name} ${t.free} of ${t.of}`).join(' · '));

// Detail sheet.
const sheet = ref(props.detail !== null);
watch(
    () => props.detail,
    (d) => (sheet.value = d !== null),
);
watch(sheet, (open) => {
    if (!open && props.detail) {
        router.get(route('reservations.index'), { tab: props.tab, ...(props.date ? { date: props.date } : {}) }, { preserveScroll: true, preserveState: true, replace: true });
    }
});

const refund = ref<'refund' | 'keep'>('refund');
const moneyTiles = computed(() => [
    { value: 'refund', label: 'Hand it back', sub: props.detail ? `${formatMoney(props.detail.held, 'tile')} cash from the drawer` : undefined },
    { value: 'keep', label: 'Keep it', sub: 'counted as income' },
]);

const approval = useForm({ reason_id: null as number | null, note: '' as string | null, refund: true, approver_id: null as number | null, pin: '' });
const approving = ref(false);
const approvalSummary = ref('');
const approvalUrl = ref('');
const approvalError = ref<string | undefined>(undefined);
const attempt = ref(0);

async function settle(kind: 'cancel' | 'refund'): Promise<void> {
    const d = props.detail;

    if (!d) {
        return;
    }

    const money = d.held > 0 ? (refund.value === 'refund' ? `**${formatMoney(d.held)} cash must be handed back** from the drawer.` : `The ${formatMoney(d.held)} paid ahead is **kept** as income.`) : 'Nothing was paid ahead.';
    const result = await confirm({
        title: kind === 'cancel' ? `Cancel the reservation of ${d.guest}?` : `Settle the no-show of ${d.guest}?`,
        description: kind === 'cancel' ? `${d.code} is cancelled and the room is free for those dates. ${money}` : money,
        confirmLabel: kind === 'cancel' ? 'Cancel reservation' : refund.value === 'refund' ? 'Hand it back' : 'Keep it',
        cancelLabel: kind === 'cancel' ? 'Keep reservation' : 'Not now',
        confirmColor: 'error',
        reasons: d.reasons,
        needsApproval: true,
        loggedAs: shared.value.auth ? shortName(shared.value.auth.user.name) : undefined,
    });

    if (!result) {
        return;
    }

    approval.reason_id = result.reasonId;
    approval.note = result.note;
    approval.refund = refund.value === 'refund';
    approvalUrl.value = route(kind === 'cancel' ? 'reservations.cancel' : 'reservations.refund', d.id);
    approvalSummary.value = `${kind === 'cancel' ? 'Cancel' : 'No-show'} ${d.code}${d.held > 0 ? ` · ${refund.value === 'refund' ? 'refund' : 'keep'} ${formatMoney(d.held)}` : ''}`;
    approvalError.value = undefined;
    approving.value = true;
}

function approve(payload: { approver_id: number; pin: string }): void {
    approval.approver_id = payload.approver_id;
    approval.pin = payload.pin;
    approval.post(approvalUrl.value, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (page) => {
            const e = (page.flash as { error?: { code: string; message: string } } | undefined)?.error;

            if (e) {
                approvalError.value = e.code === 'pin_invalid' ? 'PIN not recognised' : e.message;
                attempt.value++;

                return;
            }

            approving.value = false;
        },
    });
}
</script>

<template>
    <FrontDeskLayout current="reservations" title="Reservations">
        <PageHeader>
            <UButton icon="i-solar-add-square-linear" label="New reservation" @click="router.visit(route('reservations.create'))" />
        </PageHeader>
        <UAlert v-if="flash.error && !approving" color="error" variant="subtle" icon="i-solar-danger-triangle-bold-duotone" class="mb-3" :description="flash.error.message" />

        <div class="mb-3 flex flex-wrap items-center gap-3">
            <div role="tablist" aria-label="Reservations" class="flex gap-1 rounded-md bg-grey-50 p-1">
                <button
                    v-for="t in tabs"
                    :key="t.value"
                    type="button"
                    role="tab"
                    :aria-selected="tab === t.value"
                    class="hm-focus flex cursor-pointer items-center gap-1.5 rounded px-3 py-1.5 text-sm"
                    :class="tab === t.value ? 'bg-default font-medium text-highlighted shadow-xs' : 'text-muted'"
                    @click="go(t.value)"
                >
                    {{ t.label }}<span v-if="t.n !== null" class="text-xs text-muted tabular">{{ t.n }}</span>
                </button>
            </div>
            <span class="flex-1" />
            <div class="flex gap-1 rounded-md bg-grey-50 p-1" aria-label="Arrival day">
                <button
                    v-for="(d, i) in days"
                    :key="d.value"
                    type="button"
                    class="hm-focus cursor-pointer rounded px-3 py-1.5 text-sm"
                    :class="date === d.value || (i === 0 && tab === 'today') ? 'bg-default font-medium text-highlighted shadow-xs' : 'text-muted'"
                    :aria-pressed="date === d.value || (i === 0 && tab === 'today')"
                    @click="pickDay(d.value)"
                >
                    {{ d.label }}
                </button>
            </div>
        </div>

        <div class="overflow-x-auto rounded-card border border-default bg-default p-4">
            <table class="dtable w-full">
                <thead>
                    <tr>
                        <th>Arrival</th>
                        <th>Guest</th>
                        <th>Room</th>
                        <th>Stay</th>
                        <th class="text-right">Paid ahead</th>
                        <th>Status</th>
                        <th />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in rows" :key="r.id" :data-reservation="r.id">
                        <td class="whitespace-nowrap">{{ arrival(r) }}</td>
                        <td><PersonPill :name="r.guest" /></td>
                        <td class="whitespace-nowrap">{{ r.room }}</td>
                        <td class="whitespace-nowrap">{{ r.stay }}</td>
                        <td class="text-right num">{{ r.paid_ahead ? formatMoney(r.paid_ahead, 'column') : '—' }}</td>
                        <td><StatusLabel :tone="r.tone" :label="r.status" /></td>
                        <td class="text-right whitespace-nowrap">
                            <UButton
                                v-if="r.can_check_in && r.state === 'reserved'"
                                size="sm"
                                :color="r.id === firstArriving ? 'primary' : 'neutral'"
                                :variant="r.id === firstArriving ? 'solid' : 'outline'"
                                label="Check in"
                                @click="checkIn(r)"
                            />
                            <UButton v-else size="sm" color="neutral" variant="ghost" label="Open" @click="openRow(r)" />
                        </td>
                    </tr>
                    <tr v-if="rows.length === 0">
                        <td colspan="7" class="py-10 text-center text-sm text-muted">
                            {{ tab === 'past' ? 'No past reservations.' : tab === 'upcoming' ? 'No reservations coming up.' : 'No reservations arriving today.' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-muted" data-test="free-rooms">Rooms free on {{ free.label }}: {{ freeLine }}.</p>

        <USlideover v-model:open="sheet" :title="detail ? detail.guest : 'Reservation'" :overlay="false" :ui="{ content: 'max-w-[420px]' }">
            <template #body>
                <template v-if="detail">
                    <div class="mb-3 flex items-center gap-2">
                        <StatusLabel :tone="detail.tone" :label="detail.status" />
                        <span class="font-mono text-xs text-muted">{{ detail.code }}</span>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <MoneyRow label="Arrival" :text="formatDateTime(detail.arrival_at, 'dayTime')" />
                        <MoneyRow label="Check-out" :text="formatDateTime(detail.checkout_at, 'dayTime')" />
                        <MoneyRow label="Room" :text="detail.room" />
                        <MoneyRow label="Stay" :text="detail.stay" />
                        <MoneyRow v-if="detail.mobile" label="Mobile" :text="detail.mobile" />
                        <MoneyRow label="Booked price" :amount="detail.booked_amount" />
                        <MoneyRow v-if="detail.paid_ahead" label="Paid ahead" :amount="detail.paid_ahead" />
                        <MoneyRow v-if="detail.paid_method" label="Paid by" :text="detail.paid_reference ? `${detail.paid_method} · ${detail.paid_reference}` : detail.paid_method" />
                        <MoneyRow v-if="detail.paid_ahead" label="Still held" :amount="detail.held" />
                    </div>
                    <p v-if="detail.closed_reason" class="mt-3 text-xs text-muted">{{ detail.closed_reason }}</p>

                    <OptionTiles v-if="(detail.can_cancel || detail.can_settle) && detail.held > 0" v-model="refund" class="mt-4" :options="moneyTiles" label="Money paid ahead" />

                    <div class="mt-5 flex flex-wrap gap-2">
                        <UButton v-if="detail.can_check_in" label="Check in" @click="checkIn(detail)" />
                        <UButton v-if="detail.stay_id" color="neutral" variant="outline" label="Open guest folio" @click="router.visit(route('stays.show', detail.stay_id))" />
                        <UButton v-if="detail.can_cancel" color="error" variant="outline" label="Cancel reservation" @click="settle('cancel')" />
                        <UButton v-if="detail.can_settle" color="neutral" variant="outline" label="Settle money paid ahead" @click="settle('refund')" />
                    </div>
                </template>
            </template>
        </USlideover>

        <ApprovalPin
            v-model:open="approving"
            :summary="approvalSummary"
            :approvers="detail?.approvers ?? []"
            :error="approvalError"
            :locked="shared.pinLocked"
            :processing="approval.processing"
            :attempt="attempt"
            @approve="approve"
        />
    </FrontDeskLayout>
</template>
