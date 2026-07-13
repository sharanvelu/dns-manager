import { RecordTypeBadge, StatusDeletingIcon, StatusDriftedIcon, StatusErrorIcon, StatusPendingIcon, StatusSyncedIcon } from '@/components/icons';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { Cloud, MoreHorizontal, Pencil, RefreshCw, Trash2 } from 'lucide-react';
import type { ComponentType, SVGProps } from 'react';
import { relativeTime } from './helpers';
import type { EntryItem, SyncStateItem, SyncStatus } from './types';

const statusConfig: Record<
    SyncStatus,
    { icon: ComponentType<SVGProps<SVGSVGElement>>; iconClass: string; nameClass?: string; label: string }
> = {
    synced: { icon: StatusSyncedIcon, iconClass: 'text-emerald-600 dark:text-emerald-400', label: 'Synced' },
    pending: { icon: StatusPendingIcon, iconClass: 'animate-pulse text-muted-foreground', label: 'Pending' },
    drifted: { icon: StatusDriftedIcon, iconClass: 'text-amber-600 dark:text-amber-400', label: 'Drifted' },
    error: { icon: StatusErrorIcon, iconClass: 'text-red-600 dark:text-red-400', label: 'Error' },
    deleting: { icon: StatusDeletingIcon, iconClass: 'text-muted-foreground', nameClass: 'line-through', label: 'Deleting' },
};

function SyncStateChip({ state }: { state: SyncStateItem }) {
    const config = statusConfig[state.status];
    const Icon = config.icon;

    const chip = (
        <span
            className={cn(
                'inline-flex max-w-40 items-center gap-1 rounded-full border bg-background px-2 py-0.5 text-[11px] text-muted-foreground',
                (state.status === 'error' || state.status === 'drifted') && 'cursor-help',
            )}
        >
            <Icon className={cn('size-3 shrink-0', config.iconClass)} />
            <span className={cn('truncate', config.nameClass)}>{state.provider.name}</span>
        </span>
    );

    if (state.status === 'error' || state.status === 'drifted') {
        return (
            <Tooltip>
                <TooltipTrigger asChild>{chip}</TooltipTrigger>
                <TooltipContent className="max-w-xs">
                    <p className="font-medium">
                        {state.provider.name} — {config.label.toLowerCase()}
                    </p>
                    <p className="mt-0.5 text-xs break-words text-muted-foreground">
                        {state.lastError ?? (state.status === 'drifted' ? 'Remote record no longer matches this entry.' : 'Sync failed.')}
                    </p>
                </TooltipContent>
            </Tooltip>
        );
    }

    return chip;
}

interface EntryRowProps {
    entry: EntryItem;
    onEdit: (entry: EntryItem) => void;
    onDelete: (entry: EntryItem) => void;
}

export function EntryRow({ entry, onEdit, onDelete }: EntryRowProps) {
    const syncNow = () => {
        router.post(route('entries.sync', entry.id), {}, { preserveScroll: true });
    };

    return (
        <tr className="border-b transition-colors last:border-b-0 hover:bg-muted/40">
            <td className="px-4 py-3">
                <span className="inline-flex items-center gap-1.5">
                    <span className="font-mono text-[13px] font-medium">{entry.name}</span>
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
            <td className="px-4 py-3">
                <RecordTypeBadge type={entry.type} />
            </td>
            <td className="max-w-56 px-4 py-3">
                <Tooltip>
                    <TooltipTrigger asChild>
                        <span className="block truncate font-mono text-xs text-muted-foreground">{entry.content}</span>
                    </TooltipTrigger>
                    <TooltipContent className="max-w-sm font-mono text-xs break-all">{entry.content}</TooltipContent>
                </Tooltip>
            </td>
            <td className="px-4 py-3 text-xs text-muted-foreground tabular-nums">{entry.ttl ?? 'Auto'}</td>
            <td className="px-4 py-3">
                {entry.syncStates.length > 0 ? (
                    <div className="flex flex-wrap gap-1">
                        {entry.syncStates.map((state) => (
                            <SyncStateChip key={state.id} state={state} />
                        ))}
                    </div>
                ) : (
                    <span className="text-xs text-muted-foreground/60">—</span>
                )}
            </td>
            <td className="px-4 py-3 text-xs whitespace-nowrap text-muted-foreground">{relativeTime(entry.updatedAt)}</td>
            <td className="px-2 py-3 text-right">
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon" className="size-8 text-muted-foreground" aria-label={`Actions for ${entry.name}`}>
                            <MoreHorizontal className="size-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-40">
                        <DropdownMenuItem onSelect={() => onEdit(entry)}>
                            <Pencil />
                            Edit
                        </DropdownMenuItem>
                        <DropdownMenuItem onSelect={syncNow}>
                            <RefreshCw />
                            Sync now
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            onSelect={() => onDelete(entry)}
                            className="text-red-600 focus:text-red-600 dark:text-red-400 dark:focus:text-red-400"
                        >
                            <Trash2 />
                            Delete
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </td>
        </tr>
    );
}
