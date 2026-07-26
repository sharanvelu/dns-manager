import { ProviderCloudflareMark, ProviderGenericMark, ProviderPiholeMark, ProviderTechnitiumMark } from '@/components/icons';

/** Tiny relative-time formatter for ISO 8601 timestamps ("3m ago", "2d ago"). */
export function relativeTime(iso: string): string {
    const seconds = Math.round((Date.now() - new Date(iso).getTime()) / 1000);

    if (seconds < 45) return 'just now';
    if (seconds < 3600) return `${Math.max(1, Math.round(seconds / 60))}m ago`;
    if (seconds < 86400) return `${Math.round(seconds / 3600)}h ago`;
    if (seconds < 30 * 86400) return `${Math.round(seconds / 86400)}d ago`;

    return new Date(iso).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

/** Provider mark keyed by connector type (cloudflare, pihole, ... → generic). */
export function ProviderMark({ type, className }: { type: string | null; className?: string }) {
    switch (type) {
        case 'cloudflare':
            return <ProviderCloudflareMark className={className} />;
        case 'pihole':
            return <ProviderPiholeMark className={className} />;
        case 'technitium':
            return <ProviderTechnitiumMark className={className} />;
        default:
            return <ProviderGenericMark className={className} />;
    }
}
