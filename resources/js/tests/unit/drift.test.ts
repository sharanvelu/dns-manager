import { acceptableDriftStates, driftFieldLabel, formatDriftValue } from '@/components/entries/drift';
import type { EntryItem, SyncStateItem } from '@/components/entries/types';
import { describe, expect, it } from 'vitest';

function state(id: number, status: SyncStateItem['status'], driftDetails: SyncStateItem['driftDetails'] = null): SyncStateItem {
    return {
        id,
        zoneProviderId: id,
        providerId: id,
        providerName: `Provider ${id}`,
        providerType: 'cloudflare',
        status,
        lastSyncedAt: null,
        lastError: null,
        driftDetails,
    };
}

function entry(syncStates: SyncStateItem[]): EntryItem {
    return {
        id: 1,
        zone: { id: 1, name: 'example.com' },
        name: 'app',
        fqdn: 'app.example.com',
        type: 'A',
        content: '10.0.0.1',
        ttl: null,
        priority: null,
        proxied: false,
        comment: null,
        updatedAt: '2026-01-01T00:00:00Z',
        syncStates,
    };
}

describe('acceptableDriftStates', () => {
    it('offers only drifted states whose remote record still exists', () => {
        const detail = [{ field: 'content', tracked: '10.0.0.1', actual: '10.0.0.2' }];
        const states = [state(1, 'drifted', detail), state(2, 'drifted', null), state(3, 'synced'), state(4, 'drifted', detail)];

        expect(acceptableDriftStates(entry(states)).map((s) => s.id)).toEqual([1, 4]);
    });

    it('offers nothing for an entry without drift', () => {
        expect(acceptableDriftStates(entry([state(1, 'synced'), state(2, 'error')]))).toEqual([]);
    });
});

describe('drift formatting', () => {
    it('labels TTL and renders auto, booleans and missing values', () => {
        expect(driftFieldLabel('ttl')).toBe('TTL');
        expect(driftFieldLabel('content')).toBe('content');
        expect(formatDriftValue({ field: 'ttl', tracked: null, actual: 300 }, null)).toBe('Auto');
        expect(formatDriftValue({ field: 'priority', tracked: null, actual: 10 }, null)).toBe('—');
        expect(formatDriftValue({ field: 'proxied', tracked: false, actual: true }, true)).toBe('Yes');
        expect(formatDriftValue({ field: 'ttl', tracked: null, actual: 300 }, 300)).toBe('300');
    });
});
