import type { ActivityFilters, ActivityItem, ActivityMeta } from '@/components/activity/types';
import type { ConfigField } from '@/components/config-fields';
import type { EntryFilters, PaginatedEntries, ZoneAttachment } from '@/components/entries/types';
import type { GrantDialogUser, ZoneRoleOption } from '@/components/users/grant-dialog';
import type { ZoneCan } from '@/types';

/** Header/identity slice of a zone shared by every zone page. */
export interface ZoneSummary {
    id: number;
    name: string;
    description: string | null;
}

export interface ZoneProviderSummary {
    id: number;
    providerId: number;
    name: string;
    type: string;
    enabled: boolean;
}

export interface ZoneListItem extends ZoneSummary {
    entriesCount: number;
    syncedCount: number;
    driftedCount: number;
    erroredCount: number;
    providers: ZoneProviderSummary[];
    createdAt: string | null;
}

export interface ProviderListOption {
    id: number;
    name: string;
    type: string;
    enabled: boolean;
    supportsZones: boolean;
}

export interface ZonesIndexProps {
    zones: ZoneListItem[];
    providers: ProviderListOption[];
    /** Enabled zoneless providers — auto-attached to every new zone. */
    zonelessProviders: string[];
}

/** Full connector descriptor as serialized by ConnectorRegistry::descriptors(). */
export interface ZoneConnector {
    type: string;
    displayName: string;
    supportedRecordTypes: string[];
    configSchema: ConfigField[];
    zoneConfigSchema: ConfigField[];
    capabilities: {
        supportsProxied: boolean;
        supportsTtl: boolean;
        supportsPriority: boolean;
        supportsZones: boolean;
        minTtl: number | null;
        maxTtl: number | null;
        defaultTtl: number | null;
    };
}

export interface ZoneStats {
    entriesCount: number;
    inSync: number;
    drifted: number;
    errored: number;
}

export interface ZoneAttachmentDetail {
    id: number;
    providerId: number;
    providerName: string;
    providerType: string;
    providerEnabled: boolean;
    enabled: boolean;
    healthStatus: 'ok' | 'error' | 'unchecked';
    healthMessage: string | null;
    /** Non-secret zoneConfigSchema values only (secrets arrive as ''). */
    zoneConfig: Record<string, string | boolean>;
    supportsZones: boolean;
    recordsCount: number;
    syncedCount: number;
    driftedCount: number;
    errorCount: number;
}

export interface AvailableProvider {
    id: number;
    name: string;
    type: string;
    enabled: boolean;
}

export interface ZoneProvidersProps {
    zone: ZoneSummary;
    zoneCan: ZoneCan;
    attachments: ZoneAttachmentDetail[];
    /** Attach-dialog fodder — empty unless the user manages attachments. */
    availableProviders: AvailableProvider[];
    connectors: ZoneConnector[];
}

export interface ZoneRecordsProps {
    zone: ZoneSummary;
    zoneCan: ZoneCan;
    stats: ZoneStats;
    entries: PaginatedEntries;
    filters: EntryFilters;
    zones: Array<{ id: number; name: string }>;
    zoneAttachments: Record<number, ZoneAttachment[]>;
    connectors: ZoneConnector[];
}

export interface ZoneActivityProps {
    zone: ZoneSummary;
    zoneCan: ZoneCan;
    activities: { data: ActivityItem[]; meta: ActivityMeta };
    filters: ActivityFilters;
    users: Array<{ id: number; name: string }>;
    events: string[];
}

export interface ZoneAccessGrant {
    userId: number;
    userName: string;
    userEmail: string;
    userAvatarUrl: string | null;
    roles: string[];
    createdAt: string | null;
}

export interface ZoneAccessProps {
    zone: ZoneSummary;
    zoneCan: ZoneCan;
    grants: ZoneAccessGrant[];
    zoneRoleOptions: ZoneRoleOption[];
    /** Users without a grant on this zone — empty for read-only visitors. */
    grantableUsers: GrantDialogUser[];
    /** Only Super Admins and User Admins may touch Zone Admin grants. */
    canGrantZoneAdmin: boolean;
}
