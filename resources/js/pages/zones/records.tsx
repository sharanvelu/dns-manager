import { EntriesView } from '@/components/entries/entries-view';
import { FlashToast } from '@/components/flash-toast';
import { StatusDriftedIcon, StatusErrorIcon, StatusSyncedIcon } from '@/components/icons';
import { StatTile } from '@/components/stat-tile';
import { Button } from '@/components/ui/button';
import { ZoneTabs } from '@/components/zone-tabs';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Globe, RefreshCw } from 'lucide-react';
import type { ZoneRecordsProps } from './types';

export default function ZoneRecords({ zone, zoneCan, stats, entries, filters, zones, zoneAttachments, connectors }: ZoneRecordsProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Zones', href: '/zones' },
        { title: zone.name, href: `/zones/${zone.id}` },
        { title: 'Records', href: `/zones/${zone.id}/records` },
    ];

    const fullySynced = stats.entriesCount > 0 && stats.inSync === stats.entriesCount;

    const syncDrifted = () => {
        router.post(route('zones.sync-drifted', zone.id), {}, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Records — ${zone.name}`} />

            <div className="flex h-full flex-1 flex-col">
                <div className="flex flex-col gap-6 px-4 pt-4 md:px-6 md:pt-6">
                    <ZoneTabs zone={zone} zoneCan={zoneCan} entriesCount={stats.entriesCount} />

                    <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <StatTile label="Records" value={stats.entriesCount} icon={Globe} />
                        <StatTile label="Fully in sync" value={stats.inSync} icon={StatusSyncedIcon} accent={fullySynced ? 'green' : 'neutral'} />
                        <StatTile
                            label="Drifted"
                            value={stats.drifted}
                            icon={StatusDriftedIcon}
                            accent={stats.drifted > 0 ? 'amber' : 'neutral'}
                            action={
                                zoneCan.manageRecords && stats.drifted > 0 ? (
                                    <Button variant="outline" size="sm" className="h-7 px-2 text-xs" onClick={syncDrifted}>
                                        <RefreshCw className="size-3.5" />
                                        Sync
                                    </Button>
                                ) : undefined
                            }
                        />
                        <StatTile label="Errors" value={stats.errored} icon={StatusErrorIcon} accent={stats.errored > 0 ? 'red' : 'neutral'} />
                    </div>
                </div>
                <EntriesView
                    scope={{ baseUrl: `/zones/${zone.id}/records`, zone: { id: zone.id, name: zone.name } }}
                    entries={entries}
                    filters={filters}
                    zones={zones}
                    zoneAttachments={zoneAttachments}
                    connectors={connectors}
                    zoneCan={{ [zone.id]: { manageRecords: zoneCan.manageRecords, viewActivity: zoneCan.viewActivity } }}
                />
            </div>
            <FlashToast />
        </AppLayout>
    );
}
