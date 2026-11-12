<script setup lang="ts">
import { useIntervalFn } from '@vueuse/core';
import QRCode from 'qrcode';
import { computed, onMounted, ref, watch } from 'vue';
import { formatDuration } from '@/Composables/useDateTime';
import { formatMoney } from '@/Composables/useMoney';
import StatusLabel from './StatusLabel.vue';

/**
 * The hold on the kiosk (30, DESIGN_SYSTEM §5.3): the amount to pay at the desk, the QR of the
 * stay code (drawn in the browser, black on white, ASSETS §1.6), the code in 48 px mono under it
 * so a QR that fails to draw never blocks the guest, and the countdown to hold_expires_at.
 * `now` is the server time the page was rendered at, so a kiosk clock that is off by a few
 * minutes still counts down the server's timer.
 */
const props = withDefaults(defineProps<{ code: string; amount: number; expiresAt: string | null; now?: string | null; qrSize?: number }>(), { now: null, qrSize: 400 });

const canvas = ref<HTMLCanvasElement | null>(null);
const qrFailed = ref(false);
const offset = props.now ? Date.parse(props.now) - Date.now() : 0;
const tick = ref(Date.now());

useIntervalFn(() => (tick.value = Date.now()), 1000);

const secondsLeft = computed(() => (props.expiresAt ? Math.max(0, Math.floor((Date.parse(props.expiresAt) - (tick.value + offset)) / 1000)) : 0));
const expired = computed(() => secondsLeft.value <= 0);

async function draw(): Promise<void> {
    if (!canvas.value) {
        return;
    }

    try {
        await QRCode.toCanvas(canvas.value, props.code, { width: props.qrSize, margin: 1, color: { dark: '#000000', light: '#ffffff' } });
        qrFailed.value = false;
    } catch {
        qrFailed.value = true;
    }
}

onMounted(draw);
watch(() => props.code, draw);

defineExpose({ secondsLeft, expired });
</script>

<template>
    <div class="flex flex-col items-center text-center" data-hold-card>
        <p class="text-2xl leading-[34px] text-muted">
            Please go to the front desk and pay <b class="text-highlighted tabular">{{ formatMoney(amount) }}</b>
        </p>
        <canvas v-show="!qrFailed" ref="canvas" class="mt-8 rounded-card border border-default" :width="qrSize" :height="qrSize" :aria-label="`QR code for ${code}`" role="img" />
        <p class="mt-6 font-mono text-[48px] leading-[56px] font-semibold text-highlighted" data-code>{{ code }}</p>
        <StatusLabel v-if="!expired" class="mt-8" size="lg" tone="warn" :label="`Held for ${formatDuration(secondsLeft, 'countdown')}`" data-countdown />
        <StatusLabel v-else class="mt-8" size="lg" tone="neutral" label="This hold has ended" data-countdown />
    </div>
</template>
