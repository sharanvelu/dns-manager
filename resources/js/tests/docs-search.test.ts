import { describe, expect, it } from 'vitest';

import { searchDocs } from '@/pages/docs/lib/search';
import type { SearchEntry } from '@/pages/docs/types';

const index: SearchEntry[] = [
    {
        slug: 'index',
        title: 'Overview',
        description: 'What DNS Manager is.',
        headings: [{ id: 'features', text: 'Features', level: 2 }],
        text: 'DNS Manager is a self-hosted control plane for DNS records.',
    },
    {
        slug: 'zones',
        title: 'DNS Zones',
        description: 'Group records by domain.',
        headings: [
            { id: 'attaching-a-provider', text: 'Attaching a provider', level: 2 },
            { id: 'zone-discovery', text: 'Zone discovery', level: 3 },
        ],
        text: 'Zones group records. Attach providers to zones.',
    },
];

describe('searchDocs', () => {
    it('returns nothing for queries shorter than two characters', () => {
        expect(searchDocs(index, '')).toEqual([]);
        expect(searchDocs(index, 'z')).toEqual([]);
        expect(searchDocs(index, '  ')).toEqual([]);
    });

    it('ranks title matches above body matches', () => {
        const results = searchDocs(index, 'zones');

        expect(results[0].slug).toBe('zones');
        expect(results[0].heading).toBeUndefined();
    });

    it('returns heading matches with their ids for deep links', () => {
        const results = searchDocs(index, 'attaching');

        expect(results).toHaveLength(1);
        expect(results[0].heading).toEqual({ id: 'attaching-a-provider', text: 'Attaching a provider' });
    });

    it('matches case-insensitively across fields', () => {
        expect(searchDocs(index, 'SELF-HOSTED')[0].slug).toBe('index');
        expect(searchDocs(index, 'discovery').some((result) => result.heading?.id === 'zone-discovery')).toBe(true);
    });

    it('caps the number of results', () => {
        const results = searchDocs(index, 'zone', 1);

        expect(results).toHaveLength(1);
    });
});
