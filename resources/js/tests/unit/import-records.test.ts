import {
    applySelectAll,
    EMPTY_IMPORT_FILTERS,
    filterExcluded,
    filterRecords,
    initialSelection,
    recordKey,
    recordTypes,
    rowState,
    selectAllState,
    statusCounts,
    type ExcludedRecord,
    type RemoteRecord,
} from '@/components/zones/import-records';
import { describe, expect, it } from 'vitest';

function remote(name: string, type: string, content: string, status: RemoteRecord['status']): RemoteRecord {
    return {
        externalId: `id-${name}-${type}`,
        type,
        name,
        content,
        ttl: null,
        priority: null,
        proxied: false,
        status,
        entryId: status === 'new' ? null : 1,
    };
}

const records: RemoteRecord[] = [
    remote('www', 'A', '10.0.0.1', 'new'),
    remote('mail', 'A', '10.0.0.2', 'exists'),
    remote('nas', 'CNAME', 'mail.example.com', 'managed'),
    remote('api', 'CNAME', 'www.example.com', 'new'),
];

const excluded: ExcludedRecord[] = [
    { type: 'TXT', name: '@', content: '"v=spf1 -all"', ttl: null, reason: 'unmanaged-type' },
    { type: 'A', name: 'host.other.dev', content: '10.9.9.9', ttl: null, reason: 'out-of-zone' },
];

const names = (rows: { name: string }[]) => rows.map((row) => row.name);

describe('filterRecords', () => {
    it('returns everything without filters', () => {
        expect(filterRecords(records, EMPTY_IMPORT_FILTERS)).toHaveLength(4);
    });

    it('searches name and content case-insensitively', () => {
        expect(names(filterRecords(records, { ...EMPTY_IMPORT_FILTERS, search: ' MAIL ' }))).toEqual(['mail', 'nas']);
    });

    it('combines type and status filters', () => {
        expect(names(filterRecords(records, { search: '', type: 'CNAME', status: 'new' }))).toEqual(['api']);
    });
});

describe('statusCounts', () => {
    it('counts per status over search + type, ignoring the status filter', () => {
        expect(statusCounts(records, { search: '', type: 'A', status: 'managed' })).toEqual({ new: 1, exists: 1, managed: 0 });
        expect(statusCounts(records, EMPTY_IMPORT_FILTERS)).toEqual({ new: 2, exists: 1, managed: 1 });
    });
});

describe('filterExcluded', () => {
    it('applies search and type but not status', () => {
        expect(names(filterExcluded(excluded, { search: 'other', type: '', status: 'new' }))).toEqual(['host.other.dev']);
        expect(names(filterExcluded(excluded, { ...EMPTY_IMPORT_FILTERS, type: 'TXT' }))).toEqual(['@']);
    });
});

describe('recordTypes', () => {
    it('lists distinct sorted types across importable and excluded rows', () => {
        expect(recordTypes(records, excluded)).toEqual(['A', 'CNAME', 'TXT']);
    });
});

describe('selection', () => {
    it('locks managed rows as tracked and preselects the rest', () => {
        expect(rowState(records[2])).toBe('tracked');
        expect([...initialSelection(records)].sort()).toEqual([records[0], records[1], records[3]].map(recordKey).sort());
    });

    it('select-all only touches the visible selectable rows', () => {
        const visible = filterRecords(records, { ...EMPTY_IMPORT_FILTERS, type: 'CNAME' });

        const cleared = applySelectAll(initialSelection(records), visible, false);
        expect([...cleared].sort()).toEqual([records[0], records[1]].map(recordKey).sort());

        const reselected = applySelectAll(cleared, visible, true);
        expect(reselected.has(recordKey(records[3]))).toBe(true);
        expect(reselected.has(recordKey(records[2]))).toBe(false);
    });

    it('reports the header checkbox state for the visible rows', () => {
        expect(selectAllState(records, new Set())).toBe(false);
        expect(selectAllState(records, new Set([recordKey(records[0])]))).toBe('indeterminate');
        expect(selectAllState(records, initialSelection(records))).toBe(true);
    });
});
