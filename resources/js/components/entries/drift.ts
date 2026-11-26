import type { DriftDetail, EntryItem, SyncStateItem } from './types';

export function driftFieldLabel(field: string): string {
    return field === 'ttl' ? 'TTL' : field;
}

export function formatDriftValue(detail: DriftDetail, value: DriftDetail['tracked']): string {
    if (value === null) return detail.field === 'ttl' ? 'Auto' : '—';
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    return String(value);
}

/**
 * Drifted states whose provider values can be accepted: the record still
 * exists remotely (a missing record records no drift details).
 */
export function acceptableDriftStates(entry: EntryItem): SyncStateItem[] {
    return entry.syncStates.filter((state) => state.status === 'drifted' && (state.driftDetails?.length ?? 0) > 0);
}
