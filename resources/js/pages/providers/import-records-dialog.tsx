import { RecordTypeBadge } from '@/components/icons';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
import { LoaderCircle, TriangleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { Provider } from './types';

interface RemoteRecord {
    externalId: string;
    type: string;
    name: string;
    content: string;
    ttl: number | null;
    priority: number | null;
    proxied: boolean;
    status: 'new' | 'exists' | 'managed';
}

interface ImportRecordsDialogProps {
    provider: Provider;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

const statusBadge: Record<RemoteRecord['status'], { label: string; className: string }> = {
    new: { label: 'New', className: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400' },
    exists: { label: 'Will update', className: 'bg-amber-500/10 text-amber-700 dark:text-amber-400' },
    managed: { label: 'Managed', className: 'text-muted-foreground' },
};

function recordKey(record: RemoteRecord): string {
    return `${record.type}|${record.name}|${record.content}|${record.externalId}`;
}

export function ImportRecordsDialog({ provider, open, onOpenChange }: ImportRecordsDialogProps) {
    const [records, setRecords] = useState<RemoteRecord[]>([]);
    const [unmanagedCount, setUnmanagedCount] = useState(0);
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [loading, setLoading] = useState(false);
    const [importing, setImporting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!open) return;

        setLoading(true);
        setError(null);
        setRecords([]);
        setSelected(new Set());

        fetch(route('providers.import.records', provider.id), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then(async (response) => {
                const body = await response.json();

                if (!response.ok) {
                    throw new Error(body.message ?? `Failed to load records (HTTP ${response.status}).`);
                }

                const remote: RemoteRecord[] = body.records;
                setRecords(remote);
                setUnmanagedCount(body.unmanagedTypeCount ?? 0);
                // Preselect everything that is not already managed here.
                setSelected(new Set(remote.filter((record) => record.status !== 'managed').map(recordKey)));
            })
            .catch((fetchError: Error) => setError(fetchError.message))
            .finally(() => setLoading(false));
    }, [open, provider.id]);

    const selectable = records.filter((record) => record.status !== 'managed');
    const allSelected = selectable.length > 0 && selectable.every((record) => selected.has(recordKey(record)));

    const toggle = (record: RemoteRecord, checked: boolean) => {
        setSelected((current) => {
            const next = new Set(current);
            if (checked) {
                next.add(recordKey(record));
            } else {
                next.delete(recordKey(record));
            }

            return next;
        });
    };

    const toggleAll = (checked: boolean) => {
        setSelected(checked ? new Set(selectable.map(recordKey)) : new Set());
    };

    const submit = () => {
        const chosen = records.filter((record) => selected.has(recordKey(record)));

        setImporting(true);
        router.post(
            route('providers.import', provider.id),
            {
                records: chosen.map(({ externalId, type, name, content, ttl, priority, proxied }) => ({
                    externalId,
                    type,
                    name,
                    content,
                    ttl,
                    priority,
                    proxied,
                })),
            },
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                onFinish: () => setImporting(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Import records from {provider.name}</DialogTitle>
                    <DialogDescription>
                        Select the records to manage here. Existing entries are updated and linked — never duplicated. Imported entries stay assigned
                        to this provider only.
                    </DialogDescription>
                </DialogHeader>

                {loading && (
                    <div className="text-muted-foreground flex items-center justify-center gap-2 py-10 text-sm">
                        <LoaderCircle className="size-4 animate-spin" />
                        Fetching records from {provider.name}…
                    </div>
                )}

                {error && (
                    <div className="flex items-start gap-2 rounded-lg border border-red-500/40 bg-red-500/10 p-3 text-sm text-red-700 dark:text-red-400">
                        <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                        {error}
                    </div>
                )}

                {!loading && !error && records.length === 0 && (
                    <p className="text-muted-foreground py-8 text-center text-sm">No importable records found at this provider.</p>
                )}

                {!loading && !error && records.length > 0 && (
                    <>
                        <div className="text-muted-foreground flex items-center justify-between text-xs">
                            <label className="flex items-center gap-2">
                                <Checkbox checked={allSelected} onCheckedChange={(checked) => toggleAll(checked === true)} />
                                Select all
                            </label>
                            <span className="tabular-nums">
                                {selected.size} of {selectable.length} selected
                                {unmanagedCount > 0 && ` · ${unmanagedCount} record(s) hidden (types not managed by this provider)`}
                            </span>
                        </div>

                        <div className="max-h-80 divide-y overflow-y-auto rounded-lg border">
                            {records.map((record) => {
                                const badge = statusBadge[record.status];
                                const isManaged = record.status === 'managed';

                                return (
                                    <label
                                        key={recordKey(record)}
                                        className={`flex items-center gap-3 px-3 py-2 text-sm ${isManaged ? 'opacity-50' : 'hover:bg-muted/40 cursor-pointer'}`}
                                    >
                                        <Checkbox
                                            checked={selected.has(recordKey(record))}
                                            disabled={isManaged}
                                            onCheckedChange={(checked) => toggle(record, checked === true)}
                                        />
                                        <RecordTypeBadge type={record.type} />
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate font-mono text-[13px]">{record.name}</span>
                                            <span className="text-muted-foreground block truncate font-mono text-xs">{record.content}</span>
                                        </span>
                                        <Badge variant="secondary" className={badge.className}>
                                            {badge.label}
                                        </Badge>
                                    </label>
                                );
                            })}
                        </div>
                    </>
                )}

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={importing}>
                        Cancel
                    </Button>
                    <Button type="button" onClick={submit} disabled={importing || loading || selected.size === 0}>
                        {importing && <LoaderCircle className="size-4 animate-spin" />}
                        Import {selected.size > 0 ? `${selected.size} record${selected.size === 1 ? '' : 's'}` : ''}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
