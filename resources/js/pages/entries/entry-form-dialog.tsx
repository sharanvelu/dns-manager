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
import { RECORD_TYPES, type ConnectorInfo, type EntryItem, type ProviderInfo, type RecordType } from './types';

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
    name: string;
    type: RecordType;
    content: string;
    ttl: string;
    priority: string;
    proxied: boolean;
    comment: string;
    providers: number[];
};

function compatibleProviders(providers: ProviderInfo[], type: RecordType): ProviderInfo[] {
    return providers.filter((provider) => provider.enabled && provider.managedRecordTypes.includes(type));
}

function initialData(entry: EntryItem | null, providers: ProviderInfo[]): EntryFormData {
    const type = (entry?.type as RecordType) ?? 'A';

    return {
        name: entry?.name ?? '',
        type,
        content: entry?.content ?? '',
        ttl: entry?.ttl != null ? String(entry.ttl) : '',
        priority: entry?.priority != null ? String(entry.priority) : '',
        proxied: entry?.proxied ?? false,
        comment: entry?.comment ?? '',
        // Edit: current assignment. Create: every enabled provider managing the type.
        providers: entry
            ? entry.syncStates.filter((state) => state.status !== 'deleting').map((state) => state.provider.id)
            : compatibleProviders(providers, type).map((provider) => provider.id),
    };
}

interface EntryFormDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** null = create mode, otherwise the entry being edited. */
    entry: EntryItem | null;
    providers: ProviderInfo[];
    connectors: ConnectorInfo[];
}

export function EntryFormDialog({ open, onOpenChange, entry, providers, connectors }: EntryFormDialogProps) {
    const { data, setData, post, put, processing, errors, reset, clearErrors, transform } = useForm<EntryFormData>(initialData(entry, providers));

    // Re-seed the form each time the dialog opens (create ↔ edit, or a different entry).
    useEffect(() => {
        if (open) {
            setData(initialData(entry, providers));
            clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, entry]);

    const hasPriority = data.type === 'MX' || data.type === 'SRV';

    const targetProviders = compatibleProviders(providers, data.type);

    const changeType = (type: RecordType) => {
        setData('type', type);
        // Selection resets to the default (all compatible) whenever the type changes.
        setData(
            'providers',
            compatibleProviders(providers, type).map((provider) => provider.id),
        );
    };

    const toggleProvider = (id: number, checked: boolean) => {
        setData('providers', checked ? [...data.providers, id] : data.providers.filter((selected) => selected !== id));
    };

    const connectorByType = new Map(connectors.map((connector) => [connector.type, connector]));
    const showProxied = targetProviders.some((provider) => connectorByType.get(provider.type)?.capabilities.supportsProxied);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        transform((form) => ({
            name: form.name.trim(),
            type: form.type,
            content: form.content.trim(),
            ttl: form.ttl === '' ? null : Number(form.ttl),
            priority: hasPriority && form.priority !== '' ? Number(form.priority) : null,
            proxied: showProxied ? form.proxied : false,
            comment: form.comment.trim() === '' ? null : form.comment.trim(),
            providers: form.providers,
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
                        <Label htmlFor="entry-name">Name</Label>
                        <Input
                            id="entry-name"
                            value={data.name}
                            onChange={(event) => setData('name', event.target.value)}
                            placeholder="app.example.com"
                            className="font-mono text-sm"
                            autoComplete="off"
                            autoFocus
                        />
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
                            targetProviders.length === 0 && 'border-amber-500/40 bg-amber-500/10',
                            targetProviders.length > 0 && 'bg-muted/40',
                        )}
                    >
                        {targetProviders.length > 0 ? (
                            <>
                                <p className="text-muted-foreground text-xs font-medium">Sync to providers</p>
                                <div className="mt-2 grid gap-2">
                                    {targetProviders.map((provider) => (
                                        <label
                                            key={provider.id}
                                            htmlFor={`entry-provider-${provider.id}`}
                                            className="flex items-center gap-2.5 text-sm"
                                        >
                                            <Checkbox
                                                id={`entry-provider-${provider.id}`}
                                                checked={data.providers.includes(provider.id)}
                                                onCheckedChange={(checked) => toggleProvider(provider.id, checked === true)}
                                            />
                                            <ProviderMark type={provider.type} className="text-muted-foreground size-3.5" />
                                            {provider.name}
                                        </label>
                                    ))}
                                </div>
                                {data.providers.length === 0 && (
                                    <p className="mt-2 flex items-start gap-2 text-xs text-amber-700 dark:text-amber-400">
                                        <TriangleAlert className="mt-px size-3.5 shrink-0" />
                                        No providers selected — the entry will only be stored locally
                                        {entry ? ' and removed from providers it currently syncs to' : ''}.
                                    </p>
                                )}
                                <InputError className="mt-2" message={errors.providers} />
                            </>
                        ) : (
                            <p className="flex items-start gap-2 text-xs text-amber-700 dark:text-amber-400">
                                <TriangleAlert className="mt-px size-3.5 shrink-0" />
                                No enabled provider manages {data.type} records — this entry will not sync anywhere.
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
