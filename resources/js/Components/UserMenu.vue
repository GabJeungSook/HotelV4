<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui';
import { useColorMode } from '@vueuse/core';
import { computed } from 'vue';

/** Avatar + short name opening the user menu: role, Switch user, Appearance (DESIGN_SYSTEM §5.2). */
const props = withDefaults(defineProps<{ user: { name: string; role: string }; canSwitch?: boolean }>(), { canSwitch: false });
const emit = defineEmits<{ switchUser: [] }>();

const mode = useColorMode({ emitAuto: true });

const items = computed<DropdownMenuItem[][]>(() => [
    [{ label: props.user.role, type: 'label' }],
    ...(props.canSwitch ? [[{ label: 'Switch user', icon: 'i-lucide-user', onSelect: () => emit('switchUser') }]] : []),
    [
        { label: 'Appearance', type: 'label' },
        { label: 'Light', icon: 'i-lucide-sun', type: 'checkbox', checked: mode.value === 'light', onSelect: () => (mode.value = 'light') },
        { label: 'Dark', icon: 'i-lucide-moon', type: 'checkbox', checked: mode.value === 'dark', onSelect: () => (mode.value = 'dark') },
        { label: 'System', icon: 'i-lucide-monitor', type: 'checkbox', checked: mode.value === 'auto', onSelect: () => (mode.value = 'auto') },
    ],
]);

const short = computed(() => {
    const parts = props.user.name.trim().split(/\s+/);

    return parts.length > 2 ? `${parts.slice(0, 2).join(' ')} ${parts[2][0]}.` : parts.length === 2 ? `${parts[0]} ${parts[1][0]}.` : parts[0];
});
</script>

<template>
    <UDropdownMenu :items="items" :content="{ align: 'end' }">
        <button type="button" class="hm-focus flex cursor-pointer items-center gap-2 rounded-md px-1 py-1 text-sm font-medium text-default hover:bg-elevated">
            <UAvatar :alt="user.name" size="sm" class="size-7" />
            <span class="whitespace-nowrap max-md:hidden">{{ short }}</span>
        </button>
    </UDropdownMenu>
</template>
