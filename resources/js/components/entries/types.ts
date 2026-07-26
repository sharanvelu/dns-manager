export const RECORD_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'NS', 'CAA', 'PTR'] as const;
export type RecordType = (typeof RECORD_TYPES)[number];

export const SYNC_STATUSES = ['synced', 'pending', 'drifted', 'error', 'deleting'] as const;
export type SyncStatus = (typeof SYNC_STATUSES)[number];

export interface SyncStateItem {
    id: number;
    zoneProviderId: number;
    providerId: number | null;
    providerName: string | null;
    providerType: string | null;
    status: SyncStatus;
    lastSyncedAt: string | null;
    lastError: string | null;
}

export interface ZoneOption {
    id: number;
    name: string;
}

export interface EntryItem {
    id: number;
    zone: ZoneOption;
    /** Zone-relative name: '@' (apex), 'www', '*.app', … */
    name: string;
    fqdn: string;
    type: string;
    content: string;
    ttl: number | null;
    priority: number | null;
    proxied: boolean;
    comment: string | null;
    updatedAt: string;
    syncStates: SyncStateItem[];
}

/** A zone↔provider attachment — the sync targets offered for a zone's entries. */
export interface ZoneAttachment {
    id: number;
    providerId: number;
    providerName: string;
    providerType: string;
    /** Attachment enabled AND provider enabled. */
    enabled: boolean;
    managedRecordTypes: string[];
}

export interface ConnectorInfo {
    type: string;
    displayName: string;
    supportedRecordTypes: string[];
    configSchema: unknown[];
    zoneConfigSchema: unknown[];
    capabilities: {
        supportsProxied: boolean;
        supportsTtl: boolean;
        supportsPriority: boolean;
        supportsZones: boolean;
        minTtl: number | null;
        maxTtl: number | null;
    };
}

export type SortColumn = 'name' | 'zone' | 'type' | 'content' | 'ttl' | 'updated';
export type SortDirection = 'asc' | 'desc';

export interface EntryFilters {
    search?: string;
    type?: string;
    provider?: string;
    status?: string;
    /** Zone id as a string — only meaningful on the global entries page. */
    zone?: string;
    sort?: SortColumn;
    direction?: SortDirection;
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

/** Where the entries view lives — the global page or a single zone's records page. */
export interface EntriesScope {
    /** '/entries' or `/zones/${id}/records` — every filter/sort/pagination request targets this. */
    baseUrl: string;
    /** Set ⇒ zone-locked mode: no zone column/filter, create dialog pins the zone. */
    zone?: ZoneOption;
}

export interface ImportResult {
    imported: number;
    skipped: number;
    failed: Array<{ line: number; message: string }>;
}
