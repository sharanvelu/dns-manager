import { ConfigFieldInput, defaultConfigFor, type ConfigField, type ConfigValues } from '@/components/config-fields';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useForm } from '@inertiajs/react';
import { CircleAlert, CircleCheck, LoaderCircle, RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState, type FormEventHandler } from 'react';

export interface AttachableProvider {
    id: number;
    name: string;
    type: string;
    enabled: boolean;
}

export interface AttachZoneOption {
    id: number;
    name: string;
}

export interface AttachConnectorInfo {
    type: string;
    displayName: string;
    zoneConfigSchema: ConfigField[];
    capabilities: { supportsZones: boolean };
}

export interface AttachProviderDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Candidates to attach — caller pre-filters out already-attached providers. */
    providers: AttachableProvider[];
    /** Candidate zones — caller pre-filters when opened from a provider card. */
    zones: AttachZoneOption[];
    connectors: AttachConnectorInfo[];
    /** Zone-side entry point: the zone is fixed, the user picks a provider. */
    fixedZoneId?: number;
    /** Provider-side entry point: the provider is fixed, the user picks a zone. */
    fixedProviderId?: number;
}

type Discovery = { status: 'idle' } | { status: 'loading' } | { status: 'found'; summary: string } | { status: 'not-found'; message: string };

/**
 * Attach a provider (credential) to a zone, creating the ZoneProvider
 * attachment. Renders the connector's zoneConfigSchema and auto-runs
 * zone-config discovery (e.g. the Cloudflare zone ID from the zone name);
 * discovered values only fill blank fields — manual input always wins.
 */
export function AttachProviderDialog({ open, onOpenChange, providers, zones, connectors, fixedZoneId, fixedProviderId }: AttachProviderDialogProps) {
    const [zoneId, setZoneId] = useState<number | null>(fixedZoneId ?? (zones.length === 1 ? zones[0].id : null));
    const [discovery, setDiscovery] = useState<Discovery>({ status: 'idle' });

    const { data, setData, post, processing, errors, reset, clearErrors } = useForm<{
        provider_id: number | null;
        config: ConfigValues;
    }>({
        provider_id: fixedProviderId ?? (providers.length === 1 ? providers[0].id : null),
        config: {},
    });

    const provider = providers.find((candidate) => candidate.id === data.provider_id);
    const zone = zones.find((candidate) => candidate.id === zoneId);
    const connector = connectors.find((candidate) => candidate.type === provider?.type);
    const zoneFields = useMemo(() => connector?.zoneConfigSchema ?? [], [connector]);

    const discover = useCallback(
        async (currentConfig: ConfigValues) => {
            if (!provider || !zone || zoneFields.length === 0) return;

            setDiscovery({ status: 'loading' });

            try {
                const response = await fetch(route('zone-providers.discover', zone.id), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
                    },
                    body: JSON.stringify({ provider_id: provider.id }),
                });

                const body = (await response.json().catch(() => null)) as { found?: boolean; config?: Record<string, string>; error?: string } | null;

                if (!response.ok || !body?.found || !body.config) {
                    setDiscovery({
                        status: 'not-found',
                        message:
                            body?.error ??
                            `No ${connector?.displayName ?? 'provider'} zone matches ${zone.name} — check the credential's zone access.`,
                    });

                    return;
                }

                // Discovered values only fill blanks; the user's input wins.
                const merged: ConfigValues = { ...currentConfig };
                for (const [key, value] of Object.entries(body.config)) {
                    if (merged[key] === '' || merged[key] === undefined) merged[key] = value;
                }

                setData('config', merged);
                setDiscovery({ status: 'found', summary: Object.values(body.config).join(', ') });
            } catch {
                setDiscovery({ status: 'not-found', message: 'Could not reach the server. Check your connection and try again.' });
            }
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [provider?.id, zone?.id, zoneFields.length],
    );

    // Reset + auto-discover whenever the provider/zone pairing changes.
    useEffect(() => {
        if (!open) return;

        clearErrors();
        const blank = defaultConfigFor(zoneFields);
        setData('config', blank);
        setDiscovery({ status: 'idle' });

        if (provider && zone && zoneFields.length > 0) {
            void discover(blank);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, provider?.id, zone?.id]);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        if (!zone) return;

        post(route('zone-providers.store', zone.id), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{fixedZoneId && zone ? `Attach a provider to ${zone.name}` : 'Attach to zone'}</DialogTitle>
                    <DialogDescription>The provider's credentials are reused — only zone-specific settings live on the attachment.</DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} noValidate className="space-y-5">
                    {fixedProviderId === undefined && (
                        <div className="space-y-1.5">
                            <Label>Provider</Label>
                            <Select
                                value={data.provider_id !== null ? String(data.provider_id) : undefined}
                                onValueChange={(value) => setData('provider_id', Number(value))}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Choose a provider" />
                                </SelectTrigger>
                                <SelectContent>
                                    {providers.map((candidate) => (
                                        <SelectItem key={candidate.id} value={String(candidate.id)}>
                                            {candidate.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.provider_id} />
                        </div>
                    )}

                    {fixedZoneId === undefined && (
                        <div className="space-y-1.5">
                            <Label>Zone</Label>
                            <Select value={zoneId !== null ? String(zoneId) : undefined} onValueChange={(value) => setZoneId(Number(value))}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Choose a zone" />
                                </SelectTrigger>
                                <SelectContent>
                                    {zones.map((candidate) => (
                                        <SelectItem key={candidate.id} value={String(candidate.id)}>
                                            <span className="font-mono">{candidate.name}</span>
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}

                    {provider && zone && zoneFields.length > 0 && (
                        <div className="space-y-4">
                            {discovery.status === 'loading' && (
                                <p className="text-muted-foreground flex items-center gap-2 text-xs">
                                    <LoaderCircle className="size-3.5 animate-spin" />
                                    Looking up {zone.name} at {connector?.displayName}…
                                </p>
                            )}
                            {discovery.status === 'found' && (
                                <div className="flex items-start gap-2 rounded-md border border-emerald-500/20 bg-emerald-500/10 px-3 py-2 text-xs text-emerald-700 dark:text-emerald-400">
                                    <CircleCheck className="mt-px size-3.5 shrink-0" />
                                    <span>
                                        Matched {connector?.displayName} zone <span className="font-mono">{zone.name}</span> ({discovery.summary})
                                    </span>
                                </div>
                            )}
                            {discovery.status === 'not-found' && (
                                <div className="flex items-start gap-2 rounded-md border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-xs text-amber-700 dark:text-amber-400">
                                    <CircleAlert className="mt-px size-3.5 shrink-0" />
                                    <span>{discovery.message}</span>
                                </div>
                            )}

                            {zoneFields.map((field) => (
                                <ConfigFieldInput
                                    key={field.key}
                                    field={field}
                                    value={data.config[field.key]}
                                    error={errors[`config.${field.key}` as keyof typeof errors]}
                                    editing={false}
                                    idPrefix="zone-config"
                                    onChange={(key, value) => setData('config', { ...data.config, [key]: value })}
                                />
                            ))}

                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => void discover(data.config)}
                                disabled={discovery.status === 'loading'}
                            >
                                <RefreshCw className="size-3.5" />
                                Discover again
                            </Button>
                        </div>
                    )}

                    {provider && zone && zoneFields.length === 0 && (
                        <p className="text-muted-foreground bg-muted/40 rounded-md px-3 py-2 text-xs">
                            No zone-specific settings — {provider.name} serves this zone as-is.
                        </p>
                    )}

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing || !provider || !zone}>
                            {processing ? 'Attaching…' : 'Attach'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
