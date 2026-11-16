<script setup lang="ts">
import { ref } from 'vue';
import blue400 from '../../images/brand/homi-logo-400.png';
import blue800 from '../../images/brand/homi-logo-800.png';
import white400 from '../../images/brand/homi-logo-white-400.png';
import white800 from '../../images/brand/homi-logo-white-800.png';

/**
 * The Homi logo (ASSETS §1.3): blue on light backgrounds, white on photos and dark ones.
 * If the image fails, the fallback is the bed icon on a rounded square followed by "Homi".
 */
withDefaults(defineProps<{ variant?: 'blue' | 'white'; width?: number; height?: number; name?: string }>(), { variant: 'blue', width: 112, height: 34, name: 'Homi' });

const failed = ref(false);
</script>

<template>
    <span v-if="failed" class="inline-flex items-center gap-2" :style="{ height: `${height}px` }" data-logo-fallback>
        <span class="grid aspect-square h-full place-items-center rounded-lg" :class="variant === 'white' ? 'bg-default' : 'bg-primary'">
            <UIcon name="i-solar-bed-bold-duotone" class="size-1/2" :class="variant === 'white' ? 'text-primary' : 'text-on-primary'" aria-hidden="true" />
        </span>
        <span class="text-lg font-semibold" :class="variant === 'white' ? 'text-(--hm-card)' : 'text-highlighted'">{{ name }}</span>
    </span>
    <img
        v-else
        :src="variant === 'white' ? white400 : blue400"
        :srcset="variant === 'white' ? `${white400} 400w, ${white800} 800w` : `${blue400} 400w, ${blue800} 800w`"
        :sizes="`${width}px`"
        :width="width"
        :height="height"
        :alt="name"
        class="block object-contain object-left"
        @error="failed = true"
    />
</template>
