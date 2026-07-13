export const RECORD_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'NS', 'CAA', 'PTR'] as const;
export type RecordType = (typeof RECORD_TYPES)[number];

export const SYNC_STATUSES = ['synced', 'pending', 'drifted', 'error', 'deleting'] as const;
export type SyncStatus = (typeof SYNC_STATUSES)[number];

export interface SyncStateItem {
    id: number;
    provider: { id: number; name: string; type: string };
    status: SyncStatus;
    lastSyncedAt: string | null;
    lastError: string | null;
}

export interface EntryItem {
    id: number;
    name: string;
    type: string;
    content: string;
    ttl: number | null;
    priority: number | null;
    proxied: boolean;
    comment: string | null;
    updatedAt: string;
    syncStates: SyncStateItem[];
}

export interface ProviderInfo {
    id: number;
    name: string;
    type: string;
    enabled: boolean;
    managedRecordTypes: string[];
}

export interface ConnectorInfo {
    type: string;
    displayName: string;
    supportedRecordTypes: string[];
    configSchema: unknown[];
    capabilities: {
        supportsProxied: boolean;
        supportsTtl: boolean;
        supportsPriority: boolean;
        minTtl: number | null;
        maxTtl: number | null;
    };
}

export interface EntryFilters {
    search?: string;
    type?: string;
    provider?: string;
    status?: string;
}

export interface PaginatorLink {
    url: string | null;
    label: string;
    active: boolean;
}

export interface PaginatedEntries {
    data: EntryItem[];
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
    links: PaginatorLink[];
}

export interface EntriesPageProps {
    entries: PaginatedEntries;
    filters: EntryFilters;
    providers: ProviderInfo[];
    connectors: ConnectorInfo[];
}

export interface ImportResult {
    imported: number;
    skipped: number;
    failed: Array<{ line: number; message: string }>;
}
