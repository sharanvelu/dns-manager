import { RecordTypeBadge } from '@/components/icons';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { router } from '@inertiajs/react';
import { ChevronRight, LoaderCircle, Search, TriangleAlert } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import {
    applySelectAll,
    EMPTY_IMPORT_FILTERS,
    filterExcluded,
    filterRecords,
    importPlan,
    initialSelection,
    recordKey,
    recordTypes,
    rowState,
    selectAllState,
    statusCounts,
    submitLabel,
    type ExcludedReason,
    type ExcludedRecord,
    type ImportFilters,
    type ImportStatus,
    type RemoteRecord,
} from './import-records';

interface ImportRecordsDialogProps {
    zone: { id: number; name: string };
    attachment: { id: number; providerName: string };
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

/** Rows rendered at once — large zones page in more on demand so the dialog stays responsive. */
const RENDER_STEP = 200;
/** Mirrors the `records` max on the import endpoint. */
const MAX_IMPORT = 1000;
const ALL_TYPES = '__all';

const statusBadge: Record<ImportStatus, { label: string; className: string }> = {
    new: { label: 'New', className: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400' },
    exists: { label: 'Will update', className: 'bg-amber-500/10 text-amber-700 dark:text-amber-400' },
    managed: { label: 'Managed', className: 'text-muted-foreground' },
};

const untrackBadge = { label: 'Stop tracking', className: 'bg-muted text-foreground' };

const statusFilterLabel: Record<ImportStatus, string> = {
    new: 'New',
    exists: 'Matches existing',
    managed: 'Managed',
};

function reasonLabel(reason: ExcludedReason, providerName: string, zoneName: string): string {
    return reason === 'out-of-zone' ? `Outside ${zoneName}` : `Type not managed by ${providerName}`;
}

/**
 * Import a zone attachment's remote records into the zone. Names arrive
 * zone-relative. A ticked row ends up managed from this attachment: new /
 * matching rows import, already-managed rows start ticked and stop being
 * tracked when unticked (record kept at the provider — never deleted). Rows
 * can be searched and filtered by type/status; records that cannot be
 * imported (unmanaged types, outside the zone) are listed in a collapsed,
 * non-selectable section with the reason.
 */
export function ImportRecordsDialog({ zone, attachment, open, onOpenChange }: ImportRecordsDialogProps) {
    const [records, setRecords] = useState<RemoteRecord[]>([]);
    const [excluded, setExcluded] = useState<ExcludedRecord[]>([]);
    const [excludedTotal, setExcludedTotal] = useState(0);
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [filters, setFilters] = useState<ImportFilters>(EMPTY_IMPORT_FILTERS);
    const [renderLimit, setRenderLimit] = useState(RENDER_STEP);
    const [loading, setLoading] = useState(false);
    const [importing, setImporting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [submitError, setSubmitError] = useState<string | null>(null);
    // Paused attachments can import but never untrack (paused invariant).
    const [canUntrack, setCanUntrack] = useState(true);

    useEffect(() => {
        if (!open) return;

        setLoading(true);
        setError(null);
        setSubmitError(null);
        setRecords([]);
        setExcluded([]);
        setExcludedTotal(0);
        setSelected(new Set());
        setFilters(EMPTY_IMPORT_FILTERS);

        fetch(route('zones.import.records', [zone.id, attachment.id]), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then(async (response) => {
                const body = await response.json();

                if (!response.ok) {
                    throw new Error(body.message ?? `Failed to load records (HTTP ${response.status}).`);
                }

                const remote: RemoteRecord[] = body.records;
                const skipped: ExcludedRecord[] = body.excluded ?? [];
                setRecords(remote);
                setExcluded(skipped);
                setExcludedTotal(body.excludedTotal ?? skipped.length);
                setCanUntrack(body.attachmentActive !== false);
                // Everything starts ticked: import the rest, keep tracking what is tracked.
                setSelected(initialSelection(remote));
            })
            .catch((fetchError: Error) => setError(fetchError.message))
            .finally(() => setLoading(false));
    }, [open, zone.id, attachment.id]);

    const updateFilters = (change: Partial<ImportFilters> | ImportFilters) => {
        setFilters((current) => ({ ...current, ...change }));
        setRenderLimit(RENDER_STEP);
    };

    const types = useMemo(() => recordTypes(records, excluded), [records, excluded]);
    const visible = useMemo(() => filterRecords(records, filters), [records, filters]);
    const visibleExcluded = useMemo(() => filterExcluded(excluded, filters), [excluded, filters]);
    const counts = useMemo(() => statusCounts(records, filters), [records, filters]);
    const countsTotal = counts.new + counts.exists + counts.managed;

    const visibleToggleable = visible.filter((record) => rowState(record, canUntrack) !== 'locked');
    const headerState = selectAllState(visible, selected, canUntrack);
    const plan = useMemo(() => importPlan(records, selected, canUntrack), [records, selected, canUntrack]);
    const visiblePlan = importPlan(visible, selected, canUntrack);
    const hiddenChanges = plan.toImport.length + plan.toUntrack.length - (visiblePlan.toImport.length + visiblePlan.toUntrack.length);
    const nothingToDo = plan.toImport.length === 0 && plan.toUntrack.length === 0;
    const overLimit = plan.toImport.length > MAX_IMPORT || plan.toUntrack.length > MAX_IMPORT;
    const hasFilters = filters.search !== '' || filters.type !== '' || filters.status !== 'all';

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

    const submit = () => {
        setImporting(true);
        setSubmitError(null);
        router.post(
            route('zones.import', [zone.id, attachment.id]),
            {
                untrack: plan.toUntrack,
                records: plan.toImport.map(({ externalId, type, name, content, ttl, priority, proxied }) => ({
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
                onError: (errors) => setSubmitError(Object.values(errors)[0] ?? 'The import failed.'),
                onFinish: () => setImporting(false),
            },
        );
    };

    const loaded = !loading && !error;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[90dvh] flex-col sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Import records from {attachment.providerName}</DialogTitle>
                    <DialogDescription>
                        Tick the records to manage in <span className="font-mono">{zone.name}</span>. Existing entries are updated and linked — never
                        duplicated. Imported entries stay assigned to this provider only. Untick a managed record to stop tracking it from{' '}
                        {attachment.providerName} — the record stays at the provider.
                    </DialogDescription>
                </DialogHeader>

                {loading && (
                    <div className="text-muted-foreground flex items-center justify-center gap-2 py-10 text-sm">
                        <LoaderCircle className="size-4 animate-spin" />
                        Fetching records from {attachment.providerName}…
                    </div>
                )}

                {error && (
                    <div className="flex items-start gap-2 rounded-lg border border-red-500/40 bg-red-500/10 p-3 text-sm text-red-700 dark:text-red-400">
                        <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                        {error}
                    </div>
                )}

                {loaded && records.length === 0 && (
                    <p className="text-muted-foreground py-6 text-center text-sm">
                        No importable records found in this zone at {attachment.providerName}.
                    </p>
                )}

                {loaded && (records.length > 0 || excluded.length > 0) && (
                    <div className="flex min-h-0 flex-1 flex-col gap-3">
                        <div className="flex flex-wrap items-center gap-2">
                            <div className="relative min-w-48 flex-1">
                                <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2" />
                                <Input
                                    value={filters.search}
                                    onChange={(event) => updateFilters({ search: event.target.value })}
                                    placeholder="Search name or content…"
                                    aria-label="Search records"
                                    className="h-8 pl-8 text-xs"
                                />
                            </div>
                            <Select
                                value={filters.type === '' ? ALL_TYPES : filters.type}
                                onValueChange={(value) => updateFilters({ type: value === ALL_TYPES ? '' : value })}
                            >
                                <SelectTrigger className="h-8 w-32 text-xs" aria-label="Filter by type">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL_TYPES} className="text-xs">
                                        All types
                                    </SelectItem>
                                    {types.map((type) => (
                                        <SelectItem key={type} value={type} className="font-mono text-xs">
                                            {type}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        {records.length > 0 && (
                            <ToggleGroup
                                type="single"
                                variant="outline"
                                size="sm"
                                className="flex-wrap justify-start"
                                value={filters.status}
                                onValueChange={(value) => value && updateFilters({ status: value as ImportFilters['status'] })}
                                aria-label="Filter by status"
                            >
                                <ToggleGroupItem value="all" className="gap-1.5 px-2.5 text-xs">
                                    All <span className="text-muted-foreground tabular-nums">{countsTotal}</span>
                                </ToggleGroupItem>
                                {(Object.keys(statusFilterLabel) as ImportStatus[]).map((status) => (
                                    <ToggleGroupItem key={status} value={status} className="gap-1.5 px-2.5 text-xs">
                                        {statusFilterLabel[status]} <span className="text-muted-foreground tabular-nums">{counts[status]}</span>
                                    </ToggleGroupItem>
                                ))}
                            </ToggleGroup>
                        )}

                        {records.length > 0 && (
                            <div className="text-muted-foreground flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-xs">
                                <label className="flex items-center gap-2">
                                    <Checkbox
                                        checked={headerState}
                                        disabled={visibleToggleable.length === 0}
                                        onCheckedChange={() =>
                                            setSelected((current) => applySelectAll(current, visible, headerState !== true, canUntrack))
                                        }
                                        aria-label={hasFilters ? 'Select all matching records' : 'Select all records'}
                                    />
                                    {hasFilters ? `Select all ${visibleToggleable.length} matching` : 'Select all'}
                                </label>
                                <span className="tabular-nums">
                                    {plan.toImport.length} to import
                                    {plan.toUntrack.length > 0 && ` · ${plan.toUntrack.length} to stop tracking`}
                                    {hasFilters && hiddenChanges > 0 && ` (${hiddenChanges} hidden by filters)`}
                                </span>
                            </div>
                        )}

                        {records.length > 0 && (
                            <div className="max-h-[45dvh] min-h-24 divide-y overflow-y-auto overscroll-contain rounded-lg border">
                                {visible.length === 0 && (
                                    <div className="text-muted-foreground flex flex-col items-center gap-2 py-8 text-center text-sm">
                                        No records match these filters.
                                        <Button variant="outline" size="sm" onClick={() => updateFilters(EMPTY_IMPORT_FILTERS)}>
                                            Clear filters
                                        </Button>
                                    </div>
                                )}
                                {visible.slice(0, renderLimit).map((record) => {
                                    const state = rowState(record, canUntrack);
                                    const locked = state === 'locked';
                                    const ticked = locked || selected.has(recordKey(record));
                                    const untracking = state === 'tracked' && !ticked;
                                    const badge = untracking ? untrackBadge : statusBadge[record.status];

                                    return (
                                        <label
                                            key={recordKey(record)}
                                            title={
                                                locked
                                                    ? `${attachment.providerName} is paused — enable it to stop tracking records`
                                                    : state === 'tracked'
                                                      ? `Managed from ${attachment.providerName} — untick to stop tracking (the record stays at the provider)`
                                                      : undefined
                                            }
                                            className={`flex items-center gap-3 px-3 py-2 text-sm ${locked ? 'opacity-60' : 'hover:bg-muted/40 cursor-pointer'} ${untracking ? 'text-muted-foreground' : ''}`}
                                        >
                                            <Checkbox
                                                checked={ticked}
                                                disabled={locked}
                                                onCheckedChange={(checked) => toggle(record, checked === true)}
                                                aria-label={`${record.type} ${record.name}`}
                                            />
                                            <RecordTypeBadge type={record.type} />
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate font-mono text-[13px]">{record.name}</span>
                                                <span className="text-muted-foreground block truncate font-mono text-xs">{record.content}</span>
                                            </span>
                                            <Badge variant="secondary" className={`shrink-0 ${badge.className}`}>
                                                {badge.label}
                                            </Badge>
                                        </label>
                                    );
                                })}
                                {visible.length > renderLimit && (
                                    <div className="flex items-center justify-between gap-3 px-3 py-2 text-xs">
                                        <span className="text-muted-foreground tabular-nums">
                                            Showing {renderLimit} of {visible.length} — search or filter to narrow down.
                                        </span>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="h-7 text-xs"
                                            onClick={() => setRenderLimit((limit) => limit + RENDER_STEP)}
                                        >
                                            Show more
                                        </Button>
                                    </div>
                                )}
                            </div>
                        )}

                        {excludedTotal > 0 && (
                            <Collapsible className="group/excluded rounded-lg border">
                                <CollapsibleTrigger className="text-muted-foreground hover:text-foreground flex w-full cursor-pointer items-center gap-2 px-3 py-2 text-left text-xs">
                                    <ChevronRight className="size-3.5 shrink-0 transition-transform group-data-[state=open]/excluded:rotate-90" />
                                    <span className="flex-1">
                                        <span className="tabular-nums">{excludedTotal}</span> record{excludedTotal === 1 ? '' : 's'} can't be imported
                                        {hasFilters && excluded.length > 0 && (
                                            <span className="tabular-nums"> · {visibleExcluded.length} match the filters</span>
                                        )}
                                    </span>
                                </CollapsibleTrigger>
                                <CollapsibleContent>
                                    <div className="max-h-48 divide-y overflow-y-auto overscroll-contain border-t">
                                        {visibleExcluded.map((record, index) => (
                                            <div
                                                key={`${record.reason}|${record.type}|${record.name}|${index}`}
                                                className="flex items-center gap-3 px-3 py-2 text-sm"
                                            >
                                                <RecordTypeBadge type={record.type} />
                                                <span className="min-w-0 flex-1">
                                                    <span className="block truncate font-mono text-[13px]">{record.name}</span>
                                                    <span className="text-muted-foreground block truncate font-mono text-xs">{record.content}</span>
                                                </span>
                                                <span className="text-muted-foreground shrink-0 text-xs">
                                                    {reasonLabel(record.reason, attachment.providerName, zone.name)}
                                                </span>
                                            </div>
                                        ))}
                                        {excludedTotal > excluded.length && (
                                            <p className="text-muted-foreground px-3 py-2 text-xs tabular-nums">
                                                Showing the first {excluded.length} of {excludedTotal}.
                                            </p>
                                        )}
                                    </div>
                                </CollapsibleContent>
                            </Collapsible>
                        )}
                    </div>
                )}

                {plan.toUntrack.length > 0 && (
                    <p className="bg-muted/40 text-muted-foreground rounded-lg border p-2.5 text-xs">
                        {plan.toUntrack.length} managed record{plan.toUntrack.length === 1 ? '' : 's'} will stop being tracked from{' '}
                        {attachment.providerName}. Nothing is deleted — the record{plan.toUntrack.length === 1 ? ' stays' : 's stay'} at the provider,
                        and the entr{plan.toUntrack.length === 1 ? 'y stays' : 'ies stay'} in the app (local-only if {attachment.providerName} was the
                        only provider).
                    </p>
                )}

                {overLimit && (
                    <p className="rounded-lg border border-amber-500/40 bg-amber-500/10 p-2.5 text-xs text-amber-700 dark:text-amber-400">
                        At most {MAX_IMPORT} records can be imported or untracked at once — narrow the selection and work in batches.
                    </p>
                )}

                {submitError && (
                    <div className="flex items-start gap-2 rounded-lg border border-red-500/40 bg-red-500/10 p-3 text-sm text-red-700 dark:text-red-400">
                        <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                        {submitError}
                    </div>
                )}

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={importing}>
                        Cancel
                    </Button>
                    <Button type="button" onClick={submit} disabled={importing || loading || nothingToDo || overLimit}>
                        {importing && <LoaderCircle className="size-4 animate-spin" />}
                        {submitLabel(plan)}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
