<!-- Checkout requests: guests who asked to check out at the kiosk (and desk-started ones). A live
     rail on the board, reloaded every few seconds. "Check out" opens that guest's checkout screen;
     the front desk settles the bill, returns the deposit and finishes. -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import MoneyRow from '@/Components/MoneyRow.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import { formatDateTime } from '@/Composables/useDateTime';

export interface CheckoutRequest {
    id: number;
    code: string;
    room: string | null;
    guest: string;
    balance: number;
    deposit: number;
    requested_at: string | null;
}

defineProps<{ requests: CheckoutRequest[] }>();
</script>

<template>
    <div>
        <p v-if="requests.length === 0" class="py-8 text-center text-sm text-muted" data-test="no-checkout-requests">No checkout requests right now.</p>
        <div v-else class="flex flex-col gap-3">
            <article v-for="r in requests" :key="r.id" class="rounded-card border border-info p-3.5" :data-checkout="r.code">
                <div class="flex items-center justify-between gap-2">
                    <b class="text-[15px] font-semibold text-highlighted tabular">Room {{ r.room }}</b>
                    <StatusLabel tone="info" :label="`Requested ${formatDateTime(r.requested_at, 'time')}`" />
                </div>
                <p class="mt-1 text-sm">{{ r.guest }} · {{ r.code }}</p>
                <MoneyRow class="mt-2" label="Balance to settle" :amount="r.balance" />
                <MoneyRow v-if="r.deposit > 0" label="Deposit to return" :amount="r.deposit" />
                <div class="mt-3">
                    <UButton size="sm" block class="justify-center" label="Check out" @click="router.visit(route('stays.checkout', r.id))" />
                </div>
            </article>
        </div>
    </div>
</template>
