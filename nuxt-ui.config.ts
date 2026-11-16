/**
 * The Homi theme for Nuxt UI (DESIGN_SYSTEM §2.2 and §5.1), set once for the whole app.
 * Imported by vite.config.ts and vitest.config.ts so tests render the same classes.
 * Colours are token utilities only; the values live in resources/css/app.css.
 */
export const nuxtUiOptions = {
    router: 'inertia' as const,
    // Lucide icons are bundled from the local @iconify-json/lucide at build time; nothing is fetched
    // from the Iconify API at runtime (ASSETS §1.2). The scan picks up every i-lucide-* name in our code.
    icon: {
        clientBundle: {
            scan: { globInclude: ['resources/js/**/*.{vue,ts}', 'nuxt-ui.config.ts'], globExclude: ['node_modules', '**/__tests__/**'] },
        },
    },
    ui: {
        colors: { primary: 'homi', neutral: 'neutral' },
        icons: { loading: 'i-lucide-loader-circle' },

        // Mockup v6 `.btn`: one solid primary; every other button white with a 1 px line-strong ring;
        // 8 px radius, weight 600, the hairline shadow on solid and outline buttons.
        button: {
            slots: { base: 'cursor-pointer font-semibold rounded-control shadow-none justify-center whitespace-nowrap transition-colors duration-120 ease-out' },
            variants: {
                size: {
                    xs: { base: 'h-7 px-2 text-xs gap-1', leadingIcon: 'size-3.5', trailingIcon: 'size-3.5' },
                    sm: { base: 'h-[30px] px-2.5 text-xs gap-1.5', leadingIcon: 'size-[15px]', trailingIcon: 'size-[15px]' },
                    md: { base: 'h-[34px] px-3 text-[12.5px] gap-1.5', leadingIcon: 'size-[15px]', trailingIcon: 'size-[15px]' },
                    lg: { base: 'h-9 px-3.5 text-[12.5px] gap-1.5', leadingIcon: 'size-[15px]', trailingIcon: 'size-[15px]' },
                    xl: { base: 'h-[46px] px-5 text-sm gap-2 rounded-[10px]', leadingIcon: 'size-4', trailingIcon: 'size-4' },
                },
            },
            compoundVariants: [
                {
                    variant: ['solid', 'outline', 'subtle'],
                    class: 'shadow-xs',
                },
                {
                    color: 'primary',
                    variant: 'solid',
                    class: 'text-on-primary disabled:bg-(--hm-disabled) disabled:text-(--hm-disabled-ink) disabled:opacity-100 aria-disabled:bg-(--hm-disabled) aria-disabled:text-(--hm-disabled-ink) aria-disabled:opacity-100',
                },
                {
                    color: 'error',
                    variant: 'solid',
                    class: 'text-on-primary disabled:bg-(--hm-disabled) disabled:text-(--hm-disabled-ink) disabled:opacity-100',
                },
                {
                    color: 'success',
                    variant: 'solid',
                    class: 'text-on-primary',
                },
                {
                    color: 'neutral',
                    variant: ['outline', 'subtle'],
                    class: 'bg-default text-default ring ring-inset ring-accented hover:bg-elevated active:bg-elevated disabled:text-muted/60 disabled:opacity-100 disabled:hover:bg-default',
                },
                {
                    color: 'error',
                    variant: 'outline',
                    class: 'bg-default text-muted ring ring-inset ring-accented hover:bg-(--hm-bad-50) hover:text-error active:bg-(--hm-bad-50) disabled:opacity-60',
                },
                {
                    variant: ['solid', 'outline', 'subtle', 'soft', 'ghost', 'link'],
                    class: 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                },
            ],
        },

        // 36 px fields; focus = 1 px primary border + 3 px halo; error = bad border + 3 px bad-50 halo.
        input: {
            slots: { base: 'rounded-control shadow-xs transition-[box-shadow,color] duration-120 ease-out' },
            variants: {
                size: {
                    md: { base: 'h-8 px-2.5 text-[12.5px] gap-2' },
                    lg: { base: 'h-9 px-3 text-[13px] gap-2' },
                    xl: { base: 'h-11 px-3 text-[13.5px] gap-2.5 rounded-[10px]' },
                },
            },
            compoundVariants: [
                {
                    color: 'primary',
                    variant: ['outline', 'subtle'],
                    class: 'focus-visible:outline-0 focus-visible:ring-1 focus-visible:ring-(--hm-select-line) focus-visible:shadow-[0_0_0_4px_var(--hm-focus)]',
                },
                { color: 'error', highlight: true, class: 'ring-1 ring-error shadow-[0_0_0_3px_var(--hm-bad-50)]' },
            ],
            defaultVariants: { size: 'lg', variant: 'outline' },
        },
        select: {
            slots: { base: 'rounded-control shadow-xs' },
            compoundVariants: [
                {
                    color: 'primary',
                    variant: ['outline', 'subtle'],
                    class: 'focus-visible:outline-0 focus-visible:ring-1 focus-visible:ring-(--hm-select-line) focus-visible:shadow-[0_0_0_4px_var(--hm-focus)]',
                },
                { color: 'error', highlight: true, class: 'ring-1 ring-error shadow-[0_0_0_3px_var(--hm-bad-50)]' },
            ],
            defaultVariants: { size: 'lg', variant: 'outline' },
        },
        textarea: {
            slots: { base: 'rounded-control shadow-xs' },
            compoundVariants: [
                {
                    color: 'primary',
                    variant: ['outline', 'subtle'],
                    class: 'focus-visible:outline-0 focus-visible:ring-1 focus-visible:ring-(--hm-select-line) focus-visible:shadow-[0_0_0_4px_var(--hm-focus)]',
                },
                { color: 'error', highlight: true, class: 'ring-1 ring-error shadow-[0_0_0_3px_var(--hm-bad-50)]' },
            ],
            defaultVariants: { size: 'lg', variant: 'outline' },
        },
        formField: {
            slots: {
                label: 'text-[12.5px] leading-[18px] font-medium text-default',
                error: 'mt-1 text-xs font-semibold text-error',
                help: 'mt-1 text-xs text-muted',
                hint: 'text-xs text-muted',
            },
        },

        // 12 px radius, padding 20 px, 440 px wide, the one soft shadow; full screen under 640 px.
        modal: {
            slots: {
                overlay: 'bg-(--hm-overlay)',
                content: 'rounded-xl shadow-(--hm-shadow-modal) ring-0 divide-y-0',
                header: 'px-5 pt-5 pb-1 min-h-0',
                body: 'px-5 py-3',
                footer: 'px-5 pb-5 pt-2 justify-end gap-2',
                title: 'text-[18px] leading-[26px] font-semibold text-highlighted',
                description: 'mt-1 text-sm text-default',
            },
            variants: {
                fullscreen: {
                    false: { content: 'w-[calc(100vw-2rem)] max-w-[440px] max-sm:inset-0 max-sm:w-full max-sm:max-w-none max-sm:max-h-none max-sm:rounded-none max-sm:translate-x-0 max-sm:translate-y-0 max-sm:top-0 max-sm:left-0' },
                },
                overlay: { true: { overlay: 'bg-(--hm-overlay)' } },
            },
        },
        slideover: {
            slots: {
                overlay: 'bg-(--hm-overlay)',
                content: 'border-l border-default shadow-(--hm-shadow-sheet) ring-0 sm:ring-0 divide-y-0',
            },
            variants: { side: { right: { content: 'sm:max-w-[420px]' } } },
        },
        badge: {
            slots: { base: 'font-normal' },
            compoundVariants: [{ variant: 'subtle', class: 'ring-0' }],
        },
        checkbox: { slots: { base: 'rounded-[3px]' } },
        alert: {
            slots: { root: 'rounded-card ring-0 px-3.5 py-2.5 gap-2.5', title: 'text-sm font-normal', description: 'text-sm', icon: 'size-4 mt-0.5' },
            compoundVariants: [
                { color: 'success', variant: 'soft', class: { root: 'bg-ok-50 text-ok' } },
                { color: 'warning', variant: 'soft', class: { root: 'bg-warn-50 text-warn' } },
                { color: 'error', variant: 'soft', class: { root: 'bg-bad-50 text-bad' } },
                { color: 'info', variant: 'soft', class: { root: 'bg-info-50 text-info' } },
            ],
            defaultVariants: { variant: 'soft' },
        },
        avatar: {
            slots: { root: 'bg-nav-active', fallback: 'text-default font-medium' },
        },
        skeleton: { base: 'animate-pulse rounded-md bg-elevated' },
        tooltip: { slots: { content: 'shadow-none ring ring-default' } },
        selectMenu: { slots: { base: 'rounded-control shadow-xs' } },
        dropdownMenu: { slots: { content: 'rounded-[10px] shadow-(--hm-shadow-modal) ring-default' } },
        separator: { slots: { border: 'border-default' } },
    },
};
