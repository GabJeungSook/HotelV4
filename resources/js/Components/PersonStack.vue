<script setup lang="ts">
import PersonAvatar from './PersonAvatar.vue';

/** Stacked avatars where several people share a row ("On duty" on 60); names are in the title and for readers. */
withDefaults(defineProps<{ names: string[]; size?: number }>(), { size: 24 });
</script>

<template>
    <span class="inline-flex items-center" :aria-label="names.join(', ')" role="img" data-person-stack>
        <PersonAvatar v-for="(n, i) in names" :key="`${n}-${i}`" :name="n" :size="size" ring :class="i > 0 ? '-ms-1.5' : ''" />
    </span>
</template>
