import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { router } from '@inertiajs/react';
import { LoaderCircle, Pencil, RefreshCw, Server, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import { BulkEditDialog } from './bulk-edit-dialog';
import { BulkProvidersDialog } from './bulk-providers-dialog';
import type { ZoneAttachment } from './types';

interface BulkActionsBarProps {
    selectedIds: number[];
    /** The shared zone of every selected entry — null when the selection spans zones. */
    selectionZoneId: number | null;
    /** That zone's provider attachments (empty when selectionZoneId is null). */
    attachments: ZoneAttachment[];
    onClear: () => void;
}

export function BulkActionsBar({ selectedIds, selectionZoneId, attachments, onClear }: BulkActionsBarProps) {
    const [providersOpen, setProvidersOpen] = useState(false);
    const [editOpen, setEditOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [busy, setBusy] = useState(false);

    const count = selectedIds.length;
    const noun = count === 1 ? 'entry' : 'entries';
    const singleZone = selectionZoneId !== null;

    const syncNow = () => {
        setBusy(true);
        router.post(
            route('entries.bulk.sync'),
            { ids: selectedIds },
            {
                preserveScroll: true,
                onSuccess: onClear,
                onFinish: () => setBusy(false),
            },
        );
    };

    const destroy = () => {
        setBusy(true);
        router.delete(route('entries.bulk.destroy'), {
            data: { ids: selectedIds },
            preserveScroll: true,
            onSuccess: () => {
                setDeleteOpen(false);
                onClear();
            },
            onFinish: () => setBusy(false),
        });
    };

    const providersButton = (
        <Button variant="outline" size="sm" className="h-8" onClick={() => setProvidersOpen(true)} disabled={busy || !singleZone}>
            <Server className="size-3.5" />
            Providers
        </Button>
    );

    return (
        <div className="border-primary/20 bg-primary/5 flex flex-wrap items-center gap-2 rounded-xl border px-3 py-2">
            <p className="text-sm font-medium tabular-nums">
                {count} {noun} selected
            </p>
            <Button variant="ghost" size="sm" className="text-muted-foreground h-8" onClick={onClear}>
                <X className="size-3.5" />
                Clear
            </Button>

            <div className="ml-auto flex flex-wrap items-center gap-2">
                <Button variant="outline" size="sm" className="h-8" onClick={syncNow} disabled={busy}>
                    <RefreshCw className="size-3.5" />
                    Sync now
                </Button>
                {singleZone ? (
                    providersButton
                ) : (
                    <Tooltip>
                        {/* Disabled buttons swallow pointer events — the span keeps the tooltip alive. */}
                        <TooltipTrigger asChild>
                            <span tabIndex={0}>{providersButton}</span>
                        </TooltipTrigger>
                        <TooltipContent>Select entries from a single zone to change providers.</TooltipContent>
                    </Tooltip>
                )}
                <Button variant="outline" size="sm" className="h-8" onClick={() => setEditOpen(true)} disabled={busy}>
                    <Pencil className="size-3.5" />
                    Edit
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    className="h-8 text-red-600 hover:text-red-600 dark:text-red-400 dark:hover:text-red-400"
                    onClick={() => setDeleteOpen(true)}
                    disabled={busy}
                >
                    <Trash2 className="size-3.5" />
                    Delete
                </Button>
            </div>

            <BulkProvidersDialog
                open={providersOpen}
                onOpenChange={setProvidersOpen}
                selectedIds={selectedIds}
                attachments={attachments}
                onDone={onClear}
            />
            <BulkEditDialog open={editOpen} onOpenChange={setEditOpen} selectedIds={selectedIds} onDone={onClear} />

            <Dialog open={deleteOpen} onOpenChange={setDeleteOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            Delete {count} {noun}?
                        </DialogTitle>
                        <DialogDescription>
                            The records are removed from every provider they are assigned to, then the entries disappear from the list. Records at
                            disabled providers are left in place. This cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" onClick={() => setDeleteOpen(false)} disabled={busy}>
                            Cancel
                        </Button>
                        <Button type="button" variant="destructive" onClick={destroy} disabled={busy}>
                            {busy && <LoaderCircle className="size-4 animate-spin" />}
                            Delete {count} {noun}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
