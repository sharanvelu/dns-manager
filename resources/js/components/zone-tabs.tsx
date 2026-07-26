import { ActivityLogDialog } from '@/components/activity-log-dialog';
import { ZoneMark } from '@/components/icons';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { ZoneDeleteDialog, type DeletableZone } from '@/components/zones/zone-delete-dialog';
import { ZoneFormDialog } from '@/components/zones/zone-form-dialog';
import { cn } from '@/lib/utils';
import { type SharedData, type ZoneCan } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { History, LoaderCircle, MoreHorizontal, Pencil, RefreshCw, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface ZoneTabsProps {
    zone: { id: number; name: string; description: string | null };
    /** Per-zone abilities for this zone, passed from the page props. */
    zoneCan: ZoneCan;
    /** Enables the record count in the delete confirmation copy. */
    entriesCount?: number;
}

/**
 * Shared header for the zone pages: identity block, Records / Providers /
 * Activity / Access tab pills, and the Sync-all + kebab actions.
 */
export function ZoneTabs({ zone, zoneCan, entriesCount }: ZoneTabsProps) {
    const { url } = usePage<SharedData>();

    const [editOpen, setEditOpen] = useState(false);
    const [activityOpen, setActivityOpen] = useState(false);
    const [deleting, setDeleting] = useState<DeletableZone | null>(null);
    const [syncing, setSyncing] = useState(false);

    const path = url.split('?')[0];
    const base = `/zones/${zone.id}`;

    // A user-admin may land on the Access tab without being able to open the
    // zone itself — never offer tabs that would 403.
    const tabs = [
        ...(zoneCan.viewZone
            ? [
                  { label: 'Records', href: `${base}/records`, active: path.startsWith(`${base}/records`) },
                  { label: 'Providers', href: `${base}/providers`, active: path.startsWith(`${base}/providers`) },
              ]
            : []),
        ...(zoneCan.viewActivity ? [{ label: 'Activity', href: `${base}/activity`, active: path.startsWith(`${base}/activity`) }] : []),
        ...(zoneCan.viewAccess ? [{ label: 'Access', href: `${base}/access`, active: path.startsWith(`${base}/access`) }] : []),
    ];

    const syncAll = () => {
        router.post(
            route('zones.sync', zone.id),
            {},
            {
                preserveScroll: true,
                onStart: () => setSyncing(true),
                onFinish: () => setSyncing(false),
            },
        );
    };

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex min-w-0 items-center gap-3">
                    <span className="bg-muted/40 text-muted-foreground flex size-10 shrink-0 items-center justify-center rounded-md border">
                        <ZoneMark className="size-5" />
                    </span>
                    <div className="min-w-0">
                        <h1 className="truncate font-mono text-lg font-semibold">{zone.name}</h1>
                        {zone.description && <p className="text-muted-foreground truncate text-sm">{zone.description}</p>}
                    </div>
                </div>

                <div className="flex shrink-0 items-center gap-2">
                    {zoneCan.manageRecords && (
                        <Button variant="outline" size="sm" onClick={syncAll} disabled={syncing}>
                            {syncing ? <LoaderCircle className="size-4 animate-spin" /> : <RefreshCw className="size-4" />}
                            Sync all
                        </Button>
                    )}
                    {(zoneCan.updateZone || zoneCan.deleteZone || zoneCan.viewActivity) && (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="ghost" size="icon" className="size-8" aria-label="Zone actions">
                                    <MoreHorizontal className="size-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                {zoneCan.updateZone && (
                                    <DropdownMenuItem onSelect={() => setEditOpen(true)}>
                                        <Pencil className="size-4" />
                                        Edit zone
                                    </DropdownMenuItem>
                                )}
                                {zoneCan.viewActivity && (
                                    <DropdownMenuItem onSelect={() => setActivityOpen(true)}>
                                        <History className="size-4" />
                                        Activity
                                    </DropdownMenuItem>
                                )}
                                {zoneCan.deleteZone && (
                                    <>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            onSelect={() => setDeleting({ ...zone, entriesCount })}
                                            className="text-red-600 focus:text-red-600 dark:text-red-400 dark:focus:text-red-400"
                                        >
                                            <Trash2 className="size-4" />
                                            Delete zone
                                        </DropdownMenuItem>
                                    </>
                                )}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}
                </div>
            </div>

            <nav className="flex items-center gap-1" aria-label="Zone sections">
                {tabs.map((tab) => (
                    <Link
                        key={tab.href}
                        href={tab.href}
                        aria-current={tab.active ? 'page' : undefined}
                        className={cn(
                            'rounded-full px-3 py-1.5 text-sm font-medium transition-colors',
                            tab.active ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground hover:bg-muted/60',
                        )}
                    >
                        {tab.label}
                    </Link>
                ))}
            </nav>

            <ZoneFormDialog open={editOpen} onOpenChange={setEditOpen} zone={zone} />
            <ZoneDeleteDialog zone={deleting} onOpenChange={(open) => !open && setDeleting(null)} />
            <ActivityLogDialog
                open={activityOpen}
                onOpenChange={setActivityOpen}
                subjectType="zone"
                subjectId={zone.id}
                subjectLabel={zone.name}
                dataUrl={`/zones/${zone.id}/activity/data`}
            />
        </div>
    );
}
