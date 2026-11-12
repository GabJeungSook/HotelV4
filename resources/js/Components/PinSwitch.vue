<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import type { FlashError } from '@/Composables/useShared';
import PinPad from './PinPad.vue';
import PinUnlock from './PinUnlock.vue';

/**
 * Switch user by PIN (3, DESIGN_SYSTEM §5.2): pick a member of the open shift (skipped when
 * there is only one), then the PIN pad with the tries left. Three wrong PINs go to the full
 * sign-in; a locked computer shows the unlock form instead of the pad.
 */
const props = withDefaults(defineProps<{ members: { id: number; name: string }[]; locked?: boolean; tries?: number }>(), { locked: false, tries: 3 });

const open = defineModel<boolean>('open', { default: false });

const picked = ref<number | null>(null);
const processing = ref(false);
const error = ref<string | undefined>(undefined);
const triesLeft = ref<number>(props.tries);
const attempt = ref(0);

const member = computed(() => props.members.find((m) => m.id === picked.value) ?? (props.members.length === 1 ? props.members[0] : undefined));

watch(open, (value) => {
    if (value) {
        picked.value = props.members.length === 1 ? props.members[0].id : null;
        error.value = undefined;
        triesLeft.value = props.tries;
        attempt.value++;
    }
});

function submit(pin: string): void {
    if (!member.value) {
        return;
    }

    processing.value = true;
    const target = member.value.id;
    router.post(
        route('switch'),
        { user_id: target, pin },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                // Signed on as the person picked: done, whatever an earlier answer said.
                if ((page.props as { auth?: { user: { id: number } } | null }).auth?.user.id === target) {
                    open.value = false;

                    return;
                }

                const e = ((page.flash ?? {}) as { error?: FlashError }).error;

                if (e && (e.code === 'pin_invalid' || e.code === 'pin_locked' || e.code === 'action_refused')) {
                    error.value = e.code === 'pin_invalid' ? 'PIN not recognised' : e.message;
                    triesLeft.value = e.tries_left ?? triesLeft.value;
                    attempt.value++;

                    return;
                }

                open.value = false;
            },
            onFinish: () => (processing.value = false),
        },
    );
}
</script>

<template>
    <UModal v-model:open="open" title="Switch user" :ui="{ content: 'max-w-[360px]' }">
        <template #body>
            <template v-if="locked">
                <PinPad locked :show-keys="false" />
                <PinUnlock />
            </template>
            <template v-else-if="members.length === 0">
                <p class="text-sm text-muted">No other member is on this shift.</p>
            </template>
            <template v-else-if="!member">
                <p class="mb-3 text-sm text-muted">Who is taking over this computer?</p>
                <div class="flex flex-col gap-2">
                    <UButton v-for="m in members" :key="m.id" color="neutral" variant="outline" block size="lg" :label="m.name" @click="picked = m.id" />
                </div>
            </template>
            <template v-else>
                <p class="mb-4 text-sm text-muted">{{ member.name }} — enter your 4-digit PIN</p>
                <PinPad :key="attempt" :error="error" :tries-left="triesLeft" :disabled="processing" @complete="submit" />
            </template>
        </template>
        <template #footer>
            <div class="flex w-full justify-between">
                <UButton color="neutral" variant="ghost" label="Cancel" @click="open = false" />
                <UButton v-if="member && members.length > 1 && !locked" color="neutral" variant="ghost" label="Someone else" @click="picked = null" />
            </div>
        </template>
    </UModal>
</template>
