<script setup lang="ts">
/**
 * Who is operating the open shift right now (its members — the front desks sharing the one drawer).
 * Always visible in the top bar; the signed-in person is marked "you". Hidden when no shift is open.
 */
import { computed } from 'vue';
import { useShared } from '@/Composables/useShared';

const { props: shared } = useShared();
const members = computed(() => shared.value.shift?.members ?? []);
const meId = computed(() => shared.value.auth?.user.id);
const first = (name: string): string => name.split(/\s+/)[0] ?? name;
const full = computed(() => members.value.map((m) => (m.id === meId.value ? `${m.name} (you)` : m.name)).join(', '));
</script>

<template>
    <div v-if="members.length" class="flex items-center gap-1.5 text-[12.5px] whitespace-nowrap" :title="`On the desk now: ${full}`">
        <UIcon name="i-solar-users-group-rounded-bold-duotone" class="size-4 shrink-0 text-muted" aria-hidden="true" />
        <span class="sr-only">On the desk now:</span>
        <span class="flex items-center gap-1">
            <span v-for="(m, i) in members" :key="m.id" :class="m.id === meId ? 'font-semibold text-highlighted' : 'text-muted'">
                {{ first(m.name) }}<template v-if="m.id === meId"> (you)</template><span v-if="i < members.length - 1" class="text-dimmed">,</span>
            </span>
        </span>
    </div>
</template>
