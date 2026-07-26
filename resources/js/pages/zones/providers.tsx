import { FlashToast } from '@/components/flash-toast';
import { ProviderCloudflareMark, ProviderGenericMark, ProviderPiholeMark, ProviderTechnitiumMark } from '@/components/icons';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { ZoneTabs } from '@/components/zone-tabs';
import { AttachProviderDialog } from '@/components/zones/attach-provider-dialog';
import { AttachmentConfigDialog } from '@/components/zones/attachment-config-dialog';
import { DetachProviderDialog, type DetachableAttachment } from '@/components/zones/detach-provider-dialog';
import { ImportRecordsDialog } from '@/components/zones/import-records-dialog';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { CircleAlert, CircleCheck, Download, Link2, LoaderCircle, Pause, Play, Plug, Settings2, Unlink } from 'lucide-react';
import { useState, type ComponentType, type SVGProps } from 'react';
import type { ZoneAttachmentDetail, ZoneProvidersProps } from './types';

const providerMarks: Record<string, ComponentType<SVGProps<SVGSVGElement>>> = {
    cloudflare: ProviderCloudflareMark,
    pihole: ProviderPiholeMark,
    technitium: ProviderTechnitiumMark,
};

function HealthBadge({ status, message }: { status: ZoneAttachmentDetail['healthStatus']; message: string | null }) {
    if (status === 'ok') {
        return (
            <span className="inline-flex items-center gap-1.5 rounded-full border border-emerald-500/20 bg-emerald-500/10 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:text-emerald-400">
                <span className="size-1.5 rounded-full bg-emerald-500" />
                Healthy
            </span>
        );
    }

    if (status === 'error') {
        const badge = (
            <span className="inline-flex items-center gap-1.5 rounded-full border border-red-500/20 bg-red-500/10 px-2 py-0.5 text-xs font-medium text-red-700 dark:text-red-400">
                <span className="size-1.5 rounded-full bg-red-500" />
                Error
            </span>
        );

        if (!message) return badge;

        return (
            <Tooltip>
                <TooltipTrigger asChild>
                    <span className="cursor-default">{badge}</span>
                </TooltipTrigger>
                <TooltipContent className="max-w-72">{message}</TooltipContent>
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

interface TestResult {
    ok: boolean;
    message: string;
}

function AttachmentCard({
    zone,
    attachment,
    typeLabel,
    zoneConfigFields,
    canManageAttachments,
    canManageRecords,
    onEditConfig,
    onImport,
    onDetach,
}: {
    zone: { id: number; name: string };
    attachment: ZoneAttachmentDetail;
    typeLabel: string;
    zoneConfigFields: number;
    canManageAttachments: boolean;
    canManageRecords: boolean;
    onEditConfig: (attachment: ZoneAttachmentDetail) => void;
    onImport: (attachment: ZoneAttachmentDetail) => void;
    onDetach: (attachment: DetachableAttachment) => void;
}) {
    const Mark = providerMarks[attachment.providerType] ?? ProviderGenericMark;
    const [test, setTest] = useState<{ status: 'idle' | 'loading' | 'done'; result?: TestResult }>({ status: 'idle' });

    const paused = !attachment.enabled || !attachment.providerEnabled;
    const configEntries = Object.entries(attachment.zoneConfig).filter(([, value]) => value !== '' && value !== false);

    const toggleEnabled = () => {
        router.put(route('zone-providers.update', [zone.id, attachment.id]), { enabled: !attachment.enabled }, { preserveScroll: true });
    };

    const runTest = async () => {
        setTest({ status: 'loading' });

        try {
            const response = await fetch(route('zone-providers.test', [zone.id, attachment.id]), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
                },
            });

            const body = (await response.json().catch(() => null)) as (TestResult & { message?: string }) | null;

            setTest({
                status: 'done',
                result: {
                    ok: response.ok && (body?.ok ?? false),
                    message: body?.message ?? (response.ok ? 'No response message.' : `Request failed (${response.status}).`),
                },
            });
        } catch {
            setTest({ status: 'done', result: { ok: false, message: 'Could not reach the server.' } });
        }
    };

    return (
        <Card className={cn('flex flex-col gap-3 p-4', paused && 'opacity-60')}>
            <div className="flex items-start justify-between gap-2">
                <div className="flex min-w-0 items-center gap-3">
                    <span className="bg-muted/40 text-muted-foreground flex size-9 shrink-0 items-center justify-center rounded-md border">
                        <Mark className="size-5" />
                    </span>
                    <span className="min-w-0">
                        <span className="block truncate text-sm font-medium">{attachment.providerName}</span>
                        <span className="text-muted-foreground block text-xs">{typeLabel}</span>
                    </span>
                </div>
                <div className="flex shrink-0 flex-wrap items-center justify-end gap-1.5">
                    {!attachment.supportsZones && (
                        <Badge variant="secondary" className="font-medium">
                            All zones
                        </Badge>
                    )}
                    {!attachment.providerEnabled ? (
                        <Badge variant="secondary" className="font-medium">
                            Provider disabled
                        </Badge>
                    ) : (
                        !attachment.enabled && (
                            <Badge variant="secondary" className="font-medium">
                                Paused
                            </Badge>
                        )
                    )}
                    <HealthBadge status={attachment.healthStatus} message={attachment.healthMessage} />
                </div>
            </div>

            <Separator />

            <div className="text-muted-foreground flex flex-wrap items-center justify-between gap-2 text-xs">
                <span className="tabular-nums">
                    {attachment.recordsCount.toLocaleString()} {attachment.recordsCount === 1 ? 'record' : 'records'} ·{' '}
                    {attachment.syncedCount.toLocaleString()} in sync
                    {attachment.driftedCount > 0 && <span className="text-amber-600 dark:text-amber-400"> · {attachment.driftedCount} drifted</span>}
                    {attachment.errorCount > 0 && (
                        <span className="text-red-600 dark:text-red-400">
                            {' '}
                            · {attachment.errorCount} {attachment.errorCount === 1 ? 'error' : 'errors'}
                        </span>
                    )}
                </span>
                {configEntries.length > 0 ? (
                    <span className="flex min-w-0 items-center gap-1.5">
                        {configEntries.map(([key, value]) => (
                            <Tooltip key={key}>
                                <TooltipTrigger asChild>
                                    <code className="bg-muted max-w-40 cursor-default truncate rounded px-1.5 py-0.5 font-mono text-[11px]">
                                        {String(value)}
                                    </code>
                                </TooltipTrigger>
                                <TooltipContent className="font-mono text-xs">
                                    {key}: {String(value)}
                                </TooltipContent>
                            </Tooltip>
                        ))}
                    </span>
                ) : (
                    !attachment.supportsZones && <span>Serves every zone as-is</span>
                )}
            </div>

            {(canManageAttachments || canManageRecords) && (
                <div className="flex flex-wrap items-center gap-2">
                    {canManageAttachments && (
                        <Button variant="outline" size="sm" onClick={() => void runTest()} disabled={test.status === 'loading'}>
                            {test.status === 'loading' ? <LoaderCircle className="size-3.5 animate-spin" /> : <Plug className="size-3.5" />}
                            Test
                        </Button>
                    )}
                    {canManageRecords && (
                        <Button variant="outline" size="sm" onClick={() => onImport(attachment)}>
                            <Download className="size-3.5" />
                            Import records
                        </Button>
                    )}
                    {canManageAttachments && (
                        <>
                            <Button variant="outline" size="sm" onClick={toggleEnabled}>
                                {attachment.enabled ? <Pause className="size-3.5" /> : <Play className="size-3.5" />}
                                {attachment.enabled ? 'Disable' : 'Enable'}
                            </Button>
                            {zoneConfigFields > 0 && (
                                <Button variant="outline" size="sm" onClick={() => onEditConfig(attachment)}>
                                    <Settings2 className="size-3.5" />
                                    Edit config
                                </Button>
                            )}
                            <Button
                                variant="ghost"
                                size="sm"
                                className="text-red-600 hover:text-red-600 dark:text-red-400 dark:hover:text-red-400"
                                onClick={() =>
                                    onDetach({
                                        id: attachment.id,
                                        providerName: attachment.providerName,
                                        supportsZones: attachment.supportsZones,
                                    })
                                }
                            >
                                <Unlink className="size-3.5" />
                                {attachment.supportsZones ? 'Detach' : 'Opt out'}
                            </Button>
                        </>
                    )}
                </div>
            )}

            {test.status === 'done' && test.result && (
                <div
                    className={cn(
                        'flex items-start gap-2 rounded-md border px-3 py-2 text-xs',
                        test.result.ok
                            ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
                            : 'border-red-500/40 bg-red-500/10 text-red-700 dark:text-red-400',
                    )}
                >
                    {test.result.ok ? <CircleCheck className="mt-px size-3.5 shrink-0" /> : <CircleAlert className="mt-px size-3.5 shrink-0" />}
                    <span className="min-w-0 break-words">{test.result.message}</span>
                </div>
            )}
        </Card>
    );
}

export default function ZoneProviders({ zone, zoneCan, attachments, availableProviders, connectors }: ZoneProvidersProps) {
    const [attachOpen, setAttachOpen] = useState(false);
    const [attachProviderId, setAttachProviderId] = useState<number | null>(null);
    const [configAttachment, setConfigAttachment] = useState<ZoneAttachmentDetail | null>(null);
    const [importAttachment, setImportAttachment] = useState<ZoneAttachmentDetail | null>(null);
    const [detaching, setDetaching] = useState<DetachableAttachment | null>(null);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Zones', href: '/zones' },
        { title: zone.name, href: `/zones/${zone.id}` },
        { title: 'Providers', href: `/zones/${zone.id}/providers` },
    ];

    const connectorFor = (type: string) => connectors.find((connector) => connector.type === type);

    const openAttach = (providerId: number | null = null) => {
        setAttachProviderId(providerId);
        setAttachOpen(true);
    };

    const attachCandidates =
        attachProviderId !== null ? availableProviders.filter((provider) => provider.id === attachProviderId) : availableProviders;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Providers — ${zone.name}`} />

            <TooltipProvider delayDuration={200}>
                <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                    <ZoneTabs zone={zone} zoneCan={zoneCan} />

                    {attachments.length === 0 && (
                        <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm text-amber-700 dark:text-amber-400">
                            <span className="flex items-center gap-2">
                                <CircleAlert className="size-4 shrink-0" />
                                No providers attached — records in this zone are only stored locally.
                            </span>
                            {zoneCan.manageAttachments && availableProviders.length > 0 && (
                                <Button size="sm" variant="outline" onClick={() => openAttach()}>
                                    <Link2 className="size-4" />
                                    Attach a provider
                                </Button>
                            )}
                        </div>
                    )}

                    <section className="flex flex-col gap-3">
                        <div className="flex items-center justify-between gap-2">
                            <h2 className="text-muted-foreground text-sm font-medium">Attached providers</h2>
                            {zoneCan.manageAttachments && availableProviders.length > 0 && (
                                <Button variant="outline" size="sm" onClick={() => openAttach()}>
                                    <Link2 className="size-4" />
                                    Attach provider
                                </Button>
                            )}
                        </div>

                        {attachments.length > 0 && (
                            <div className="grid items-start gap-3 lg:grid-cols-2">
                                {attachments.map((attachment) => (
                                    <AttachmentCard
                                        key={attachment.id}
                                        zone={zone}
                                        attachment={attachment}
                                        typeLabel={connectorFor(attachment.providerType)?.displayName ?? attachment.providerType}
                                        zoneConfigFields={connectorFor(attachment.providerType)?.zoneConfigSchema.length ?? 0}
                                        canManageAttachments={zoneCan.manageAttachments}
                                        canManageRecords={zoneCan.manageRecords}
                                        onEditConfig={setConfigAttachment}
                                        onImport={setImportAttachment}
                                        onDetach={setDetaching}
                                    />
                                ))}
                            </div>
                        )}

                        {availableProviders.length > 0 && (
                            <div className="flex flex-col gap-2">
                                <h3 className="text-muted-foreground/70 text-xs font-medium">Available</h3>
                                <div className="divide-border divide-y rounded-lg border border-dashed">
                                    {availableProviders.map((provider) => {
                                        const Mark = providerMarks[provider.type] ?? ProviderGenericMark;

                                        return (
                                            <div key={provider.id} className="flex items-center justify-between gap-3 px-3 py-2">
                                                <span className="text-muted-foreground flex min-w-0 items-center gap-2 text-sm">
                                                    <Mark className="size-4 shrink-0" />
                                                    <span className="truncate">{provider.name}</span>
                                                    <span className="text-muted-foreground/70 text-xs">
                                                        {connectorFor(provider.type)?.displayName ?? provider.type}
                                                    </span>
                                                </span>
                                                {zoneCan.manageAttachments && (
                                                    <Button variant="ghost" size="sm" onClick={() => openAttach(provider.id)}>
                                                        <Link2 className="size-3.5" />
                                                        Attach
                                                    </Button>
                                                )}
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        )}
                    </section>
                </div>
            </TooltipProvider>

            {/* key forces a fresh mount so the provider preselection applies. */}
            <AttachProviderDialog
                key={attachProviderId ?? 'all'}
                open={attachOpen}
                onOpenChange={(open) => {
                    setAttachOpen(open);
                    if (!open) setAttachProviderId(null);
                }}
                providers={attachCandidates}
                zones={[{ id: zone.id, name: zone.name }]}
                connectors={connectors}
                fixedZoneId={zone.id}
            />
            {configAttachment && (
                <AttachmentConfigDialog
                    open
                    onOpenChange={(open) => !open && setConfigAttachment(null)}
                    zoneId={zone.id}
                    attachment={configAttachment}
                    fields={connectorFor(configAttachment.providerType)?.zoneConfigSchema ?? []}
                />
            )}
            {importAttachment && (
                <ImportRecordsDialog
                    open
                    onOpenChange={(open) => !open && setImportAttachment(null)}
                    zone={zone}
                    attachment={{ id: importAttachment.id, providerName: importAttachment.providerName }}
                />
            )}
            <DetachProviderDialog zone={zone} attachment={detaching} onOpenChange={(open) => !open && setDetaching(null)} />
            <FlashToast />
        </AppLayout>
    );
}
