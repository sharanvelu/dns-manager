import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import { Cloud, LoaderCircle, TriangleAlert } from 'lucide-react';
import { useEffect, type FormEvent } from 'react';
import { ProviderMark } from './helpers';
import { RECORD_TYPES, type ConnectorInfo, type EntryItem, type RecordType, type ZoneAttachment, type ZoneOption } from './types';

const contentField: Record<RecordType, { label: string; placeholder: string }> = {
    A: { label: 'IPv4 address', placeholder: 'e.g. 192.168.1.10' },
    AAAA: { label: 'IPv6 address', placeholder: 'e.g. 2001:db8::1' },
    CNAME: { label: 'Target hostname', placeholder: 'e.g. server.home.lan' },
    MX: { label: 'Mail server hostname', placeholder: 'e.g. mail.example.com' },
    TXT: { label: 'Text value', placeholder: 'e.g. v=spf1 include:example.com ~all' },
    SRV: { label: 'Content', placeholder: 'weight port target' },
    NS: { label: 'Target hostname', placeholder: 'e.g. ns1.example.com' },
    CAA: { label: 'Content', placeholder: 'flags tag "value"' },
    PTR: { label: 'Target hostname', placeholder: 'e.g. host.example.com' },
};

type EntryFormData = {
    dns_zone_id: string;
    name: string;
    type: RecordType;
    content: string;
    ttl: string;
    priority: string;
    proxied: boolean;
    comment: string;
    zone_providers: number[];
};

function compatibleAttachments(attachments: ZoneAttachment[], type: RecordType): ZoneAttachment[] {
    return attachments.filter((attachment) => attachment.enabled && attachment.managedRecordTypes.includes(type));
}

function initialData(entry: EntryItem | null, defaultZoneId: number | undefined, zoneAttachments: Record<number, ZoneAttachment[]>): EntryFormData {
    const type = (entry?.type as RecordType) ?? 'A';
    const zoneId = entry ? entry.zone.id : defaultZoneId;

    return {
        dns_zone_id: zoneId != null ? String(zoneId) : '',
        name: entry?.name ?? '',
        type,
        content: entry?.content ?? '',
        ttl: entry?.ttl != null ? String(entry.ttl) : '',
        priority: entry?.priority != null ? String(entry.priority) : '',
        proxied: entry?.proxied ?? false,
        comment: entry?.comment ?? '',
        // Edit: current assignment. Create: every compatible enabled attachment of the zone.
        zone_providers: entry
            ? entry.syncStates.filter((state) => state.status !== 'deleting').map((state) => state.zoneProviderId)
            : compatibleAttachments(zoneAttachments[zoneId ?? -1] ?? [], type).map((attachment) => attachment.id),
    };
}

interface EntryFormDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** null = create mode, otherwise the entry being edited. */
    entry: EntryItem | null;
    /** Zone-locked mode (lockedZone set) pins the create dialog to that zone. */
    lockedZone?: ZoneOption;
    zones: ZoneOption[];
    zoneAttachments: Record<number, ZoneAttachment[]>;
    connectors: ConnectorInfo[];
    /** Create mode default: the active zone filter, else the only zone. */
    defaultZoneId?: number;
}

export function EntryFormDialog({ open, onOpenChange, entry, lockedZone, zones, zoneAttachments, connectors, defaultZoneId }: EntryFormDialogProps) {
    const { data, setData, post, put, processing, errors, reset, clearErrors, transform } = useForm<EntryFormData>(
        initialData(entry, lockedZone?.id ?? defaultZoneId, zoneAttachments),
    );

    // Re-seed the form each time the dialog opens (create ↔ edit, or a different entry).
    useEffect(() => {
        if (open) {
            setData(initialData(entry, lockedZone?.id ?? defaultZoneId, zoneAttachments));
            clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, entry]);

    const hasPriority = data.type === 'MX' || data.type === 'SRV';

    const zoneId = data.dns_zone_id === '' ? null : Number(data.dns_zone_id);
    const zoneName = entry?.zone.name ?? (lockedZone ? lockedZone.name : zones.find((zone) => zone.id === zoneId)?.name);
    const targetAttachments = compatibleAttachments(zoneId != null ? (zoneAttachments[zoneId] ?? []) : [], data.type);

    // The zone is immutable after creation; zone-locked pages pin it on create too.
    const zoneLocked = entry !== null || lockedZone !== undefined;

    const changeZone = (value: string) => {
        // Changing the zone resets targeting to all compatible enabled attachments.
        setData((current) => ({
            ...current,
            dns_zone_id: value,
            zone_providers: compatibleAttachments(zoneAttachments[Number(value)] ?? [], current.type).map((attachment) => attachment.id),
        }));
    };

    const changeType = (type: RecordType) => {
        // Selection resets to the default (all compatible) whenever the type changes.
        setData((current) => ({
            ...current,
            type,
            zone_providers: compatibleAttachments(current.dns_zone_id === '' ? [] : (zoneAttachments[Number(current.dns_zone_id)] ?? []), type).map(
                (attachment) => attachment.id,
            ),
        }));
    };

    const toggleAttachment = (id: number, checked: boolean) => {
        setData('zone_providers', checked ? [...data.zone_providers, id] : data.zone_providers.filter((selected) => selected !== id));
    };

    const connectorByType = new Map(connectors.map((connector) => [connector.type, connector]));
    const showProxied = targetAttachments.some(
        (attachment) => data.zone_providers.includes(attachment.id) && connectorByType.get(attachment.providerType)?.capabilities.supportsProxied,
    );

    const submit = (event: FormEvent) => {
        event.preventDefault();

        transform((form) => ({
            // The zone is immutable — updates never send it.
            ...(entry ? {} : { dns_zone_id: form.dns_zone_id === '' ? null : Number(form.dns_zone_id) }),
            name: form.name.trim(),
            type: form.type,
            content: form.content.trim(),
            ttl: form.ttl === '' ? null : Number(form.ttl),
            priority: hasPriority && form.priority !== '' ? Number(form.priority) : null,
            proxied: showProxied ? form.proxied : false,
            comment: form.comment.trim() === '' ? null : form.comment.trim(),
            zone_providers: form.zone_providers,
        }));

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                onOpenChange(false);
                reset();
            },
        };

        if (entry) {
            put(route('entries.update', entry.id), options);
        } else {
            post(route('entries.store'), options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{entry ? 'Edit entry' : 'Add entry'}</DialogTitle>
                    <DialogDescription>
                        {entry
                            ? 'Changes are pushed to the selected providers below.'
                            : 'The record syncs to the selected providers — all compatible ones by default.'}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="entry-zone">Zone</Label>
                        {zoneLocked ? (
                            <p className="text-muted-foreground bg-muted/40 rounded-md border px-3 py-2 font-mono text-sm">{zoneName}</p>
                        ) : (
                            <>
                                <Select value={data.dns_zone_id} onValueChange={changeZone}>
                                    <SelectTrigger id="entry-zone" className="font-mono text-sm" aria-label="Zone">
                                        <SelectValue placeholder="Select a zone" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {zones.map((zone) => (
                                            <SelectItem key={zone.id} value={String(zone.id)} className="font-mono text-sm">
                                                {zone.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.dns_zone_id} />
                            </>
                        )}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="entry-name">Name</Label>
                        <Input
                            id="entry-name"
                            value={data.name}
                            onChange={(event) => setData('name', event.target.value)}
                            placeholder="www"
                            className="font-mono text-sm"
                            autoComplete="off"
                            autoFocus
                        />
                        <p className="text-muted-foreground text-xs">Relative to the zone — use @ for the zone apex.</p>
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="entry-type">Type</Label>
                            <Select value={data.type} onValueChange={(value) => changeType(value as RecordType)}>
                                <SelectTrigger id="entry-type">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {RECORD_TYPES.map((type) => (
                                        <SelectItem key={type} value={type}>
                                            {type}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.type} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="entry-ttl">TTL</Label>
                            <Input
                                id="entry-ttl"
                                type="number"
                                min={60}
                                max={86400}
                                value={data.ttl}
                                onChange={(event) => setData('ttl', event.target.value)}
                                placeholder="Auto"
                                className="tabular-nums"
                            />
                            <p className="text-muted-foreground text-xs">60–86400 seconds, empty = automatic</p>
                            <InputError message={errors.ttl} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="entry-content">{contentField[data.type].label}</Label>
                        <Input
                            id="entry-content"
                            value={data.content}
                            onChange={(event) => setData('content', event.target.value)}
                            placeholder={contentField[data.type].placeholder}
                            className="font-mono text-sm"
                            autoComplete="off"
                        />
                        <InputError message={errors.content} />
                    </div>

                    {hasPriority && (
                        <div className="grid gap-2">
                            <Label htmlFor="entry-priority">Priority</Label>
                            <Input
                                id="entry-priority"
                                type="number"
                                min={0}
                                max={65535}
                                value={data.priority}
                                onChange={(event) => setData('priority', event.target.value)}
                                placeholder="10"
                                className="tabular-nums"
                            />
                            <p className="text-muted-foreground text-xs">0–65535, lower is preferred</p>
                            <InputError message={errors.priority} />
                        </div>
                    )}

                    {showProxied && (
                        <div className="grid gap-2">
                            <label htmlFor="entry-proxied" className="flex items-start gap-3 rounded-lg border p-3">
                                <Checkbox
                                    id="entry-proxied"
                                    checked={data.proxied}
                                    onCheckedChange={(checked) => setData('proxied', checked === true)}
                                />
                                <span className="grid gap-0.5">
                                    <span className="flex items-center gap-1.5 text-sm leading-none font-medium">
                                        <Cloud className="size-3.5 text-orange-500 dark:text-orange-400" />
                                        Proxied
                                    </span>
                                    <span className="text-muted-foreground text-xs">Route traffic through the provider's proxy network.</span>
                                </span>
                            </label>
                            <InputError message={errors.proxied} />
                        </div>
                    )}

                    <div className="grid gap-2">
                        <Label htmlFor="entry-comment">Comment</Label>
                        <Input
                            id="entry-comment"
                            value={data.comment}
                            onChange={(event) => setData('comment', event.target.value)}
                            placeholder="Optional note (max 255 characters)"
                            maxLength={255}
                        />
                        <InputError message={errors.comment} />
                    </div>

                    <div
                        className={cn(
                            'rounded-lg border p-3',
                            zoneId != null && targetAttachments.length === 0 && 'border-amber-500/40 bg-amber-500/10',
                            (zoneId == null || targetAttachments.length > 0) && 'bg-muted/40',
                        )}
                    >
                        {zoneId == null ? (
                            <p className="text-muted-foreground text-xs">Select a zone to choose which providers this record syncs to.</p>
                        ) : targetAttachments.length > 0 ? (
                            <>
                                <p className="text-muted-foreground text-xs font-medium">Sync to providers</p>
                                <div className="mt-2 grid gap-2">
                                    {targetAttachments.map((attachment) => (
                                        <label
                                            key={attachment.id}
                                            htmlFor={`entry-attachment-${attachment.id}`}
                                            className="flex items-center gap-2.5 text-sm"
                                        >
                                            <Checkbox
                                                id={`entry-attachment-${attachment.id}`}
                                                checked={data.zone_providers.includes(attachment.id)}
                                                onCheckedChange={(checked) => toggleAttachment(attachment.id, checked === true)}
                                            />
                                            <ProviderMark type={attachment.providerType} className="text-muted-foreground size-3.5" />
                                            {attachment.providerName}
                                        </label>
                                    ))}
                                </div>
                                {data.zone_providers.length === 0 && (
                                    <p className="mt-2 flex items-start gap-2 text-xs text-amber-700 dark:text-amber-400">
                                        <TriangleAlert className="mt-px size-3.5 shrink-0" />
                                        No providers selected — the entry will only be stored locally
                                        {entry ? ' and removed from providers it currently syncs to' : ''}.
                                    </p>
                                )}
                                <InputError className="mt-2" message={errors.zone_providers} />
                            </>
                        ) : (
                            <p className="flex items-start gap-2 text-xs text-amber-700 dark:text-amber-400">
                                <TriangleAlert className="mt-px size-3.5 shrink-0" />
                                No enabled provider attached to this zone manages {data.type} records — this entry will not sync anywhere.
                            </p>
                        )}
                    </div>

                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="size-4 animate-spin" />}
                            {entry ? 'Save changes' : 'Create entry'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
