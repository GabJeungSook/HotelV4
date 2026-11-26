<!-- Kiosk checkout (reference: HotelV2). Guest enters room + stay code, reviews the bill, and
     requests checkout. This only flags the stay — the front desk takes payment, returns the
     deposit and does the real checkout. -->
<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import HomiLogo from '@/Components/HomiLogo.vue';
import KioskFooter from '@/Components/KioskFooter.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import { formatDateTime } from '@/Composables/useDateTime';
import { useKioskIdle } from '@/Composables/useKioskIdle';
import { formatMoney } from '@/Composables/useMoney';
import { useShared } from '@/Composables/useShared';
import KioskLayout from '@/Layouts/KioskLayout.vue';

interface Bill {
    code: string;
    room: string | null;
    guest_name: string;
    checked_in_at: string | null;
    expected_checkout_at: string | null;
    lines: { label: string; amount: number; status: string }[];
    balance: number;
    deposit: number;
    already_requested: boolean;
}

const props = defineProps<{ bill: Bill | null; room_number: string; code: string }>();

const { flash } = useShared();
useKioskIdle(60, () => router.visit(route('kiosk.index')));

const lookup = useForm({ room_number: props.room_number, code: props.code });
const requesting = ref(false);

const error = computed(() => (flash.value.error && flash.value.error.code !== 'room_taken' ? flash.value.error.message : undefined));
const findReady = computed(() => lookup.room_number.trim() !== '' && lookup.code.trim() !== '');

function find(): void {
    if (findReady.value) {
        lookup.transform((d) => ({ ...d, room_number: d.room_number.trim(), code: d.code.trim() })).post(route('kiosk.checkout.lookup'), { preserveState: true });
    }
}

// Read the room and code from the current props (not a form snapshot taken before the bill loaded).
function request(): void {
    router.post(
        route('kiosk.checkout.confirm'),
        { room_number: props.room_number, code: props.code },
        { onStart: () => (requesting.value = true), onFinish: () => (requesting.value = false) },
    );
}

function home(): void {
    router.visit(route('kiosk.index'));
}
</script>

<template>
    <KioskLayout title="Check out">
        <div class="mb-6 flex items-center justify-between gap-4">
            <HomiLogo variant="blue" :width="124" :height="38" />
            <span class="text-xl font-medium text-muted">Check out</span>
        </div>

        <UAlert v-if="error" class="mb-6 text-xl" color="error" variant="subtle" icon="i-solar-danger-triangle-bold-duotone" :description="error" data-test="checkout-error" />

        <!-- Step 1: find the stay -->
        <template v-if="!bill">
            <h1 class="text-[40px] leading-[48px] font-semibold text-highlighted">Check out your room</h1>
            <p class="mt-2 text-2xl leading-9 text-muted">Enter your room number and the code on your check-in slip.</p>

            <form class="mt-8 flex flex-col gap-6" @submit.prevent="find">
                <UFormField label="Room number" :ui="{ label: 'text-2xl leading-9' }">
                    <UInput v-model="lookup.room_number" class="kiosk w-full" size="xl" :ui="{ base: 'h-[72px] text-[28px]' }" inputmode="numeric" autocomplete="off" autofocus />
                </UFormField>
                <UFormField label="Stay code" :ui="{ label: 'text-2xl leading-9' }">
                    <UInput v-model="lookup.code" class="kiosk w-full" size="xl" :ui="{ base: 'h-[72px] text-[28px] uppercase' }" autocomplete="off" placeholder="DGS-26-000000" />
                </UFormField>
            </form>

            <KioskFooter>
                <template #back>
                    <UButton class="h-[72px] justify-center text-2xl" size="xl" block color="neutral" variant="outline" label="Back" @click="home" />
                </template>
                <UButton class="h-[72px] justify-center text-2xl" size="xl" block label="Find my stay" trailing-icon="i-solar-alt-arrow-right-linear" :disabled="!findReady" :loading="lookup.processing" @click="find" />
            </KioskFooter>
        </template>

        <!-- Step 2: review the bill and request checkout -->
        <template v-else>
            <h1 class="text-[40px] leading-[48px] font-semibold text-highlighted">Room {{ bill.room }}</h1>
            <p class="mt-2 text-2xl leading-9 text-muted">{{ bill.guest_name }} · code {{ bill.code }}</p>

            <p class="mt-7 text-xl font-medium text-muted">
                <template v-if="bill.checked_in_at">Checked in {{ formatDateTime(bill.checked_in_at, 'datetime') }}</template>
                <template v-if="bill.expected_checkout_at"> · due out {{ formatDateTime(bill.expected_checkout_at, 'datetime') }}</template>
            </p>

            <!-- Every charge on the stay, so the guest sees what they've paid and still owe. -->
            <dl class="mt-4 rounded-card border border-default bg-default px-7 py-5 text-2xl leading-[44px]" data-test="checkout-lines">
                <div v-for="(line, i) in bill.lines" :key="i" class="flex items-center justify-between gap-4" :class="line.status === 'reversed' ? 'text-dimmed line-through' : ''">
                    <dt class="flex min-w-0 items-center gap-3 text-muted">
                        <span class="truncate">{{ line.label }}</span>
                        <StatusLabel v-if="line.status === 'unpaid'" tone="warn" label="Unpaid" />
                        <StatusLabel v-else-if="line.status === 'paid'" tone="ok" label="Paid" />
                    </dt>
                    <dd class="shrink-0 font-medium text-highlighted tabular">{{ formatMoney(line.amount) }}</dd>
                </div>
                <div v-if="bill.deposit > 0" class="flex justify-between gap-4 border-t border-default pt-3 text-muted">
                    <dt>Deposit to collect at the desk</dt>
                    <dd class="font-medium text-highlighted tabular">{{ formatMoney(bill.deposit) }}</dd>
                </div>
                <div class="mt-2 flex justify-between gap-4 border-t border-default pt-3 text-[28px] font-semibold text-highlighted">
                    <dt>Balance to settle</dt>
                    <dd class="tabular">{{ formatMoney(bill.balance) }}</dd>
                </div>
            </dl>

            <div class="mt-6 rounded-card border border-info-line bg-info-50 px-7 py-5 text-xl leading-8 text-info" data-test="checkout-note">
                <template v-if="bill.already_requested">
                    <b>You've already requested checkout.</b> Please go to the front desk to settle the balance and collect your deposit.
                </template>
                <template v-else>Confirm to let the front desk know you're leaving. Pay any balance and collect your deposit at the desk.</template>
            </div>

            <KioskFooter>
                <template v-if="bill.already_requested" #summary>
                    <div class="flex items-center gap-3 rounded-card border border-default bg-muted px-7 py-5">
                        <StatusLabel tone="ok" label="Checkout requested" size="lg" />
                        <span class="text-xl text-muted">Please proceed to the front desk.</span>
                    </div>
                </template>
                <template #back>
                    <UButton class="h-[72px] justify-center text-2xl" size="xl" block color="neutral" variant="outline" label="Back" @click="home" />
                </template>
                <UButton class="h-[72px] justify-center text-2xl" size="xl" block label="Request checkout" :disabled="bill.already_requested" :loading="requesting" data-test="checkout-confirm" @click="request" />
            </KioskFooter>
        </template>
    </KioskLayout>
</template>
