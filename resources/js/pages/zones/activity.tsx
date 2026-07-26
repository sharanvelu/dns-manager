import { ActivityTable } from '@/components/activity/activity-table';
import { FlashToast } from '@/components/flash-toast';
import { ZoneTabs } from '@/components/zone-tabs';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import type { ZoneActivityProps } from './types';

export default function ZoneActivity({ zone, zoneCan, activities, filters, users, events }: ZoneActivityProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Zones', href: '/zones' },
        { title: zone.name, href: `/zones/${zone.id}` },
        { title: 'Activity', href: `/zones/${zone.id}/activity` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Activity — ${zone.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <ZoneTabs zone={zone} zoneCan={zoneCan} />

                <ActivityTable
                    activities={activities}
                    filters={filters}
                    users={users}
                    events={events}
                    baseUrl={`/zones/${zone.id}/activity`}
                    hideSubjectTypeFilter
                />
            </div>
            <FlashToast />
        </AppLayout>
    );
}
