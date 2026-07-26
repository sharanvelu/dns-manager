import { ActivityLogDialog } from '@/components/activity-log-dialog';
import { FlashToast } from '@/components/flash-toast';
import {
    EmptyZonesIllustration,
    ProviderCloudflareMark,
    ProviderGenericMark,
    ProviderPiholeMark,
    ProviderTechnitiumMark,
    StatusDriftedIcon,
    StatusErrorIcon,
    StatusSyncedIcon,
    ZoneMark,
} from '@/components/icons';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { ZoneDeleteDialog, type DeletableZone } from '@/components/zones/zone-delete-dialog';
import { ZoneFormDialog } from '@/components/zones/zone-form-dialog';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { ExternalLink, History, MoreHorizontal, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState, type ComponentType, type SVGProps } from 'react';
import type { ZoneListItem, ZonesIndexProps } from './types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Zones',
        href: '/zones',
    },
];

const providerMarks: Record<string, ComponentType<SVGProps<SVGSVGElement>>> = {
    cloudflare: ProviderCloudflareMark,
    pihole: ProviderPiholeMark,
    technitium: ProviderTechnitiumMark,
};

function StatusRollup({ zone }: { zone: ZoneListItem }) {
    const allInSync = zone.entriesCount > 0 && zone.syncedCount === zone.entriesCount;

    if (!allInSync && zone.driftedCount === 0 && zone.erroredCount === 0) {
        return null;
    }

    return (
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs tabular-nums">
            {allInSync && (
                <span className="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400">
                    <StatusSyncedIcon className="size-3.5" />
                    All in sync
                </span>
            )}
            {zone.driftedCount > 0 && (
                <span className="inline-flex items-center gap-1 text-amber-600 dark:text-amber-400">
                    <StatusDriftedIcon className="size-3.5" />
                    {zone.driftedCount} drifted
                </span>
            )}
            {zone.erroredCount > 0 && (
                <span className="inline-flex items-center gap-1 text-red-600 dark:text-red-400">
                    <StatusErrorIcon className="size-3.5" />
                    {zone.erroredCount} {zone.erroredCount === 1 ? 'error' : 'errors'}
                </span>
            )}
        </div>
    );
}

export default function ZonesIndex({ zones, providers, zonelessProviders }: ZonesIndexProps) {
    const can = usePage<SharedData>().props.auth.can;

    const [formOpen, setFormOpen] = useState(false);
    const [editingZone, setEditingZone] = useState<ZoneListItem | null>(null);
    const [deletingZone, setDeletingZone] = useState<DeletableZone | null>(null);
    const [activityZone, setActivityZone] = useState<ZoneListItem | null>(null);

    const openCreate = () => {
        setEditingZone(null);
        setFormOpen(true);
    };

    const openEdit = (zone: ZoneListItem) => {
        setEditingZone(zone);
        setFormOpen(true);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Zones" />

            <TooltipProvider delayDuration={200}>
                <div className="flex h-full flex-1 flex-col gap-6 p-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h1 className="text-xl font-semibold tracking-tight">Zones</h1>
                            <p className="text-muted-foreground text-sm">DNS zones grouping your entries, each pushed to its attached providers.</p>
                        </div>
                        {can.createZones && (
                            <Button onClick={openCreate}>
                                <Plus className="size-4" />
                                Add zone
                            </Button>
                        )}
                    </div>

                    {zones.length === 0 ? (
                        <div className="flex flex-1 flex-col items-center justify-center gap-4 rounded-xl border border-dashed py-16 text-center">
                            <EmptyZonesIllustration className="text-muted-foreground" />
                            <div>
                                <p className="text-sm font-medium">No zones yet</p>
                                <p className="text-muted-foreground mx-auto mt-1 max-w-sm text-sm">
                                    Create a zone like example.com to start organizing your DNS entries.
                                </p>
                            </div>
                            {can.createZones && (
                                <Button onClick={openCreate}>
                                    <Plus className="size-4" />
                                    Create your first zone
                                </Button>
                            )}
                        </div>
                    ) : (
                        <Card className="divide-border divide-y overflow-hidden py-0">
                            {zones.map((zone) => (
                                <div key={zone.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                    <div className="flex min-w-0 items-center gap-3">
                                        <span className="bg-muted/40 text-muted-foreground flex size-9 shrink-0 items-center justify-center rounded-md border">
                                            <ZoneMark className="size-5" />
                                        </span>
                                        <div className="min-w-0">
                                            <Link
                                                href={`/zones/${zone.id}/records`}
                                                className="block truncate font-mono text-sm font-medium hover:underline hover:underline-offset-4"
                                            >
                                                {zone.name}
                                            </Link>
                                            {zone.description ? (
                                                <p className="text-muted-foreground truncate text-xs">{zone.description}</p>
                                            ) : (
                                                <StatusRollup zone={zone} />
                                            )}
                                        </div>
                                    </div>

                                    <div className="flex shrink-0 items-center gap-4">
                                        {zone.description && <StatusRollup zone={zone} />}
                                        <span className="text-muted-foreground text-sm tabular-nums">
                                            {zone.entriesCount} {zone.entriesCount === 1 ? 'record' : 'records'}
                                        </span>
                                        <span
                                            className={cn('text-muted-foreground flex items-center gap-1.5', zone.providers.length === 0 && 'hidden')}
                                        >
                                            {zone.providers.map((attachment) => {
                                                const Mark = providerMarks[attachment.type] ?? ProviderGenericMark;

                                                return (
                                                    <Tooltip key={attachment.id}>
                                                        <TooltipTrigger asChild>
                                                            <span className={cn('cursor-default', !attachment.enabled && 'opacity-40')}>
                                                                <Mark className="size-4" />
                                                            </span>
                                                        </TooltipTrigger>
                                                        <TooltipContent>
                                                            {attachment.name}
                                                            {!attachment.enabled && ' (disabled)'}
                                                        </TooltipContent>
                                                    </Tooltip>
                                                );
                                            })}
                                        </span>

                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="text-muted-foreground size-8"
                                                    aria-label={`Actions for ${zone.name}`}
                                                >
                                                    <MoreHorizontal className="size-4" />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end" className="w-40">
                                                <DropdownMenuItem asChild>
                                                    <Link href={`/zones/${zone.id}/records`}>
                                                        <ExternalLink />
                                                        Open
                                                    </Link>
                                                </DropdownMenuItem>
                                                {can.createZones && (
                                                    <DropdownMenuItem onSelect={() => openEdit(zone)}>
                                                        <Pencil />
                                                        Edit
                                                    </DropdownMenuItem>
                                                )}
                                                {can.viewGlobalActivity && (
                                                    <DropdownMenuItem onSelect={() => setActivityZone(zone)}>
                                                        <History />
                                                        Activity
                                                    </DropdownMenuItem>
                                                )}
                                                {can.createZones && (
                                                    <>
                                                        <DropdownMenuSeparator />
                                                        <DropdownMenuItem
                                                            onSelect={() => setDeletingZone(zone)}
                                                            className="text-red-600 focus:text-red-600 dark:text-red-400 dark:focus:text-red-400"
                                                        >
                                                            <Trash2 />
                                                            Delete
                                                        </DropdownMenuItem>
                                                    </>
                                                )}
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </div>
                                </div>
                            ))}
                        </Card>
                    )}
                </div>
            </TooltipProvider>

            <ZoneFormDialog
                open={formOpen}
                onOpenChange={setFormOpen}
                zone={editingZone}
                zonelessProviders={zonelessProviders}
                hasProviders={providers.length > 0}
            />
            <ZoneDeleteDialog zone={deletingZone} onOpenChange={(open) => !open && setDeletingZone(null)} />
            {activityZone && (
                <ActivityLogDialog
                    open
                    onOpenChange={(open) => !open && setActivityZone(null)}
                    subjectType="zone"
                    subjectId={activityZone.id}
                    subjectLabel={activityZone.name}
                />
            )}
            <FlashToast />
        </AppLayout>
    );
}
