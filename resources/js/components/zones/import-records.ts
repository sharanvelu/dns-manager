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
 * How a row behaves in the checklist. A ticked row ends up managed from this
 * attachment: `selectable` rows (new / matches existing) import when ticked;
 * `tracked` rows (already managed) start ticked and stop being tracked when
 * unticked — the record stays at the provider. `locked` is a tracked row on a
 * paused attachment, which cannot be untracked (paused invariant).
 */
export type RowState = 'selectable' | 'tracked' | 'locked';

export function rowState(record: RemoteRecord, canUntrack = true): RowState {
    if (record.status !== 'managed') return 'selectable';

    return canUntrack && record.entryId !== null ? 'tracked' : 'locked';
}

export function isToggleable(record: RemoteRecord, canUntrack = true): boolean {
    return rowState(record, canUntrack) !== 'locked';
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

/** The default selection: every row ticked — import everything, keep tracking what is tracked. */
export function initialSelection(records: RemoteRecord[]): Set<string> {
    return new Set(records.map(recordKey));
}

export type SelectAllState = boolean | 'indeterminate';

/** Header checkbox state for the visible toggleable rows. */
export function selectAllState(visible: RemoteRecord[], selected: Set<string>, canUntrack = true): SelectAllState {
    const toggleable = visible.filter((record) => isToggleable(record, canUntrack));
    const chosen = toggleable.filter((record) => selected.has(recordKey(record))).length;

    if (chosen === 0) return false;

    return chosen === toggleable.length ? true : 'indeterminate';
}

/**
 * Select-all applies to the visible (filtered) toggleable rows only; rows
 * hidden by the filters and locked rows keep their current state.
 */
export function applySelectAll(selected: Set<string>, visible: RemoteRecord[], checked: boolean, canUntrack = true): Set<string> {
    const next = new Set(selected);

    visible
        .filter((record) => isToggleable(record, canUntrack))
        .forEach((record) => (checked ? next.add(recordKey(record)) : next.delete(recordKey(record))));

    return next;
}

export interface ImportPlan {
    /** Ticked rows not yet managed here. */
    toImport: RemoteRecord[];
    /** Entry ids of unticked managed rows — released with the record kept at the provider. */
    toUntrack: number[];
}

/** What submitting the current selection does. Locked rows never untrack. */
export function importPlan(records: RemoteRecord[], selected: Set<string>, canUntrack = true): ImportPlan {
    const toImport: RemoteRecord[] = [];
    const toUntrack: number[] = [];

    records.forEach((record) => {
        const state = rowState(record, canUntrack);
        const ticked = selected.has(recordKey(record));

        if (state === 'selectable' && ticked) toImport.push(record);
        if (state === 'tracked' && !ticked && record.entryId !== null) toUntrack.push(record.entryId);
    });

    return { toImport, toUntrack: [...new Set(toUntrack)] };
}

const plural = (count: number) => `${count} record${count === 1 ? '' : 's'}`;

/** Submit-button label: "Import 3 records", "Stop tracking 2 records", or "Import 3 · Stop tracking 2". */
export function submitLabel({ toImport, toUntrack }: ImportPlan): string {
    if (toImport.length > 0 && toUntrack.length > 0) return `Import ${toImport.length} · Stop tracking ${toUntrack.length}`;
    if (toUntrack.length > 0) return `Stop tracking ${plural(toUntrack.length)}`;

    return toImport.length > 0 ? `Import ${plural(toImport.length)}` : 'Import';
}
