import { ActivityTable } from '@/components/activity/activity-table';
import { type ActivityFilters, type ActivityItem, type ActivityMeta, type ActivitySubjectChip } from '@/components/activity/types';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

interface ActivityPageProps {
    activities: { data: ActivityItem[]; meta: ActivityMeta };
    filters: ActivityFilters;
    users: Array<{ id: number; name: string }>;
    events: string[];
    subject: ActivitySubjectChip | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Activity',
        href: '/activity',
    },
];

export default function Activity({ activities, filters, users, events, subject }: ActivityPageProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Activity" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold tracking-tight">Activity</h1>
                    <p className="text-muted-foreground text-sm">Audit trail of changes to entries, zones, providers, and users.</p>
                </div>

                <ActivityTable activities={activities} filters={filters} users={users} events={events} baseUrl="/activity" subjectChip={subject} />
            </div>
        </AppLayout>
    );
}
