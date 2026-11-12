<!-- Mockup 2 -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ApprovalPin, { type Approver } from '@/Components/ApprovalPin.vue';
import MoneyInput from '@/Components/MoneyInput.vue';
import MoneyRow from '@/Components/MoneyRow.vue';
import OptionTiles from '@/Components/OptionTiles.vue';
import { formatDateTime } from '@/Composables/useDateTime';
import { formatMoney } from '@/Composables/useMoney';
import { useShared } from '@/Composables/useShared';
import FrontDeskLayout from '@/Layouts/FrontDeskLayout.vue';

/**
 * Open shift (2): the period and business date proposed from the last closed shift, the cash
 * that shift counted, and the drawer count. A different count needs a note; a different period
 * needs a supervisor PIN once a shift has been closed (APP_FLOW §4.2).
 */
const props = defineProps<{
    proposal: { definition_id: number; business_date: string; last_period_name: string | null; last_business_date: string | null; last_closed_at: string | null };
    definitions: { id: number; name: string; start: string; end: string }[];
    can_change_freely: boolean;
    last_counted: number | null;
    closed_by: string | null;
    approvers: Approver[];
    user: string;
}>();

const { props: shared, flash } = useShared();
const form = useForm({
    opening_cash: props.last_counted ?? 0,
    note: '',
    definition_id: props.proposal.definition_id,
    business_date: props.proposal.business_date,
    approver_id: null as number | null,
    pin: '',
});
const approving = ref(false);
const attempt = ref(0);

const tiles = computed(() => props.definitions.map((d) => ({ value: d.id, label: d.name, sub: `${formatDateTime(d.start, 'time')} – ${formatDateTime(d.end, 'time')}` })));
const picked = computed(() => props.definitions.find((d) => d.id === form.definition_id));
const changed = computed(() => form.definition_id !== props.proposal.definition_id);
const needsApproval = computed(() => changed.value && !props.can_change_freely);
const differs = computed(() => props.last_counted !== null && form.opening_cash !== props.last_counted);
const canSave = computed(() => !form.processing && (!differs.value || form.note.trim() !== ''));

const why = computed(() => {
    const now = formatDateTime(new Date(), 'time');

    if (!props.proposal.last_period_name || !props.proposal.last_closed_at) {
        return `It is ${now}. No shift has been closed at this branch yet, so the app picked the period running now.`;
    }

    return `It is ${now}. The app picked ${picked.value?.name ?? ''} because the ${props.proposal.last_period_name} shift of ${formatDateTime(`${props.proposal.last_business_date}T12:00:00+08:00`, 'date').replace(/ \d{4}$/, '')} was closed at ${formatDateTime(props.proposal.last_closed_at, 'time')}.`;
});

const error = computed(() => (flash.value.error && !['pin_invalid', 'pin_locked'].includes(flash.value.error.code) ? flash.value.error.message : undefined));
const pinError = computed(() => (flash.value.error && ['pin_invalid', 'approval_refused', 'pin_locked'].includes(flash.value.error.code) ? flash.value.error.message : undefined));

watch(
    () => flash.value.error,
    (e) => {
        if (e && approving.value) {
            attempt.value++;
        }
    },
);

function save(): void {
    if (needsApproval.value) {
        approving.value = true;

        return;
    }

    submit();
}

function submit(approval?: { approver_id: number; pin: string }): void {
    form.approver_id = approval?.approver_id ?? null;
    form.pin = approval?.pin ?? '';
    form.transform((data) => ({ ...data, definition_id: changed.value ? data.definition_id : null, approver_id: data.approver_id || null, pin: data.pin || null })).post(route('shift.store'), {
        preserveScroll: true,
        onSuccess: (page) => {
            if (!(page.flash as { error?: unknown } | undefined)?.error) {
                approving.value = false;
            }
        },
    });
}
</script>

<template>
    <FrontDeskLayout :title="`Open the ${picked?.name ?? ''} shift`">
        <UAlert v-if="error" color="error" variant="subtle" icon="i-lucide-triangle-alert" class="mb-4" :description="error" />
        <div class="grid grid-cols-2 gap-4 max-[900px]:grid-cols-1">
            <section class="rounded-card border border-default bg-default p-5">
                <h2 class="mb-3 text-base font-semibold text-highlighted">Shift</h2>
                <OptionTiles v-model="form.definition_id" :options="tiles" label="Shift period" />
                <div class="mt-4 flex flex-col gap-1.5">
                    <MoneyRow label="Business date" :text="formatDateTime(`${form.business_date}T12:00:00+08:00`, 'businessDate')" />
                    <MoneyRow label="Branch" :text="shared.branch?.name ?? ''" />
                    <MoneyRow label="Opened by" :text="user" />
                </div>
                <UAlert class="mt-4" color="info" variant="subtle" icon="i-lucide-clock" :description="why" />
                <UAlert
                    v-if="needsApproval"
                    class="mt-3"
                    color="warning"
                    variant="subtle"
                    icon="i-lucide-lock"
                    description="This is not the proposed period. A supervisor approves the change with their PIN."
                />
            </section>
            <section class="rounded-card border border-default bg-default p-5">
                <h2 class="mb-3 text-base font-semibold text-highlighted">Opening cash</h2>
                <div class="flex flex-col gap-1.5">
                    <MoneyRow :label="`Counted by ${proposal.last_period_name ?? 'the last'} shift at close`" :amount="last_counted" :text="last_counted === null ? 'No earlier count' : undefined" />
                    <MoneyRow v-if="closed_by" label="Handed over by" :text="closed_by" />
                </div>
                <UFormField label="I counted the drawer and it has" name="opening_cash" class="mt-4" :error="form.errors.opening_cash">
                    <MoneyInput v-model="form.opening_cash" autofocus />
                </UFormField>
                <UAlert
                    v-if="last_counted !== null && !differs"
                    class="mt-3"
                    color="success"
                    variant="subtle"
                    icon="i-lucide-check"
                    description="Matches the handover amount."
                />
                <template v-if="differs">
                    <UAlert
                        class="mt-3"
                        color="warning"
                        variant="subtle"
                        icon="i-lucide-triangle-alert"
                        :description="`This is ${formatMoney(Math.abs(form.opening_cash - (last_counted ?? 0)))} ${form.opening_cash > (last_counted ?? 0) ? 'more' : 'less'} than the last count. Write what you found; it is recorded with the handover.`"
                    />
                    <UFormField label="Note (required)" name="note" class="mt-3" :error="form.errors.note">
                        <UTextarea v-model="form.note" :rows="2" class="w-full" />
                    </UFormField>
                </template>
                <UButton class="mt-5" size="xl" block :icon="needsApproval ? 'i-lucide-lock' : undefined" label="Open shift" :disabled="!canSave" :loading="form.processing" @click="save" />
            </section>
        </div>
        <ApprovalPin
            v-model:open="approving"
            :summary="`Open ${picked?.name ?? ''} instead of the proposed period`"
            :approvers="approvers"
            :error="pinError"
            :locked="shared.pinLocked"
            :processing="form.processing"
            :attempt="attempt"
            @approve="submit"
        />
    </FrontDeskLayout>
</template>
