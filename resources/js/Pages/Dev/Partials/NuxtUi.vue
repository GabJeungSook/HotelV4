<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui';
import { computed, ref, shallowRef } from 'vue';
import MoneyRow from '@/Components/MoneyRow.vue';
import TotalRow from '@/Components/TotalRow.vue';
import { formatDateRange } from '@/Composables/useDateTime';
import Block from './Block.vue';

const toast = useToast();

const modalOpen = ref(false);
const sheetOpen = ref(false);
const saving = ref(false);
const reason = ref<number | undefined>(undefined);
const reasons = [
    { label: 'Guest changed their mind', value: 1 },
    { label: 'Wrong room', value: 2 },
    { label: 'Other', value: 3 },
];
const note = ref('');
const deposit = ref(true);
const vat = ref(false);
const keyReturned = ref(true);
const remoteReturned = ref(false);
const showPassword = ref(false);
const password = ref('secret-pass');

// Starts empty: building a date value needs @internationalized/date, which we do not import directly.
const range = shallowRef<{ start?: { toString(): string }; end?: { toString(): string } } | undefined>(undefined);
const rangeLabel = computed(() =>
    range.value?.start && range.value?.end ? formatDateRange(range.value.start.toString() + 'T00:00:00+08:00', range.value.end.toString() + 'T00:00:00+08:00') : '1 – 10 Nov 2026',
);

const exportItems: DropdownMenuItem[][] = [
    [
        { label: 'Print', icon: 'i-solar-printer-2-bold-duotone', onSelect: () => toast.add({ title: 'Print recorded', color: 'neutral' }) },
        { label: 'PDF', icon: 'i-solar-document-text-bold-duotone', onSelect: () => toast.add({ title: 'PDF is being made', color: 'info' }) },
        { label: 'CSV', icon: 'i-solar-document-bold-duotone', onSelect: () => toast.add({ title: 'CSV downloaded', color: 'success', icon: 'i-solar-check-circle-linear' }) },
    ],
];
const rowItems: DropdownMenuItem[][] = [[{ label: 'Extend', icon: 'i-solar-clock-circle-bold-duotone' }, { label: 'Transfer', icon: 'i-solar-transfer-horizontal-linear' }], [{ label: 'Void line', icon: 'i-solar-close-circle-linear', color: 'error' }]];

function save(): void {
    saving.value = true;
    setTimeout(() => {
        saving.value = false;
        modalOpen.value = false;
        toast.add({ title: 'Payment saved. Change ₱85.00', color: 'success', icon: 'i-solar-check-circle-linear' });
    }, 1200);
}
</script>

<template>
    <div class="grid grid-cols-[repeat(auto-fill,minmax(min(100%,330px),1fr))] gap-4">
        <Block title="UModal · USlideover">
            <div class="flex flex-wrap gap-2">
                <UButton color="neutral" variant="outline" label="Take payment (modal)" @click="modalOpen = true" />
                <UButton color="neutral" variant="outline" label="Add charge (sheet)" @click="sheetOpen = true" />
            </div>
            <UModal v-model:open="modalOpen" title="Take payment" description="Room 205 · R. Manalastas" :dismissible="!saving">
                <template #body>
                    <MoneyRow label="Balance due" :amount="11500" tone="bad" />
                    <UFormField class="mt-3" label="Reason (optional)" name="m-reason">
                        <USelect v-model="reason" :items="reasons" value-key="value" placeholder="Choose a reason" class="w-full" />
                    </UFormField>
                </template>
                <template #footer>
                    <UButton color="neutral" variant="ghost" label="Not yet" :disabled="saving" @click="modalOpen = false" />
                    <UButton :loading="saving" :label="saving ? 'Saving…' : 'Save payment'" @click="save" />
                </template>
            </UModal>
            <USlideover v-model:open="sheetOpen" title="Add charge" side="right" :overlay="false">
                <template #body>
                    <UFormField label="Note" name="s-note">
                        <UTextarea v-model="note" :rows="3" class="w-full" placeholder="Extra towel" />
                    </UFormField>
                </template>
                <template #footer>
                    <div class="flex w-full flex-col gap-3">
                        <TotalRow label="Total" :amount="5000" />
                        <div class="flex justify-end gap-2">
                            <UButton color="neutral" variant="outline" label="Add and take payment now" />
                            <UButton label="Add to folio" @click="sheetOpen = false" />
                        </div>
                    </div>
                </template>
            </USlideover>
        </Block>

        <Block title="UDropdownMenu — ExportMenu, row menu">
            <div class="flex flex-wrap items-center gap-2">
                <UDropdownMenu :items="exportItems">
                    <UButton color="neutral" variant="outline" label="Export" trailing-icon="i-solar-alt-arrow-down-linear" />
                </UDropdownMenu>
                <UDropdownMenu :items="rowItems">
                    <UButton color="neutral" variant="ghost" size="sm" label="⋯" aria-label="Row actions" />
                </UDropdownMenu>
                <UTooltip text="Close the shift first">
                    <span><UButton color="neutral" variant="outline" label="Export" trailing-icon="i-solar-alt-arrow-down-linear" disabled /></span>
                </UTooltip>
            </div>
        </Block>

        <Block title="USelect · UTextarea · password">
            <div class="flex flex-col gap-3.5">
                <UFormField label="Reason" name="n-reason">
                    <USelect v-model="reason" :items="reasons" value-key="value" placeholder="Choose a reason" class="w-full" />
                </UFormField>
                <UFormField label="Reason" name="n-reason-err" error="Choose a reason.">
                    <USelect :items="reasons" value-key="value" placeholder="Choose a reason" class="w-full" />
                </UFormField>
                <UFormField label="Password" name="n-password">
                    <UInput v-model="password" :type="showPassword ? 'text' : 'password'" class="w-full" :ui="{ trailing: 'pe-1' }">
                        <template #trailing>
                            <UButton color="primary" variant="link" size="sm" :label="showPassword ? 'Hide' : 'Show'" @click="showPassword = !showPassword" />
                        </template>
                    </UInput>
                </UFormField>
                <UFormField label="Note (optional)" name="n-note" help="Enter adds a line.">
                    <UTextarea v-model="note" :rows="3" class="w-full" />
                </UFormField>
            </div>
        </Block>

        <Block title="USwitch · UCheckbox · USeparator">
            <div class="flex flex-col gap-3">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-sm font-medium text-highlighted">Key deposit</p>
                        <p class="text-xs text-muted">Added at check-in and returned at check-out.</p>
                    </div>
                    <USwitch v-model="deposit" aria-label="Key deposit" />
                </div>
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-sm font-medium text-highlighted">VAT registered</p>
                        <p class="text-xs text-muted">Shows VAT lines on the Z-report.</p>
                    </div>
                    <USwitch v-model="vat" aria-label="VAT registered" />
                </div>
                <USeparator />
                <UCheckbox v-model="keyReturned" label="Room key returned" />
                <UCheckbox v-model="remoteReturned" label="TV remote returned" />
                <UCheckbox :model-value="false" label="Disabled" disabled />
            </div>
        </Block>

        <Block title="UAvatar · UBadge · UTooltip">
            <div class="flex flex-wrap items-center gap-3">
                <UAvatar alt="Jessa Mae Dela Cruz" size="sm" class="size-7" />
                <UAvatar alt="Rhea Bautista" size="sm" class="size-7" />
                <span class="inline-flex items-center gap-2 text-sm">Attention <UBadge label="3" color="neutral" variant="subtle" /></span>
                <UBadge label="+ 20 more" color="neutral" variant="subtle" />
                <UTooltip text="Print again">
                    <UButton color="neutral" variant="outline" icon="i-solar-printer-2-bold-duotone" aria-label="Print again" />
                </UTooltip>
            </div>
            <div class="mt-3 flex flex-wrap gap-2">
                <UButton color="neutral" variant="outline" label="Success toast" @click="toast.add({ title: 'Check-in saved. Slip printed.', color: 'success', icon: 'i-solar-check-circle-linear' })" />
                <UButton color="neutral" variant="outline" label="Error toast" @click="toast.add({ title: 'Nothing was saved. Try again.', color: 'error', icon: 'i-solar-danger-triangle-bold-duotone', duration: 8000 })" />
                <UButton color="neutral" variant="outline" label="Info toast" @click="toast.add({ title: 'Room 117 is now To clean.', color: 'neutral' })" />
            </div>
        </Block>

        <Block title="UCalendar in UPopover — date range">
            <UPopover>
                <UButton color="neutral" variant="outline" leading-icon="i-solar-alt-arrow-left-linear" trailing-icon="i-solar-alt-arrow-right-linear" :label="rangeLabel" />
                <template #content>
                    <UCalendar v-model="range" range :week-starts-on="1" class="p-2" />
                </template>
            </UPopover>
            <p class="mt-2 text-xs text-muted">Week starts Monday; the button shows the formatted range.</p>
            <div class="mt-3 flex flex-col gap-2">
                <USkeleton class="h-8 w-40" />
                <USkeleton class="h-4 w-full" />
            </div>
        </Block>
    </div>
</template>
