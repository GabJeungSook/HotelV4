import { describe, expect, it } from 'vitest';
import { changeCard } from '../useDashboard';

describe('changeCard (TRD §4.11)', () => {
    it('shows no change figure without earlier data, never "+100%"', () => {
        expect(changeCard(null, 'vs yesterday by this time')).toEqual({ sub: 'No earlier figure to compare' });
        expect(changeCard(undefined, 'vs yesterday by this time').trend).toBeUndefined();
    });

    it('marks a rise up and a fall down with the comparison words', () => {
        expect(changeCard(8, 'vs yesterday by this time')).toEqual({ sub: '8% vs yesterday by this time', trend: 'up' });
        expect(changeCard(-12, 'vs the 7 days before')).toEqual({ sub: '12% vs the 7 days before', trend: 'down' });
        expect(changeCard(0, 'vs the day before').trend).toBe('up');
    });
});
