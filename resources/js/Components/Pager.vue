<script setup lang="ts">
import { computed } from 'vue';

/**
 * One pager per list, at the bottom of the table card (DESIGN_SYSTEM §0, mockup v6 `.pgn`):
 * Previous on the left, page numbers in the middle (the current one in primary-50), Next on the
 * right. Hidden when everything fits on one page.
 */
const props = defineProps<{ page: number; perPage: number; total: number }>();
const emit = defineEmits<{ 'update:page': [page: number] }>();

const pages = computed(() => Math.max(1, Math.ceil(props.total / props.perPage)));

/** 1 2 3 … 7 8 around the current page: first, last, and the current page with one on each side. */
const numbers = computed<(number | '…')[]>(() => {
    const n = pages.value;
    const p = props.page;

    if (n <= 7) {
        return Array.from({ length: n }, (_, i) => i + 1);
    }

    const keep = new Set([1, 2, n - 1, n, p - 1, p, p + 1].filter((x) => x >= 1 && x <= n));
    const sorted = [...keep].sort((a, b) => a - b);
    const out: (number | '…')[] = [];

    sorted.forEach((x, i) => {
        if (i > 0 && x - sorted[i - 1] > 1) {
            out.push('…');
        }
        out.push(x);
    });

    return out;
});

function go(p: number): void {
    if (p >= 1 && p <= pages.value && p !== props.page) {
        emit('update:page', p);
    }
}
</script>

<template>
    <nav v-if="pages > 1" aria-label="Pages" class="flex items-center gap-1.5 border-t border-default px-5 py-3 text-[12.5px] text-muted" data-pager>
        <UButton color="neutral" variant="outline" size="sm" icon="i-lucide-chevron-left" label="Previous" :disabled="page <= 1" @click="go(page - 1)" />
        <ol class="mx-auto flex gap-0.5">
            <li v-for="(n, i) in numbers" :key="`${n}-${i}`">
                <span v-if="n === '…'" class="grid h-[30px] min-w-[30px] place-items-center" aria-hidden="true">…</span>
                <button
                    v-else
                    type="button"
                    class="hm-focus grid h-[30px] min-w-[30px] cursor-pointer place-items-center rounded-[7px] font-medium transition-colors duration-120 ease-out"
                    :class="n === page ? 'bg-primary-50 text-primary-ink' : 'text-muted hover:bg-elevated'"
                    :aria-current="n === page ? 'page' : undefined"
                    :aria-label="`Page ${n}`"
                    @click="go(n)"
                >
                    {{ n }}
                </button>
            </li>
        </ol>
        <UButton color="neutral" variant="outline" size="sm" trailing-icon="i-lucide-chevron-right" label="Next" :disabled="page >= pages" @click="go(page + 1)" />
    </nav>
</template>
