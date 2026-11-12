<script setup lang="ts">
/** Every list has an empty state with one way forward (DESIGN_SYSTEM §5.2). `compact` is the chart form. */
withDefaults(
    defineProps<{
        title: string;
        text?: string;
        icon?: string;
        tone?: 'default' | 'ok' | 'bad';
        actionLabel?: string;
        compact?: boolean;
        size?: 'md' | 'kiosk';
    }>(),
    { text: undefined, icon: 'i-lucide-list', tone: 'default', actionLabel: undefined, compact: false, size: 'md' },
);

defineEmits<{ action: [] }>();
</script>

<template>
    <div class="flex flex-col items-center text-center text-muted" :class="compact ? 'p-6' : 'px-4 py-12'" data-empty-state>
        <UIcon
            v-if="!compact"
            :name="icon"
            aria-hidden="true"
            class="mb-3"
            :class="[size === 'kiosk' ? 'size-24' : 'size-10', tone === 'ok' ? 'text-ok' : tone === 'bad' ? 'text-bad' : 'text-empty-icon']"
        />
        <p :class="compact ? 'text-sm text-muted' : 'font-semibold text-highlighted'">{{ title }}</p>
        <p v-if="text && !compact" class="mt-1 text-sm">{{ text }}</p>
        <UButton v-if="actionLabel" class="mt-4" color="neutral" variant="outline" :label="actionLabel" @click="$emit('action')" />
    </div>
</template>
