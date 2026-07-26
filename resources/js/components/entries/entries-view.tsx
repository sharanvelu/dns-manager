import { EmptyEntriesIllustration } from '@/components/icons';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { TooltipProvider } from '@/components/ui/tooltip';
import { type ZoneCanMap } from '@/types';
import { Link, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ArrowUpDown, Plus, SearchX, Upload } from 'lucide-react';
import { useMemo, useState, type ReactNode } from 'react';
import { BulkActionsBar } from './bulk-actions-bar';
import { DeleteEntryDialog } from './delete-entry-dialog';
import { EntriesFilterBar, type ProviderOption } from './entries-filter-bar';
import { EntryFormDialog } from './entry-form-dialog';
import { EntryRow } from './entry-row';
import { ImportEntriesDialog } from './import-entries-dialog';
import { RefreshControls } from './refresh-controls';
import type {
    ConnectorInfo,
    EntriesScope,
    EntryFilters,
    EntryItem,
    PaginatedEntries,
    PaginatorLink,
    SortColumn,
    ZoneAttachment,
    ZoneOption,
} from './types';

function decodeLabel(label: string): string {
    return label.replace('&laquo;', '«').replace('&raquo;', '»').replace('&hellip;', '…');
}

function SortableHeader({ column, label, filters, scope }: { column: SortColumn; label: string; filters: EntryFilters; scope: EntriesScope }) {
    const active = (filters.sort ?? 'name') === column;
    const direction = active ? (filters.direction ?? 'asc') : undefined;
    const Icon = active ? (direction === 'desc' ? ArrowDown : ArrowUp) : ArrowUpDown;

    const toggle = () => {
        const params: Record<string, string> = Object.fromEntries(
            Object.entries({
                search: filters.search ?? '',
                type: filters.type ?? '',
                provider: filters.provider ?? '',
                status: filters.status ?? '',
                zone: scope.zone ? '' : (filters.zone ?? ''),
            }).filter(([, value]) => value !== ''),
        );

        params.sort = column;
        params.direction = active && direction === 'asc' ? 'desc' : 'asc';

        router.get(scope.baseUrl, params, { preserveState: true, preserveScroll: true, replace: true });
    };

    return (
        <th className="px-4 py-2.5 font-medium" aria-sort={active ? (direction === 'desc' ? 'descending' : 'ascending') : 'none'}>
            <button
                type="button"
                onClick={toggle}
                className={`hover:text-foreground -mx-1 inline-flex items-center gap-1 rounded px-1 transition-colors ${active ? 'text-foreground' : ''}`}
                aria-label={`Sort by ${label}`}
            >
                {label}
                <Icon className={`size-3.5 ${active ? '' : 'text-muted-foreground/50'}`} />
            </button>
        </th>
    );
}

function Pagination({ links, total, currentPage, lastPage }: { links: PaginatorLink[]; total: number; currentPage: number; lastPage: number }) {
    if (lastPage <= 1) return null;

    return (
        <div className="flex flex-wrap items-center justify-between gap-3">
            <p className="text-muted-foreground text-xs tabular-nums">
                Page {currentPage} of {lastPage} · {total} {total === 1 ? 'entry' : 'entries'}
            </p>
            <nav className="flex flex-wrap items-center gap-1" aria-label="Pagination">
                {links.map((link, index) => (
                    <Button
                        key={`${link.label}-${index}`}
                        variant={link.active ? 'default' : 'outline'}
                        size="sm"
                        className="h-8 min-w-8 px-2.5 text-xs tabular-nums"
                        disabled={link.url === null}
                        onClick={() => {
                            if (link.url) {
                                router.get(link.url, {}, { preserveState: true, preserveScroll: true });
                            }
                        }}
                    >
                        {decodeLabel(link.label)}
                    </Button>
                ))}
            </nav>
        </div>
    );
}

export interface EntriesViewProps {
    scope: EntriesScope;
    entries: PaginatedEntries;
    filters: EntryFilters;
    /** Zone mode: pass [scope.zone]. */
    zones: ZoneOption[];
    zoneAttachments: Record<number, ZoneAttachment[]>;
    connectors: ConnectorInfo[];
    /** Per-zone abilities keyed by zone id — covers exactly the zones in `zones`. */
    zoneCan: ZoneCanMap;
    /** Optional left slot of the toolbar row — the page title block. */
    header?: ReactNode;
}

/**
 * The entire entries page body: toolbar, filter bar, table, bulk actions,
 * pagination, empty states, and dialogs. Serves the global /entries page and
 * zone-scoped records pages — every request targets scope.baseUrl.
 */
export function EntriesView({ scope, entries, filters, zones, zoneAttachments, connectors, zoneCan, header }: EntriesViewProps) {
    const [formOpen, setFormOpen] = useState(false);
    const [importOpen, setImportOpen] = useState(false);
    const [editingEntry, setEditingEntry] = useState<EntryItem | null>(null);
    const [deletingEntry, setDeletingEntry] = useState<EntryItem | null>(null);
    // Selected entry id → its zone id, so bulk actions know whether the
    // selection stays within a single zone (selection survives pagination).
    const [selected, setSelected] = useState<Map<number, number>>(new Map());

    const zoneLocked = scope.zone !== undefined;
    const hasActiveFilters = Boolean(filters.search || filters.type || filters.provider || filters.status || (!zoneLocked && filters.zone));
    const isEmpty = entries.data.length === 0;

    // Zones the user can create/import records into — the only ones dialogs offer.
    const manageableZones = useMemo(() => zones.filter((zone) => zoneCan[zone.id]?.manageRecords), [zones, zoneCan]);
    const canManageAny = manageableZones.length > 0;
    const noZones = !zoneLocked && zones.length === 0;

    // Create/import default: locked zone, else the active zone filter, else the only manageable zone.
    const defaultZoneId = scope.zone?.id ?? (filters.zone ? Number(filters.zone) : manageableZones.length === 1 ? manageableZones[0].id : undefined);

    const providerOptions = useMemo<ProviderOption[]>(() => {
        const attachments = zoneLocked ? (zoneAttachments[scope.zone!.id] ?? []) : Object.values(zoneAttachments).flat();
        const names = new Map<number, string>();
        attachments.forEach((attachment) => names.set(attachment.providerId, attachment.providerName));

        return [...names.entries()].map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name));
    }, [zoneAttachments, zoneLocked, scope.zone]);

    // Only rows the user can manage are selectable.
    const selectablePageEntries = entries.data.filter((entry) => zoneCan[entry.zone.id]?.manageRecords);
    const allPageSelected = selectablePageEntries.length > 0 && selectablePageEntries.every((entry) => selected.has(entry.id));

    const selectionZoneIds = new Set(selected.values());
    const selectionZoneId = selectionZoneIds.size === 1 ? [...selectionZoneIds][0] : null;

    const toggleEntry = (entry: EntryItem, checked: boolean) => {
        if (!zoneCan[entry.zone.id]?.manageRecords) return;

        setSelected((current) => {
            const next = new Map(current);
            if (checked) {
                next.set(entry.id, entry.zone.id);
            } else {
                next.delete(entry.id);
            }

            return next;
        });
    };

    const togglePage = (checked: boolean) => {
        setSelected((current) => {
            const next = new Map(current);
            selectablePageEntries.forEach((entry) => (checked ? next.set(entry.id, entry.zone.id) : next.delete(entry.id)));

            return next;
        });
    };

    const openCreate = () => {
        setEditingEntry(null);
        setFormOpen(true);
    };

    const openEdit = (entry: EntryItem) => {
        setEditingEntry(entry);
        setFormOpen(true);
    };

    const refreshStorageKey = scope.baseUrl === '/entries' ? 'dns-manager:entries-auto-reload' : `dns-manager:entries-auto-reload:${scope.baseUrl}`;

    return (
        <TooltipProvider delayDuration={200}>
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    {header ?? <div />}
                    <div className="flex flex-wrap items-center gap-2">
                        <RefreshControls storageKey={refreshStorageKey} />
                        {canManageAny && (
                            <>
                                <Button variant="outline" onClick={() => setImportOpen(true)} disabled={noZones}>
                                    <Upload className="size-4" />
                                    Import CSV
                                </Button>
                                <Button onClick={openCreate} disabled={noZones}>
                                    <Plus className="size-4" />
                                    Add entry
                                </Button>
                            </>
                        )}
                    </div>
                </div>

                <EntriesFilterBar scope={scope} filters={filters} zones={zones} providers={providerOptions} />

                {canManageAny && selected.size > 0 && (
                    <BulkActionsBar
                        selectedIds={[...selected.keys()]}
                        selectionZoneId={selectionZoneId}
                        attachments={selectionZoneId !== null ? (zoneAttachments[selectionZoneId] ?? []) : []}
                        onClear={() => setSelected(new Map())}
                    />
                )}

                {isEmpty ? (
                    hasActiveFilters ? (
                        <div className="flex flex-1 flex-col items-center justify-center gap-3 rounded-xl border border-dashed py-16 text-center">
                            <SearchX className="text-muted-foreground/60 size-8" />
                            <div>
                                <p className="text-sm font-medium">No entries match your filters</p>
                                <p className="text-muted-foreground mt-1 text-sm">Try adjusting the search or filter criteria.</p>
                            </div>
                            <Button variant="outline" size="sm" onClick={() => router.get(scope.baseUrl, {}, { preserveState: false })}>
                                Clear filters
                            </Button>
                        </div>
                    ) : noZones ? (
                        <div className="flex flex-1 flex-col items-center justify-center gap-4 rounded-xl border border-dashed py-16 text-center">
                            <EmptyEntriesIllustration className="text-muted-foreground" />
                            <div>
                                <p className="text-sm font-medium">Create a zone first</p>
                                <p className="text-muted-foreground mt-1 max-w-sm text-sm">
                                    DNS entries live in zones. Create a zone, attach providers to it, then add records.
                                </p>
                            </div>
                            <Button asChild>
                                <Link href="/zones">Go to zones</Link>
                            </Button>
                        </div>
                    ) : (
                        <div className="flex flex-1 flex-col items-center justify-center gap-4 rounded-xl border border-dashed py-16 text-center">
                            <EmptyEntriesIllustration className="text-muted-foreground" />
                            <div>
                                <p className="text-sm font-medium">{scope.zone ? `No records in ${scope.zone.name} yet` : 'No DNS entries yet'}</p>
                                <p className="text-muted-foreground mt-1 max-w-sm text-sm">
                                    Create your first record and it will sync automatically to the zone's enabled providers.
                                </p>
                            </div>
                            {canManageAny && (
                                <Button onClick={openCreate}>
                                    <Plus className="size-4" />
                                    Add your first entry
                                </Button>
                            )}
                        </div>
                    )
                ) : (
                    <>
                        <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="bg-muted/40 text-muted-foreground border-b text-left text-xs">
                                        {canManageAny && (
                                            <th className="py-2.5 pl-4">
                                                <Checkbox
                                                    checked={allPageSelected}
                                                    onCheckedChange={(checked) => togglePage(checked === true)}
                                                    aria-label="Select all entries on this page"
                                                />
                                            </th>
                                        )}
                                        <SortableHeader column="name" label="Name" filters={filters} scope={scope} />
                                        {!zoneLocked && <SortableHeader column="zone" label="Zone" filters={filters} scope={scope} />}
                                        <SortableHeader column="type" label="Type" filters={filters} scope={scope} />
                                        <SortableHeader column="content" label="Content" filters={filters} scope={scope} />
                                        <SortableHeader column="ttl" label="TTL" filters={filters} scope={scope} />
                                        <th className="px-4 py-2.5 font-medium">Providers</th>
                                        <SortableHeader column="updated" label="Updated" filters={filters} scope={scope} />
                                        <th className="px-2 py-2.5">
                                            <span className="sr-only">Actions</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {entries.data.map((entry) => (
                                        <EntryRow
                                            key={entry.id}
                                            entry={entry}
                                            canManage={!!zoneCan[entry.zone.id]?.manageRecords}
                                            canViewActivity={!!zoneCan[entry.zone.id]?.viewActivity}
                                            showSelection={canManageAny}
                                            showZone={!zoneLocked}
                                            selected={selected.has(entry.id)}
                                            onSelect={toggleEntry}
                                            onEdit={openEdit}
                                            onDelete={setDeletingEntry}
                                        />
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <Pagination links={entries.links} total={entries.total} currentPage={entries.current_page} lastPage={entries.last_page} />
                    </>
                )}
            </div>

            <EntryFormDialog
                open={formOpen}
                onOpenChange={setFormOpen}
                entry={editingEntry}
                lockedZone={scope.zone}
                zones={manageableZones}
                zoneAttachments={zoneAttachments}
                connectors={connectors}
                defaultZoneId={defaultZoneId}
            />
            <ImportEntriesDialog
                open={importOpen}
                onOpenChange={setImportOpen}
                lockedZone={scope.zone}
                zones={manageableZones}
                defaultZoneId={defaultZoneId}
            />
            <DeleteEntryDialog entry={deletingEntry} onOpenChange={(open) => !open && setDeletingEntry(null)} />
        </TooltipProvider>
    );
}
