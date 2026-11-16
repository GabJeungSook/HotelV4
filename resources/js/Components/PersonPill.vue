<script setup lang="ts">
import PersonAvatar from './PersonAvatar.vue';

/**
 * A person in a table (DESIGN_SYSTEM §0, mockup v6 `.gp`): a 22 px initials avatar inside a white
 * pill with a 1 px border. Used for guests, front desks and staff in every table that lists people.
 * `extra` adds a muted "+1" when someone else shared the row.
 */
withDefaults(defineProps<{ name: string; extra?: string }>(), { extra: undefined });
</script>

<template>
    <span
        class="inline-flex h-7 max-w-full items-center gap-[7px] rounded-full border border-default bg-default ps-0.5 pe-2.5 text-[12.5px] font-medium whitespace-nowrap text-highlighted"
        data-person
    >
        <PersonAvatar :name="name" :size="22" />
        <span class="truncate">{{ name }}</span>
        <span v-if="extra" class="font-medium text-muted">{{ extra }}</span>
    </span>
</template>
