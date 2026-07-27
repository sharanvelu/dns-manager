import { ActivityLogDialog } from '@/components/activity-log-dialog';
import { RecordTypeBadge, StatusDeletingIcon, StatusDriftedIcon, StatusErrorIcon, StatusPendingIcon, StatusSyncedIcon } from '@/components/icons';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import { Cloud, History, MoreHorizontal, Pencil, RefreshCw, Trash2 } from 'lucide-react';
import { useState, type ComponentType, type SVGProps } from 'react';
import { relativeTime } from './helpers';
import type { DriftDetail, EntryItem, SyncStateItem, SyncStatus } from './types';

const statusConfig: Record<SyncStatus, { icon: ComponentType<SVGProps<SVGSVGElement>>; iconClass: string; nameClass?: string; label: string }> = {
    synced: { icon: StatusSyncedIcon, iconClass: 'text-emerald-600 dark:text-emerald-400', label: 'Synced' },
    pending: { icon: StatusPendingIcon, iconClass: 'animate-pulse text-muted-foreground', label: 'Pending' },
    drifted: { icon: StatusDriftedIcon, iconClass: 'text-amber-600 dark:text-amber-400', label: 'Drifted' },
    error: { icon: StatusErrorIcon, iconClass: 'text-red-600 dark:text-red-400', label: 'Error' },
    deleting: { icon: StatusDeletingIcon, iconClass: 'text-muted-foreground', nameClass: 'line-through', label: 'Deleting' },
};

function formatDriftValue(detail: DriftDetail, value: DriftDetail['tracked']): string {
    if (value === null) return detail.field === 'ttl' ? 'Auto' : '—';
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    return String(value);
}

function SyncStateChip({ state }: { state: SyncStateItem }) {
    const config = statusConfig[state.status];
    const Icon = config.icon;
    const providerName = state.providerName ?? 'Unknown provider';

    const chip = (
        <span
            className={cn(
                'bg-background text-muted-foreground inline-flex max-w-40 items-center gap-1 rounded-full border px-2 py-0.5 text-[11px]',
                (state.status === 'error' || state.status === 'drifted') && 'cursor-help',
            )}
        >
            <Icon className={cn('size-3 shrink-0', config.iconClass)} />
            <span className={cn('truncate', config.nameClass)}>{providerName}</span>
        </span>
    );

    if (state.status === 'error' || state.status === 'drifted') {
        return (
            <Tooltip>
                <TooltipTrigger asChild>{chip}</TooltipTrigger>
                <TooltipContent className="max-w-xs">
                    <p className="font-medium">
                        {providerName} — {config.label.toLowerCase()}
                    </p>
                    <p className="text-muted-foreground mt-0.5 text-xs break-words">
                        {state.lastError ?? (state.status === 'drifted' ? 'Remote record no longer matches this entry.' : 'Sync failed.')}
                    </p>
                    {state.status === 'drifted' && state.driftDetails && state.driftDetails.length > 0 && (
                        <div className="border-border/50 mt-1.5 flex flex-col gap-1 border-t pt-1.5">
                            {state.driftDetails.map((detail) => (
                                <div key={detail.field} className="text-xs">
                                    <span className="font-medium capitalize">{detail.field === 'ttl' ? 'TTL' : detail.field}</span>
                                    <span className="text-muted-foreground block font-mono break-all">
                                        tracked: {formatDriftValue(detail, detail.tracked)}
                                    </span>
                                    <span className="text-muted-foreground block font-mono break-all">
                                        actual: {formatDriftValue(detail, detail.actual)}
                                    </span>
                                </div>
                            ))}
                        </div>
                    )}
                </TooltipContent>
            </Tooltip>
        );
    }

    return chip;
}

interface EntryRowProps {
    /** The user can manage records in THIS entry's zone. */
    canManage: boolean;
    /** The user can view activity for THIS entry's zone. */
    canViewActivity: boolean;
    /** The selection column is rendered (some zone on the page is manageable). */
    showSelection: boolean;
    entry: EntryItem;
    /** Global mode: render the Zone column (zone-scoped pages omit it). */
    showZone: boolean;
    selected: boolean;
    onSelect: (entry: EntryItem, checked: boolean) => void;
    onEdit: (entry: EntryItem) => void;
    onDelete: (entry: EntryItem) => void;
}

export function EntryRow({ entry, canManage, canViewActivity, showSelection, showZone, selected, onSelect, onEdit, onDelete }: EntryRowProps) {
    const [viewingActivity, setViewingActivity] = useState(false);

    const syncNow = () => {
        router.post(route('entries.sync', entry.id), {}, { preserveScroll: true });
    };

    return (
        <tr className={cn('hover:bg-muted/40 border-b transition-colors last:border-b-0', selected && 'bg-primary/5')}>
            {showSelection && (
                <td className="py-3 pl-4">
                    {canManage && (
                        <Checkbox
                            checked={selected}
                            onCheckedChange={(checked) => onSelect(entry, checked === true)}
                            aria-label={`Select ${entry.fqdn}`}
                        />
                    )}
                </td>
            )}
            <td className="px-4 py-3">
                <span className="inline-flex items-center gap-1.5">
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <span className="font-mono text-[13px] font-medium">{entry.name}</span>
                        </TooltipTrigger>
                        <TooltipContent className="font-mono text-xs">{entry.fqdn}</TooltipContent>
                    </Tooltip>
                    {entry.proxied && (
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <Cloud className="size-3.5 shrink-0 text-orange-500 dark:text-orange-400" aria-label="Proxied" />
                            </TooltipTrigger>
                            <TooltipContent>Proxied through Cloudflare</TooltipContent>
                        </Tooltip>
                    )}
                </span>
            </td>
            {showZone && (
                <td className="px-4 py-3">
                    <Link
                        href={`/zones/${entry.zone.id}/records`}
                        className="text-muted-foreground hover:text-foreground font-mono text-xs underline-offset-4 transition-colors hover:underline"
                    >
                        {entry.zone.name}
                    </Link>
                </td>
            )}
            <td className="px-4 py-3">
                <RecordTypeBadge type={entry.type} />
            </td>
            <td className="max-w-56 px-4 py-3">
                <Tooltip>
                    <TooltipTrigger asChild>
                        <span className="text-muted-foreground block truncate font-mono text-xs">{entry.content}</span>
                    </TooltipTrigger>
                    <TooltipContent className="max-w-sm font-mono text-xs break-all">{entry.content}</TooltipContent>
                </Tooltip>
            </td>
            <td className="text-muted-foreground px-4 py-3 text-xs tabular-nums">{entry.ttl ?? 'Auto'}</td>
            <td className="px-4 py-3">
                {entry.syncStates.length > 0 ? (
                    <div className="flex flex-wrap gap-1">
                        {entry.syncStates.map((state) => (
                            <SyncStateChip key={state.id} state={state} />
                        ))}
                    </div>
                ) : (
                    <span className="text-muted-foreground/60 text-xs">—</span>
                )}
            </td>
            <td className="text-muted-foreground px-4 py-3 text-xs whitespace-nowrap">{relativeTime(entry.updatedAt)}</td>
            <td className="px-2 py-3 text-right">
                {(canManage || canViewActivity) && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="icon" className="text-muted-foreground size-8" aria-label={`Actions for ${entry.fqdn}`}>
                                <MoreHorizontal className="size-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-40">
                            {canManage && (
                                <>
                                    <DropdownMenuItem onSelect={() => onEdit(entry)}>
                                        <Pencil />
                                        Edit
                                    </DropdownMenuItem>
                                    <DropdownMenuItem onSelect={syncNow}>
                                        <RefreshCw />
                                        Sync now
                                    </DropdownMenuItem>
                                </>
                            )}
                            {canViewActivity && (
                                <DropdownMenuItem onSelect={() => setViewingActivity(true)}>
                                    <History />
                                    Activity
                                </DropdownMenuItem>
                            )}
                            {canManage && (
                                <>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        onSelect={() => onDelete(entry)}
                                        className="text-red-600 focus:text-red-600 dark:text-red-400 dark:focus:text-red-400"
                                    >
                                        <Trash2 />
                                        Delete
                                    </DropdownMenuItem>
                                </>
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
                {canViewActivity && (
                    <ActivityLogDialog
                        open={viewingActivity}
                        onOpenChange={setViewingActivity}
                        subjectType="entry"
                        subjectId={entry.id}
                        subjectLabel={entry.fqdn}
                        dataUrl={`/zones/${entry.zone.id}/activity/data`}
                    />
                )}
            </td>
        </tr>
    );
}
