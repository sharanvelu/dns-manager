import {
    EmptyActivityIllustration,
    EmptyEntriesIllustration,
    EmptyProvidersIllustration,
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
import { StatTile } from '@/components/stat-tile';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, Globe, Lock, Plus, ServerCog, Users } from 'lucide-react';
import type { ComponentType, ReactNode, SVGProps } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

interface DashboardProps {
    /** Set when the user has no zone access — stats/zones/activity are omitted. */
    noAccess?: boolean;
    isUserAdmin?: boolean;
    stats: {
        totalEntries: number;
        inSync: number;
        drifted: number;
        errored: number;
        providersTotal: number;
        providersHealthy: number;
    };
    providers: Array<{
        id: number;
        name: string;
        type: 'cloudflare' | 'pihole' | 'technitium';
        typeLabel: string;
        enabled: boolean;
        healthStatus: 'ok' | 'error' | 'unchecked';
        healthMessage: string | null;
        lastCheckedAt: string | null;
        recordsCount: number;
        syncedCount: number;
        driftedCount: number;
        errorCount: number;
    }>;
    zones: Array<{
        id: number;
        name: string;
        entriesCount: number;
        syncedCount: number;
        driftedCount: number;
        erroredCount: number;
        providerTypes: string[];
    }>;
    activity: Array<{
        id: number;
        action: string;
        status: string;
        message: string | null;
        provider: { id: number; name: string } | null;
        entry: { id: number; name: string } | null;
        zone: { id: number; name: string } | null;
        createdAt: string;
    }>;
}

type Provider = DashboardProps['providers'][number];
type Zone = DashboardProps['zones'][number];
type ActivityItem = DashboardProps['activity'][number];

/** Tiny relative-time formatter — no date library. */
function timeAgo(iso: string): string {
    const seconds = Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 1000));

    if (seconds < 45) return 'just now';
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;
    const days = Math.floor(hours / 24);
    if (days < 30) return `${days}d ago`;
    const months = Math.floor(days / 30);
    if (months < 12) return `${months}mo ago`;

    return `${Math.floor(months / 12)}y ago`;
}

const providerMarks: Record<string, ComponentType<SVGProps<SVGSVGElement>>> = {
    cloudflare: ProviderCloudflareMark,
    pihole: ProviderPiholeMark,
    technitium: ProviderTechnitiumMark,
};

const providerTypeLabels: Record<string, string> = {
    cloudflare: 'Cloudflare',
    pihole: 'Pi-hole',
    technitium: 'Technitium',
};

const actionLabels: Record<string, string> = {
    push: 'Push',
    delete: 'Delete',
    import: 'Import',
    'drift-check': 'Drift check',
    'provider-health-check': 'Health check',
};

function ProvidersHealthyChip({ healthy, total }: { healthy: number; total: number }) {
    const allHealthy = total > 0 && healthy === total;

    return (
        <div className="bg-card text-muted-foreground inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs shadow-xs">
            <ServerCog className="size-3.5" />
            <span>
                Providers healthy{' '}
                <span
                    className={cn(
                        'font-semibold tabular-nums',
                        allHealthy ? 'text-emerald-600 dark:text-emerald-400' : total > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-foreground',
                    )}
                >
                    {healthy}/{total}
                </span>
            </span>
        </div>
    );
}

function HealthBadge({ provider }: { provider: Provider }) {
    if (provider.healthStatus === 'ok') {
        return (
            <span className="inline-flex items-center gap-1.5 rounded-full border border-emerald-500/20 bg-emerald-500/10 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:text-emerald-400">
                <span className="size-1.5 rounded-full bg-emerald-500" />
                Healthy
            </span>
        );
    }

    if (provider.healthStatus === 'error') {
        const badge = (
            <span className="inline-flex items-center gap-1.5 rounded-full border border-red-500/20 bg-red-500/10 px-2 py-0.5 text-xs font-medium text-red-700 dark:text-red-400">
                <span className="size-1.5 rounded-full bg-red-500" />
                Error
            </span>
        );

        if (!provider.healthMessage) return badge;

        return (
            <Tooltip>
                <TooltipTrigger asChild>
                    <span className="cursor-default">{badge}</span>
                </TooltipTrigger>
                <TooltipContent className="max-w-72">{provider.healthMessage}</TooltipContent>
            </Tooltip>
        );
    }

    return (
        <span className="text-muted-foreground inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs font-medium">
            <span className="bg-muted-foreground/40 size-1.5 rounded-full" />
            Not checked
        </span>
    );
}

function ProviderCard({ provider }: { provider: Provider }) {
    const Mark = providerMarks[provider.type] ?? ProviderGenericMark;

    return (
        <Card className={cn('flex flex-col gap-3 p-4', !provider.enabled && 'opacity-60')}>
            <div className="flex items-start justify-between gap-2">
                <Link href="/providers" className="group flex min-w-0 items-center gap-3">
                    <span className="bg-muted/40 text-muted-foreground flex size-9 shrink-0 items-center justify-center rounded-md border">
                        <Mark className="size-5" />
                    </span>
                    <span className="min-w-0">
                        <span className="block truncate text-sm font-medium group-hover:underline group-hover:underline-offset-4">
                            {provider.name}
                        </span>
                        <span className="text-muted-foreground block text-xs">{provider.typeLabel}</span>
                    </span>
                </Link>
                <div className="flex shrink-0 items-center gap-1.5">
                    {!provider.enabled && (
                        <Badge variant="secondary" className="font-medium">
                            Disabled
                        </Badge>
                    )}
                    <HealthBadge provider={provider} />
                </div>
            </div>

            <Separator />

            <div className="text-muted-foreground flex items-center justify-between gap-2 text-xs">
                <span className="tabular-nums">
                    {provider.recordsCount.toLocaleString()} {provider.recordsCount === 1 ? 'record' : 'records'} ·{' '}
                    {provider.syncedCount.toLocaleString()} in sync
                    {provider.driftedCount > 0 && <span className="text-amber-600 dark:text-amber-400"> · {provider.driftedCount} drifted</span>}
                    {provider.errorCount > 0 && <span className="text-red-600 dark:text-red-400"> · {provider.errorCount} errored</span>}
                </span>
                {provider.lastCheckedAt && (
                    <span className="shrink-0" title={new Date(provider.lastCheckedAt).toLocaleString()}>
                        Checked {timeAgo(provider.lastCheckedAt)}
                    </span>
                )}
            </div>
        </Card>
    );
}

function ZoneCard({ zone }: { zone: Zone }) {
    const allInSync = zone.entriesCount > 0 && zone.syncedCount === zone.entriesCount;
    const hasStatus = allInSync || zone.driftedCount > 0 || zone.erroredCount > 0;

    return (
        <Card className="flex flex-col gap-3 p-4">
            <div className="flex items-start justify-between gap-2">
                <Link href={`/zones/${zone.id}`} className="group flex min-w-0 items-center gap-3">
                    <span className="bg-muted/40 text-muted-foreground flex size-9 shrink-0 items-center justify-center rounded-md border">
                        <ZoneMark className="size-5" />
                    </span>
                    <span className="min-w-0">
                        <span className="block truncate font-mono text-sm font-medium group-hover:underline group-hover:underline-offset-4">
                            {zone.name}
                        </span>
                        <span className="text-muted-foreground block text-xs tabular-nums">
                            {zone.entriesCount.toLocaleString()} {zone.entriesCount === 1 ? 'record' : 'records'}
                        </span>
                    </span>
                </Link>
                {zone.providerTypes.length > 0 && (
                    <span className="text-muted-foreground flex shrink-0 items-center gap-1.5">
                        {zone.providerTypes.map((type) => {
                            const Mark = providerMarks[type] ?? ProviderGenericMark;

                            return (
                                <Tooltip key={type}>
                                    <TooltipTrigger asChild>
                                        <span className="cursor-default">
                                            <Mark className="size-4" />
                                        </span>
                                    </TooltipTrigger>
                                    <TooltipContent>{providerTypeLabels[type] ?? type}</TooltipContent>
                                </Tooltip>
                            );
                        })}
                    </span>
                )}
            </div>

            {hasStatus && (
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
            )}
        </Card>
    );
}

function ActivityRow({ item }: { item: ActivityItem }) {
    const isSuccess = item.status === 'success';
    const StatusIcon = isSuccess ? StatusSyncedIcon : StatusErrorIcon;
    const actionLabel = actionLabels[item.action] ?? item.action;
    const context = [item.provider?.name, item.entry?.name].filter(Boolean).join(' · ');

    return (
        <li className="flex items-start gap-3 py-3">
            <StatusIcon
                className={cn('mt-0.5 size-4 shrink-0', isSuccess ? 'text-emerald-500 dark:text-emerald-400' : 'text-red-500 dark:text-red-400')}
            />
            <div className="min-w-0 flex-1">
                <p className="truncate text-sm" title={item.message ?? undefined}>
                    {item.message ?? `${actionLabel} ${isSuccess ? 'completed' : 'failed'}`}
                </p>
                {(item.zone || context) && (
                    <p className="text-muted-foreground truncate text-xs">
                        {item.zone && <span className="font-mono">{item.zone.name}</span>}
                        {item.zone && context && ' · '}
                        {context}
                    </p>
                )}
            </div>
            <span className="text-muted-foreground shrink-0 text-xs whitespace-nowrap" title={new Date(item.createdAt).toLocaleString()}>
                {timeAgo(item.createdAt)}
            </span>
        </li>
    );
}

function SectionHeading({ title, action }: { title: string; action?: ReactNode }) {
    return (
        <div className="flex items-center justify-between gap-2">
            <h2 className="text-muted-foreground text-sm font-medium">{title}</h2>
            {action}
        </div>
    );
}

export default function Dashboard({ noAccess, isUserAdmin, stats, providers, zones, activity }: DashboardProps) {
    const can = usePage<SharedData>().props.auth.can;

    if (noAccess) {
        const Icon = isUserAdmin ? Users : Lock;

        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Dashboard" />
                <div className="flex h-full flex-1 items-center justify-center p-4 md:p-6">
                    <Card className="flex w-full max-w-md flex-col items-center gap-4 border-dashed px-6 py-10 text-center">
                        <Icon className="text-muted-foreground/60 size-8" />
                        <p className="text-sm font-medium">
                            {isUserAdmin
                                ? 'You manage users — head to the Users section.'
                                : 'No access yet — ask an administrator to grant you access to a zone.'}
                        </p>
                        {isUserAdmin && (
                            <Button asChild size="sm">
                                <Link href="/users">
                                    <Users />
                                    Go to Users
                                </Link>
                            </Button>
                        )}
                    </Card>
                </div>
            </AppLayout>
        );
    }

    const hasProviders = providers.length > 0;
    const hasZones = zones.length > 0;
    const fullySynced = stats.totalEntries > 0 && stats.inSync === stats.totalEntries;
    const showProvidersSection = hasProviders || can.viewProviders;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <TooltipProvider delayDuration={200}>
                <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                    {/* Stats */}
                    <section className="flex flex-col gap-3">
                        <SectionHeading
                            title="Overview"
                            action={<ProvidersHealthyChip healthy={stats.providersHealthy} total={stats.providersTotal} />}
                        />
                        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                            <StatTile label="Total managed entries" value={stats.totalEntries} icon={Globe} />
                            <StatTile label="Fully in sync" value={stats.inSync} icon={StatusSyncedIcon} accent={fullySynced ? 'green' : 'neutral'} />
                            <StatTile
                                label="Drifted"
                                value={stats.drifted}
                                icon={StatusDriftedIcon}
                                accent={stats.drifted > 0 ? 'amber' : 'neutral'}
                            />
                            <StatTile label="Errors" value={stats.errored} icon={StatusErrorIcon} accent={stats.errored > 0 ? 'red' : 'neutral'} />
                        </div>
                    </section>

                    {/* Zones */}
                    <section className="flex flex-col gap-3">
                        <SectionHeading
                            title="Zones"
                            action={
                                hasZones ? (
                                    <Link
                                        href="/zones"
                                        className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-xs font-medium transition-colors"
                                    >
                                        View all
                                        <ArrowRight className="size-3.5" />
                                    </Link>
                                ) : undefined
                            }
                        />
                        {hasZones ? (
                            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                {zones.map((zone) => (
                                    <ZoneCard key={zone.id} zone={zone} />
                                ))}
                            </div>
                        ) : (
                            <Card className="flex flex-col items-center gap-4 border-dashed px-6 py-10 text-center">
                                <EmptyZonesIllustration className="text-muted-foreground" />
                                <div>
                                    <p className="text-sm font-medium">Create your first zone</p>
                                    <p className="text-muted-foreground mx-auto mt-1 max-w-sm text-sm">
                                        Zones group your DNS records by domain and decide which providers they sync to.
                                    </p>
                                </div>
                                {can.createZones && (
                                    <Button asChild size="sm">
                                        <Link href="/zones">
                                            <Plus />
                                            Create a zone
                                        </Link>
                                    </Button>
                                )}
                            </Card>
                        )}
                    </section>

                    {/* No entries yet — only once a zone and a provider exist */}
                    {hasZones && hasProviders && stats.totalEntries === 0 && (
                        <Card className="flex flex-col items-center gap-3 border-dashed p-6 text-center sm:flex-row sm:text-left">
                            <EmptyEntriesIllustration className="text-muted-foreground size-20 shrink-0" />
                            <div className="flex-1">
                                <p className="text-sm font-medium">No DNS entries yet</p>
                                <p className="text-muted-foreground text-sm">Create your first managed entry and push it to your providers.</p>
                            </div>
                            <Button asChild size="sm">
                                <Link href="/entries">
                                    <Plus />
                                    Add an entry
                                </Link>
                            </Button>
                        </Card>
                    )}

                    {/* Providers — hidden entirely for zone-scoped users without any (they can't connect one). */}
                    {showProvidersSection && (
                        <section className="flex flex-col gap-3">
                            <SectionHeading
                                title="Providers"
                                action={
                                    hasProviders ? (
                                        <Link
                                            href="/providers"
                                            className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-xs font-medium transition-colors"
                                        >
                                            View all
                                            <ArrowRight className="size-3.5" />
                                        </Link>
                                    ) : undefined
                                }
                            />
                            {hasProviders ? (
                                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                    {providers.map((provider) => (
                                        <ProviderCard key={provider.id} provider={provider} />
                                    ))}
                                </div>
                            ) : (
                                <Card className="flex flex-col items-center gap-4 border-dashed px-6 py-10 text-center">
                                    <EmptyProvidersIllustration className="text-muted-foreground" />
                                    <div>
                                        <p className="text-sm font-medium">No providers connected</p>
                                        <p className="text-muted-foreground mx-auto mt-1 max-w-sm text-sm">
                                            Connect Cloudflare, Pi-hole, or another DNS provider to start managing and syncing your records.
                                        </p>
                                    </div>
                                    <Button asChild size="sm">
                                        <Link href="/providers">
                                            <Plus />
                                            Connect a provider
                                        </Link>
                                    </Button>
                                </Card>
                            )}
                        </section>
                    )}

                    {/* Recent activity */}
                    <section className="flex flex-col gap-3">
                        <SectionHeading title="Recent activity" />
                        <Card className="p-4">
                            {activity.length > 0 ? (
                                <ul className="-my-3 divide-y">
                                    {activity.map((item) => (
                                        <ActivityRow key={item.id} item={item} />
                                    ))}
                                </ul>
                            ) : (
                                <div className="flex flex-col items-center gap-3 px-4 py-8 text-center">
                                    <EmptyActivityIllustration className="text-muted-foreground" />
                                    <div>
                                        <p className="text-sm font-medium">No sync activity yet</p>
                                        <p className="text-muted-foreground mt-1 text-sm">Pushes, deletions, and drift checks will show up here.</p>
                                    </div>
                                </div>
                            )}
                        </Card>
                    </section>
                </div>
            </TooltipProvider>
        </AppLayout>
    );
}
