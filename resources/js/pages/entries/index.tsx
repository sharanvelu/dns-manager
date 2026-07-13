import { EmptyEntriesIllustration } from '@/components/icons';
import { Button } from '@/components/ui/button';
import { TooltipProvider } from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Plus, SearchX } from 'lucide-react';
import { useState } from 'react';
import { DeleteEntryDialog } from './delete-entry-dialog';
import { EntriesFilterBar } from './entries-filter-bar';
import { EntryFormDialog } from './entry-form-dialog';
import { EntryRow } from './entry-row';
import { FlashToast } from './flash-toast';
import type { EntriesPageProps, EntryItem, PaginatorLink } from './types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'DNS Entries',
        href: '/entries',
    },
];

function decodeLabel(label: string): string {
    return label.replace('&laquo;', '«').replace('&raquo;', '»').replace('&hellip;', '…');
}

function Pagination({ links, total, currentPage, lastPage }: { links: PaginatorLink[]; total: number; currentPage: number; lastPage: number }) {
    if (lastPage <= 1) return null;

    return (
        <div className="flex flex-wrap items-center justify-between gap-3">
            <p className="text-xs text-muted-foreground tabular-nums">
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

export default function EntriesIndex({ entries, filters, providers, connectors }: EntriesPageProps) {
    const [formOpen, setFormOpen] = useState(false);
    const [editingEntry, setEditingEntry] = useState<EntryItem | null>(null);
    const [deletingEntry, setDeletingEntry] = useState<EntryItem | null>(null);

    const hasActiveFilters = Boolean(filters.search || filters.type || filters.provider || filters.status);
    const isEmpty = entries.data.length === 0;

    const openCreate = () => {
        setEditingEntry(null);
        setFormOpen(true);
    };

    const openEdit = (entry: EntryItem) => {
        setEditingEntry(entry);
        setFormOpen(true);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="DNS Entries" />
            <TooltipProvider delayDuration={200}>
                <div className="flex h-full flex-1 flex-col gap-4 p-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h1 className="text-xl font-semibold tracking-tight">DNS Entries</h1>
                            <p className="text-sm text-muted-foreground">Manage records and keep them in sync across providers.</p>
                        </div>
                        <Button onClick={openCreate}>
                            <Plus className="size-4" />
                            Add entry
                        </Button>
                    </div>

                    <EntriesFilterBar filters={filters} providers={providers} />

                    {isEmpty ? (
                        hasActiveFilters ? (
                            <div className="flex flex-1 flex-col items-center justify-center gap-3 rounded-xl border border-dashed py-16 text-center">
                                <SearchX className="size-8 text-muted-foreground/60" />
                                <div>
                                    <p className="text-sm font-medium">No entries match your filters</p>
                                    <p className="mt-1 text-sm text-muted-foreground">Try adjusting the search or filter criteria.</p>
                                </div>
                                <Button variant="outline" size="sm" onClick={() => router.get('/entries', {}, { preserveState: false })}>
                                    Clear filters
                                </Button>
                            </div>
                        ) : (
                            <div className="flex flex-1 flex-col items-center justify-center gap-4 rounded-xl border border-dashed py-16 text-center">
                                <EmptyEntriesIllustration className="text-muted-foreground" />
                                <div>
                                    <p className="text-sm font-medium">No DNS entries yet</p>
                                    <p className="mt-1 max-w-sm text-sm text-muted-foreground">
                                        Create your first record and it will sync automatically to every enabled provider.
                                    </p>
                                </div>
                                <Button onClick={openCreate}>
                                    <Plus className="size-4" />
                                    Add your first entry
                                </Button>
                            </div>
                        )
                    ) : (
                        <>
                            <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/40 text-left text-xs text-muted-foreground">
                                            <th className="px-4 py-2.5 font-medium">Name</th>
                                            <th className="px-4 py-2.5 font-medium">Type</th>
                                            <th className="px-4 py-2.5 font-medium">Content</th>
                                            <th className="px-4 py-2.5 font-medium">TTL</th>
                                            <th className="px-4 py-2.5 font-medium">Providers</th>
                                            <th className="px-4 py-2.5 font-medium">Updated</th>
                                            <th className="px-2 py-2.5">
                                                <span className="sr-only">Actions</span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {entries.data.map((entry) => (
                                            <EntryRow key={entry.id} entry={entry} onEdit={openEdit} onDelete={setDeletingEntry} />
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            <Pagination
                                links={entries.links}
                                total={entries.total}
                                currentPage={entries.current_page}
                                lastPage={entries.last_page}
                            />
                        </>
                    )}
                </div>

                <EntryFormDialog open={formOpen} onOpenChange={setFormOpen} entry={editingEntry} providers={providers} connectors={connectors} />
                <DeleteEntryDialog entry={deletingEntry} onOpenChange={(open) => !open && setDeletingEntry(null)} />
                <FlashToast />
            </TooltipProvider>
        </AppLayout>
    );
}
