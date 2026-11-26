/** Pure selection/filter logic for the provider import dialog (unit-tested). */

export const IMPORT_STATUSES = ['new', 'exists', 'managed'] as const;
export type ImportStatus = (typeof IMPORT_STATUSES)[number];

/** An importable remote record, as returned by `zones.import.records`. */
export interface RemoteRecord {
    externalId: string;
    type: string;
    /** Zone-relative name. */
    name: string;
    content: string;
    ttl: number | null;
    priority: number | null;
    proxied: boolean;
    /** new = no local entry; exists = matches an entry not linked here; managed = already tracked from this attachment. */
    status: ImportStatus;
    /** The matching local entry (exists/managed), null for new. */
    entryId: number | null;
}

export type ExcludedReason = 'unmanaged-type' | 'out-of-zone';

/** A remote record that cannot be imported, shown for context only. */
export interface ExcludedRecord {
    type: string;
    /** Zone-relative for unmanaged types; the remote FQDN for out-of-zone records. */
    name: string;
    content: string;
    ttl: number | null;
    reason: ExcludedReason;
}

export type StatusCounts = Record<ImportStatus, number>;

export interface ImportFilters {
    search: string;
    /** '' = every type. */
    type: string;
    /** 'all' = every status. */
    status: ImportStatus | 'all';
}

export const EMPTY_IMPORT_FILTERS: ImportFilters = { search: '', type: '', status: 'all' };

/**
 * How a row behaves in the checklist. `tracked` rows (already managed from
 * this attachment) render checked and locked today; untracking them from the
 * dialog would turn `tracked` into a toggleable state.
 */
export type RowState = 'selectable' | 'tracked';

export function rowState(record: RemoteRecord): RowState {
    return record.status === 'managed' ? 'tracked' : 'selectable';
}

export function recordKey(record: RemoteRecord): string {
    return `${record.type}|${record.name}|${record.content}|${record.externalId}`;
}

function matchesText(record: { name: string; content: string }, search: string): boolean {
    const needle = search.trim().toLowerCase();

    return needle === '' || record.name.toLowerCase().includes(needle) || record.content.toLowerCase().includes(needle);
}

/** Importable rows matching search (name/content), type, and status. */
export function filterRecords(records: RemoteRecord[], filters: ImportFilters): RemoteRecord[] {
    return records.filter(
        (record) =>
            (filters.type === '' || record.type === filters.type) &&
            (filters.status === 'all' || record.status === filters.status) &&
            matchesText(record, filters.search),
    );
}

/** Excluded rows matching search and type — status does not apply to them. */
export function filterExcluded(records: ExcludedRecord[], filters: ImportFilters): ExcludedRecord[] {
    return records.filter((record) => (filters.type === '' || record.type === filters.type) && matchesText(record, filters.search));
}

/**
 * Per-status counts over the rows matching search + type (the status filter
 * itself is ignored, so each status chip shows what selecting it would list).
 */
export function statusCounts(records: RemoteRecord[], filters: ImportFilters): StatusCounts {
    const counts: StatusCounts = { new: 0, exists: 0, managed: 0 };

    filterRecords(records, { ...filters, status: 'all' }).forEach((record) => counts[record.status]++);

    return counts;
}

/** Sorted distinct record types across importable and excluded rows. */
export function recordTypes(records: RemoteRecord[], excluded: ExcludedRecord[] = []): string[] {
    return [...new Set([...records, ...excluded].map((record) => record.type))].sort();
}

/** The default selection: every selectable row. */
export function initialSelection(records: RemoteRecord[]): Set<string> {
    return new Set(records.filter((record) => rowState(record) === 'selectable').map(recordKey));
}

export type SelectAllState = boolean | 'indeterminate';

/** Header checkbox state for the visible rows. */
export function selectAllState(visible: RemoteRecord[], selected: Set<string>): SelectAllState {
    const selectable = visible.filter((record) => rowState(record) === 'selectable');
    const chosen = selectable.filter((record) => selected.has(recordKey(record))).length;

    if (chosen === 0) return false;

    return chosen === selectable.length ? true : 'indeterminate';
}

/**
 * Select-all applies to the visible (filtered) selectable rows only; rows
 * hidden by the filters keep their current selection.
 */
export function applySelectAll(selected: Set<string>, visible: RemoteRecord[], checked: boolean): Set<string> {
    const next = new Set(selected);

    visible
        .filter((record) => rowState(record) === 'selectable')
        .forEach((record) => (checked ? next.add(recordKey(record)) : next.delete(recordKey(record))));

    return next;
}
