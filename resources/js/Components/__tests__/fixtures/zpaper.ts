import type { ZPaperData } from '../../ZPaper.vue';

/** A trimmed copy of the demo Z-0629 builder array (mockup 37b), for the gallery and the tests. */
export const demoPaper: ZPaperData = {
    kind: 'shift',
    preview: false,
    header: {
        branch: 'Hotel Dalisay GenSan',
        title: 'Z-READ · AM shift · Wed 11 Nov 2026',
        number: 'Z-0629',
        opened: 'Opened 7:58 AM',
        closed: 'Closed 8:04 PM',
        printed: 'Printed 8:06 PM by Jessa Mae D.',
        tin: null,
        registered_name: null,
    },
    sections: [
        {
            key: 'sales_cash',
            title: 'Sales and cash',
            layout: 'waterfall',
            column: 'left',
            lines: [
                { label: 'Gross sales', amount: 1846000, style: 'plain', drill: 'gross' },
                { label: 'Less Promo ₱50', count: 4, amount: -20000, style: 'indent', drill: 'discount:Promo' },
                { label: 'Less Senior citizen 20%', count: 1, amount: -10000, style: 'indent' },
                { label: 'Net sales', amount: 1816000, style: 'equals', drill: 'net' },
                { label: 'Expected in the drawer', amount: 1319500, style: 'equals' },
                { label: 'Counted', amount: 1314500, style: 'plain' },
                { label: 'Short', amount: -5000, style: 'plain', tone: 'bad', note: '"Gave wrong change to Room 203, ₱50."' },
            ],
        },
        {
            key: 'by_user',
            title: 'By front desk',
            layout: 'table',
            column: 'right',
            columns: [{ label: 'Name' }, { label: 'In', type: 'count' }, { label: 'Cash', type: 'money' }, { label: 'Back', type: 'money' }],
            lines: [
                { label: 'Jessa Mae D.', cells: [15, 1048000, 0] },
                { label: 'Rhea B.', cells: [12, 756500, 70000] },
            ],
        },
        {
            key: 'exceptions',
            title: 'Exceptions',
            layout: 'table',
            column: 'right',
            columns: [{ label: '' }, { label: 'Count', type: 'count' }, { label: 'Amount', type: 'money' }],
            lines: [
                { label: 'Cancelled stays', count: 1, amount: 70000 },
                { label: 'Slip reprints', count: 2, amount: null },
            ],
        },
        {
            key: 'grand_total',
            title: 'Running grand total',
            layout: 'lines',
            column: 'right',
            lines: [
                { label: 'Before this shift', amount: 1148234000, style: 'plain' },
                { label: 'This shift, net sales', amount: 1816000, style: 'plain' },
                { label: 'After this shift', amount: 1150050000, style: 'equals' },
            ],
        },
    ],
    signatures: [
        { name: 'Jessa Mae Dela Cruz', role: 'Closed by' },
        { name: 'Mark Anthony Sumagaysay', role: 'Received by' },
        { name: null, role: 'Back office · name and date' },
    ],
    checks: [],
};
