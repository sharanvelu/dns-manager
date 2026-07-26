import { defaultConfigFor } from '@/components/config-fields';
import { ProviderCloudflareMark, ProviderGenericMark, ProviderPiholeMark, ProviderTechnitiumMark } from '@/components/icons';
import type { FormDataConvertible } from '@inertiajs/core';
import type { ComponentType, SVGProps } from 'react';
import type { Connector, Provider } from './types';

/** Map a provider type to its mark icon component. */
export function providerMark(type: string): ComponentType<SVGProps<SVGSVGElement>> {
    switch (type) {
        case 'cloudflare':
            return ProviderCloudflareMark;
        case 'pihole':
            return ProviderPiholeMark;
        case 'technitium':
            return ProviderTechnitiumMark;
        default:
            return ProviderGenericMark;
    }
}

/** Tiny relative-time formatter for ISO timestamps ("just now", "5m ago", ...). */
export function relativeTime(iso: string | null): string | null {
    if (!iso) {
        return null;
    }

    const then = new Date(iso).getTime();

    if (Number.isNaN(then)) {
        return null;
    }

    const seconds = Math.round((Date.now() - then) / 1000);

    if (seconds < 45) return 'just now';
    if (seconds < 90) return 'a minute ago';

    const minutes = Math.round(seconds / 60);
    if (minutes < 60) return `${minutes}m ago`;

    const hours = Math.round(minutes / 60);
    if (hours < 24) return `${hours}h ago`;

    const days = Math.round(hours / 24);
    if (days < 30) return `${days}d ago`;

    return new Date(iso).toLocaleDateString();
}

/** Build the default (empty) config for a connector from its schema defaults. */
export function defaultConfig(connector: Connector | undefined): Record<string, FormDataConvertible> {
    return defaultConfigFor(connector?.configSchema ?? []);
}

/** The full update payload for a provider as it exists today (secrets blank → kept server-side). */
export function providerPayload(provider: Provider) {
    return {
        name: provider.name,
        type: provider.type,
        enabled: provider.enabled,
        managed_record_types: provider.managedRecordTypes,
        // The backend only ever sends scalar config values; secrets arrive as ''.
        config: provider.config as Record<string, FormDataConvertible>,
    };
}
