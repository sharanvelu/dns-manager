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
    /** Secrets come back as '' (empty string). */
    config: Record<string, unknown>;
}

export interface ConfigField {
    key: string;
    label: string;
    type: 'text' | 'password' | 'url' | 'boolean';
    secret: boolean;
    required: boolean;
    help: string | null;
    default: unknown;
}

export interface Connector {
    type: string;
    displayName: string;
    supportedRecordTypes: string[];
    configSchema: ConfigField[];
    capabilities: {
        supportsProxied: boolean;
        supportsTtl: boolean;
        supportsPriority: boolean;
        minTtl: number | null;
        maxTtl: number | null;
    };
}

export interface ProvidersPageProps {
    providers: Provider[];
    connectors: Connector[];
}

export interface TestResult {
    ok: boolean;
    message: string;
    details: Record<string, unknown>;
}
