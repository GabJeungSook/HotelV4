import ui from '@nuxt/ui/vue-plugin';
import { flushPromises, mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import ConfirmDialog from '../ConfirmDialog.vue';
import { useConfirmDialog } from '@/Composables/useConfirmDialog';

const reasons = [
    { id: 1, name: 'Guest changed their mind', needs_note: false, needs_manager: false },
    { id: 2, name: 'Other', needs_note: true, needs_manager: false },
    { id: 3, name: 'Wrong amount', needs_note: false, needs_manager: true },
];

const base = { title: 'Release Room 210?', confirmLabel: 'Release room', cancelLabel: 'Keep hold', confirmColor: 'error' as const, inline: true };

function mountDialog(props: Record<string, unknown> = {}) {
    return mount(ConfirmDialog, { props: { ...base, ...props }, global: { plugins: [ui] }, attachTo: document.body });
}

describe('ConfirmDialog', () => {
    it('names the thing in the title and uses verbs on both buttons, safe one first', () => {
        const wrapper = mountDialog({ description: 'The hold ends and **₱0.00** is kept.' });
        const buttons = wrapper.findAll('button').map((b) => b.text());

        expect(wrapper.find('h3').text()).toBe('Release Room 210?');
        expect(wrapper.find('h3').classes()).toContain('text-error');
        expect(buttons).toEqual(['Keep hold', 'Release room']);
        expect(wrapper.find('b').text()).toBe('₱0.00');
    });

    it('closes with false on the safe button', async () => {
        const wrapper = mountDialog();

        await wrapper.find('[data-action="cancel"]').trigger('click');

        expect(wrapper.emitted('close')).toEqual([[false]]);
    });

    it('closes with the result when there is no reason list', async () => {
        const wrapper = mountDialog();

        await wrapper.find('[data-action="confirm"]').trigger('click');

        expect(wrapper.emitted('close')).toEqual([[{ reasonId: null, note: null, needsApproval: false }]]);
    });

    it('requires a reason when a list is given', async () => {
        const wrapper = mountDialog({ reasons });

        await wrapper.find('[data-action="confirm"]').trigger('click');

        expect(wrapper.emitted('close')).toBeUndefined();
        expect(wrapper.text()).toContain('Choose a reason.');
    });

    it('asks for a note when the reason needs one, and returns it', async () => {
        const wrapper = mountDialog({ reasons });
        const vm = wrapper.vm as unknown as { $: { setupState: { reasonId: number; note: string } } };

        vm.$.setupState.reasonId = 2;
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-field="note"]').exists()).toBe(true);

        await wrapper.find('[data-action="confirm"]').trigger('click');
        expect(wrapper.text()).toContain('Write a short note.');
        expect(wrapper.emitted('close')).toBeUndefined();

        vm.$.setupState.note = '  Guest found a cheaper place ';
        await wrapper.vm.$nextTick();
        await wrapper.find('[data-action="confirm"]').trigger('click');

        expect(wrapper.emitted('close')).toEqual([[{ reasonId: 2, note: 'Guest found a cheaper place', needsApproval: false }]]);
    });

    it('shows the lock and asks for approval when the reason needs a manager', async () => {
        const wrapper = mountDialog({ reasons });
        const vm = wrapper.vm as unknown as { $: { setupState: { reasonId: number } } };

        vm.$.setupState.reasonId = 3;
        await wrapper.vm.$nextTick();
        await wrapper.find('[data-action="confirm"]').trigger('click');

        expect(wrapper.emitted('close')).toEqual([[{ reasonId: 3, note: null, needsApproval: true }]]);
    });

    it('prints who it is recorded as', () => {
        expect(mountDialog({ loggedAs: 'Jessa Mae D.' }).text()).toContain('Recorded as Jessa Mae D.');
    });

    it('opens as a modal through useConfirmDialog() and resolves with the choice', async () => {
        let answer: Promise<unknown> | undefined;
        const Host = defineComponent({
            setup() {
                const confirm = useConfirmDialog();

                return () =>
                    h('button', { id: 'ask', onClick: () => (answer = confirm({ title: 'Cancel stay?', confirmLabel: 'Cancel stay', cancelLabel: 'Keep stay' })) }, 'ask');
            },
        });
        // UApp hosts the overlays, as it does around every page in app.ts.
        const UApp = (await import('@nuxt/ui/components/App.vue')).default;
        const wrapper = mount(UApp, { slots: { default: () => h(Host) }, global: { plugins: [ui] }, attachTo: document.body });

        await wrapper.find('#ask').trigger('click');
        await flushPromises();

        const keep = [...document.body.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Keep stay');
        expect(document.body.textContent).toContain('Cancel stay?');
        keep!.click();

        await expect(answer).resolves.toBe(false);
        wrapper.unmount();
    });
});
