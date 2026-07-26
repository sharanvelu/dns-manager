import type { ConfigField } from '@/components/config-fields';

export type { ConfigField };

export interface ProviderZoneAttachment {
    zoneProviderId: number;
    zoneId: number;
    zoneName: string;
    enabled: boolean;
}

export interface Provider {
    id: number;
    name: string;
    type: string;
    typeLabel: string;
    enabled: boolean;
    healthStatus: 'ok' | 'error' | 'unchecked';
    healthMessage: string | null;
    lastCheckedAt: string | null;
    managedRecordTypes: string[];
    recordsCount: number;
    syncedCount: number;
    zones: ProviderZoneAttachment[];
    /** Secrets come back as '' (empty string). */
    config: Record<string, unknown>;
}

export interface Connector {
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
    };
}

export interface ZoneOption {
    id: number;
    name: string;
}

export interface ProvidersPageProps {
    providers: Provider[];
    connectors: Connector[];
    allZones: ZoneOption[];
}

export interface TestResult {
    ok: boolean;
    message: string;
    details: Record<string, unknown>;
}
