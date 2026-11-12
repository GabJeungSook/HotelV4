import { ref } from 'vue';

/**
 * Every print or export is logged before it happens (print_logs, TRD §4.10). `print()` posts to
 * the page's log route, learns whether this is a reprint, then opens the browser's print dialog.
 * When the log cannot be written nothing is printed, so no paper leaves the desk unrecorded.
 */
function xsrf(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export function usePrintLog() {
    const reprint = ref(false);
    const printing = ref(false);
    const error = ref<string | undefined>(undefined);

    async function print(url: string, body: Record<string, unknown> = {}): Promise<boolean> {
        printing.value = true;
        error.value = undefined;

        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
                body: JSON.stringify(body),
            });

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            reprint.value = Boolean(((await response.json()) as { reprint?: boolean }).reprint);
            setTimeout(() => window.print(), 50);

            return true;
        } catch {
            error.value = 'The print could not be logged, so it was not printed. Try again.';

            return false;
        } finally {
            printing.value = false;
        }
    }

    return { print, reprint, printing, error };
}
