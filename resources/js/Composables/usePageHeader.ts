import type { InjectionKey } from 'vue';

/** What a page can set on the shell's page header (PageHeader → AppShell). */
export interface PageHeaderState {
    sub: string | undefined;
}

export const PAGE_HEADER: InjectionKey<PageHeaderState> = Symbol('homi.page-header');
