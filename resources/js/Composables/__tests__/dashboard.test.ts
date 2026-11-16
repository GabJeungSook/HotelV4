import { describe, expect, it } from 'vitest';
import { changeCard } from '../useDashboard';

describe('changeCard (TRD §4.11)', () => {
    it('shows no change figure without earlier data, never "+100%"', () => {
        expect(changeCard(null, 'vs yesterday by this time')).toEqual({ sub: 'No earlier figure to compare' });
        expect(changeCard(undefined, 'vs yesterday by this time').trend).toBeUndefined();
    });

    it('marks a rise up and a fall down, the change on the right of the comparison words (v6)', () => {
        expect(changeCard(8, 'vs yesterday by this time')).toEqual({ sub: 'vs yesterday by this time', change: '+8%', trend: 'up' });
        expect(changeCard(-12, 'vs the 7 days before')).toEqual({ sub: 'vs the 7 days before', change: '−12%', trend: 'down' });
        expect(changeCard(0, 'vs the day before').trend).toBe('up');
    });

    it('can name the earlier figure in the comparison line', () => {
        expect(changeCard(8, 'vs yesterday by this time', 'vs ₱8,720 yesterday by this time')).toEqual({ sub: 'vs ₱8,720 yesterday by this time', change: '+8%', trend: 'up' });
    });
});
