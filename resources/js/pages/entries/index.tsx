import { EntriesView } from '@/components/entries/entries-view';
import type { ConnectorInfo, EntryFilters, PaginatedEntries, ZoneAttachment, ZoneOption } from '@/components/entries/types';
import { FlashToast } from '@/components/flash-toast';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type ZoneCanMap } from '@/types';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'DNS Entries',
        href: '/entries',
    },
];

interface EntriesPageProps {
    entries: PaginatedEntries;
    filters: EntryFilters;
    zones: ZoneOption[];
    zoneAttachments: Record<number, ZoneAttachment[]>;
    connectors: ConnectorInfo[];
    /** Per-zone abilities keyed by zone id — covers exactly the zones in `zones`. */
    zoneCan: ZoneCanMap;
}

export default function EntriesIndex({ entries, filters, zones, zoneAttachments, connectors, zoneCan }: EntriesPageProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="DNS Entries" />
            <EntriesView
                scope={{ baseUrl: '/entries' }}
                entries={entries}
                filters={filters}
                zones={zones}
                zoneAttachments={zoneAttachments}
                connectors={connectors}
                zoneCan={zoneCan}
                header={
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">DNS Entries</h1>
                        <p className="text-muted-foreground text-sm">Manage records and keep them in sync across providers.</p>
                    </div>
                }
            />
            <FlashToast />
        </AppLayout>
    );
}
