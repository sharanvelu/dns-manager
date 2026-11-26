import {
    applySelectAll,
    EMPTY_IMPORT_FILTERS,
    filterExcluded,
    filterRecords,
    importPlan,
    initialSelection,
    recordKey,
    recordTypes,
    rowState,
    selectAllState,
    statusCounts,
    submitLabel,
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
    it('makes managed rows tracked (toggleable) unless untracking is unavailable', () => {
        expect(rowState(records[0])).toBe('selectable');
        expect(rowState(records[2])).toBe('tracked');
        expect(rowState(records[2], false)).toBe('locked');
        expect(rowState({ ...records[2], entryId: null })).toBe('locked');
    });

    it('starts with every row ticked — nothing to untrack', () => {
        const selected = initialSelection(records);

        expect(selected.size).toBe(4);
        expect(importPlan(records, selected).toUntrack).toEqual([]);
        expect(names(importPlan(records, selected).toImport)).toEqual(['www', 'mail', 'api']);
    });

    it('select-all only touches the visible toggleable rows', () => {
        const visible = filterRecords(records, { ...EMPTY_IMPORT_FILTERS, type: 'CNAME' });

        const cleared = applySelectAll(initialSelection(records), visible, false);
        expect([...cleared].sort()).toEqual([records[0], records[1]].map(recordKey).sort());
        expect(importPlan(records, cleared).toUntrack).toEqual([1]);

        const reselected = applySelectAll(cleared, visible, true);
        expect(reselected.size).toBe(4);
    });

    it('select-all leaves locked rows alone on a paused attachment', () => {
        const cleared = applySelectAll(initialSelection(records), records, false, false);

        expect([...cleared]).toEqual([recordKey(records[2])]);
        expect(importPlan(records, new Set(), false).toUntrack).toEqual([]);
    });

    it('reports the header checkbox state for the visible rows', () => {
        expect(selectAllState(records, new Set())).toBe(false);
        expect(selectAllState(records, new Set([recordKey(records[0])]))).toBe('indeterminate');
        expect(selectAllState(records, initialSelection(records))).toBe(true);
        // Locked rows don't count towards the header state.
        expect(selectAllState(records, new Set([records[0], records[1], records[3]].map(recordKey)), false)).toBe(true);
    });

    it('plans imports for ticked rows and untracks for unticked managed rows', () => {
        const selected = new Set([recordKey(records[0])]);

        expect(importPlan(records, selected)).toEqual({ toImport: [records[0]], toUntrack: [1] });
    });
});

describe('submitLabel', () => {
    it('describes what submitting does', () => {
        expect(submitLabel({ toImport: [], toUntrack: [] })).toBe('Import');
        expect(submitLabel({ toImport: [records[0]], toUntrack: [] })).toBe('Import 1 record');
        expect(submitLabel({ toImport: [], toUntrack: [4, 5] })).toBe('Stop tracking 2 records');
        expect(submitLabel({ toImport: [records[0], records[1]], toUntrack: [4] })).toBe('Import 2 · Stop tracking 1');
    });
});
