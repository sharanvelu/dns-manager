import { ActivityLogDialog } from '@/components/activity-log-dialog';
import { RecordTypeBadge } from '@/components/icons';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Separator } from '@/components/ui/separator';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { AttachProviderDialog } from '@/components/zones/attach-provider-dialog';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { CircleAlert, CircleCheck, CircleDashed, Globe, History, MoreHorizontal, Pencil, Plus, Power, RefreshCw, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { providerMark, providerPayload, relativeTime } from './lib';
import type { Connector, Provider, ZoneOption } from './types';

function HealthBadge({ provider }: { provider: Provider }) {
    if (provider.healthStatus === 'ok') {
        return (
            <Badge
                variant="outline"
                className="gap-1 border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-400"
            >
                <CircleCheck className="size-3" />
                Healthy
            </Badge>
        );
    }

    if (provider.healthStatus === 'error') {
        const badge = (
            <Badge
                variant="outline"
                className="gap-1 border-red-300 bg-red-50 text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-400"
            >
                <CircleAlert className="size-3" />
                Error
            </Badge>
        );

        if (!provider.healthMessage) {
            return badge;
        }

        return (
            <TooltipProvider delayDuration={150}>
                <Tooltip>
                    <TooltipTrigger asChild>
                        <span className="inline-flex cursor-help">{badge}</span>
                    </TooltipTrigger>
                    <TooltipContent className="max-w-72">{provider.healthMessage}</TooltipContent>
                </Tooltip>
            </TooltipProvider>
        );
    }

    return (
        <Badge variant="outline" className="text-muted-foreground gap-1">
            <CircleDashed className="size-3" />
            Not checked
        </Badge>
    );
}

interface ProviderCardProps {
    provider: Provider;
    connectors: Connector[];
    allZones: ZoneOption[];
    canManage: boolean;
    onEdit: (provider: Provider) => void;
}

export function ProviderCard({ provider, connectors, allZones, canManage, onEdit }: ProviderCardProps) {
    const { can } = usePage<SharedData>().props.auth;
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const [attaching, setAttaching] = useState(false);
    const [viewingActivity, setViewingActivity] = useState(false);

    const Mark = providerMark(provider.type);
    const lastChecked = relativeTime(provider.lastCheckedAt);

    const connector = connectors.find((c) => c.type === provider.type);
    const supportsZones = connector?.capabilities.supportsZones ?? true;

    const attachedZoneIds = new Set(provider.zones.map((zone) => zone.zoneId));
    /** Zone-supporting: candidates for attachment. Zoneless: zones the provider was opted out of. */
    const unattachedZones = allZones.filter((zone) => !attachedZoneIds.has(zone.id));

    const canAttach = canManage && supportsZones && unattachedZones.length > 0;

    const checkDrift = () => {
        router.post(route('providers.check', provider.id), {}, { preserveScroll: true });
    };

    const toggleEnabled = () => {
        router.put(route('providers.update', provider.id), { ...providerPayload(provider), enabled: !provider.enabled }, { preserveScroll: true });
    };

    const destroy = () => {
        router.delete(route('providers.destroy', provider.id), {
            preserveScroll: true,
            onFinish: () => setConfirmingDelete(false),
        });
    };

    return (
        <Card className={cn('flex flex-col gap-4 p-5 transition-opacity', !provider.enabled && 'opacity-60')}>
            <div className="flex items-start gap-3">
                <div className="bg-muted/40 text-foreground flex size-10 shrink-0 items-center justify-center rounded-lg border">
                    <Mark className="size-5" />
                </div>

                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                        <h3 className="truncate text-sm font-semibold">{provider.name}</h3>
                    </div>
                    <p className="text-muted-foreground text-xs">{provider.typeLabel}</p>
                </div>

                {canManage && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="icon" className="text-muted-foreground size-8 shrink-0">
                                <MoreHorizontal className="size-4" />
                                <span className="sr-only">Provider actions</span>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-44">
                            <DropdownMenuItem onSelect={() => onEdit(provider)}>
                                <Pencil className="size-4" />
                                Edit
                            </DropdownMenuItem>
                            <DropdownMenuItem onSelect={checkDrift}>
                                <RefreshCw className="size-4" />
                                Check drift
                            </DropdownMenuItem>
                            <DropdownMenuItem onSelect={toggleEnabled}>
                                <Power className="size-4" />
                                {provider.enabled ? 'Disable' : 'Enable'}
                            </DropdownMenuItem>
                            {can.viewGlobalActivity && (
                                <DropdownMenuItem onSelect={() => setViewingActivity(true)}>
                                    <History className="size-4" />
                                    Activity
                                </DropdownMenuItem>
                            )}
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                className="text-red-600 focus:text-red-600 dark:text-red-400 dark:focus:text-red-400"
                                onSelect={() => setConfirmingDelete(true)}
                            >
                                <Trash2 className="size-4" />
                                Delete
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </div>

            <div className="flex flex-wrap items-center gap-1.5">
                <HealthBadge provider={provider} />
                {!provider.enabled && (
                    <Badge variant="secondary" className="text-muted-foreground">
                        Disabled
                    </Badge>
                )}
                <span className="text-muted-foreground ml-auto text-xs">{lastChecked ? `Checked ${lastChecked}` : 'Never checked'}</span>
            </div>

            {provider.managedRecordTypes.length > 0 && (
                <div className="flex flex-wrap gap-1">
                    {provider.managedRecordTypes.map((type) => (
                        <RecordTypeBadge key={type} type={type} />
                    ))}
                </div>
            )}

            <Separator className="mt-auto" />

            <div className="space-y-2">
                <div className="flex items-center justify-between gap-2">
                    <span className="text-muted-foreground text-xs font-medium tracking-wide uppercase">Zones</span>
                    <span className="text-muted-foreground text-xs">
                        {provider.recordsCount} {provider.recordsCount === 1 ? 'record' : 'records'} · {provider.syncedCount} in sync
                    </span>
                </div>

                {provider.zones.length === 0 && (!supportsZones ? unattachedZones.length === 0 : true) ? (
                    <p className="text-muted-foreground text-xs">Not attached to any zone yet.</p>
                ) : (
                    <div className="flex flex-wrap items-center gap-1.5">
                        {!supportsZones && (
                            <Badge variant="secondary" className="gap-1">
                                <Globe className="size-3" />
                                All zones
                            </Badge>
                        )}

                        {provider.zones.map((zone) => (
                            <a
                                key={zone.zoneProviderId}
                                href={`/zones/${zone.zoneId}`}
                                className={cn(
                                    'bg-muted/40 hover:border-muted-foreground/40 hover:bg-accent/50 inline-flex items-center rounded-md border px-2 py-0.5 font-mono text-xs transition-colors',
                                    !zone.enabled && 'text-muted-foreground opacity-60',
                                )}
                            >
                                {zone.zoneName}
                            </a>
                        ))}

                        {!supportsZones &&
                            unattachedZones.map((zone) => (
                                <TooltipProvider key={zone.id} delayDuration={150}>
                                    <Tooltip>
                                        <TooltipTrigger asChild>
                                            <span className="text-muted-foreground inline-flex cursor-help items-center rounded-md border border-dashed px-2 py-0.5 font-mono text-xs line-through opacity-60">
                                                {zone.name}
                                            </span>
                                        </TooltipTrigger>
                                        <TooltipContent>Opted out — re-attach from the zone's page.</TooltipContent>
                                    </Tooltip>
                                </TooltipProvider>
                            ))}
                    </div>
                )}

                {canAttach && (
                    <Button variant="ghost" size="sm" className="text-muted-foreground -ml-2 h-7" onClick={() => setAttaching(true)}>
                        <Plus className="size-3.5" />
                        Attach to zone
                    </Button>
                )}
            </div>

            {canAttach && (
                <AttachProviderDialog
                    open={attaching}
                    onOpenChange={setAttaching}
                    providers={[{ id: provider.id, name: provider.name, type: provider.type, enabled: provider.enabled }]}
                    zones={unattachedZones}
                    connectors={connectors}
                    fixedProviderId={provider.id}
                />
            )}

            {can.viewGlobalActivity && (
                <ActivityLogDialog
                    open={viewingActivity}
                    onOpenChange={setViewingActivity}
                    subjectType="provider"
                    subjectId={provider.id}
                    subjectLabel={provider.name}
                />
            )}

            <Dialog open={confirmingDelete} onOpenChange={setConfirmingDelete}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Delete {provider.name}?</DialogTitle>
                        <DialogDescription>
                            Records at the provider will NOT be deleted; the app just stops managing them. This detaches it from{' '}
                            {provider.zones.length} zone{provider.zones.length === 1 ? '' : 's'}.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="outline">Cancel</Button>
                        </DialogClose>
                        <Button variant="destructive" onClick={destroy}>
                            Delete provider
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </Card>
    );
}
