import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { router } from '@inertiajs/react';
import { LoaderCircle, TriangleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ProviderMark } from './helpers';
import type { ZoneAttachment } from './types';

type BulkProvidersMode = 'replace' | 'attach' | 'detach';

const modeCopy = (mode: BulkProvidersMode, keepRemote: boolean): { description: string; label: (entries: string) => string } => {
    switch (mode) {
        case 'attach':
            return {
                description:
                    "Adds the ticked providers to each entry's assignment and pushes the records to them — existing assignments are kept as they are. Providers that don't manage an entry's record type are skipped for that entry.",
                label: (entries) => `Attach to ${entries}`,
            };
        case 'detach':
            return {
                description: keepRemote
                    ? "Removes the ticked providers from each entry's assignment but keeps the records there — the app just stops managing them. Other assignments are untouched. Paused providers are left as they are."
                    : "Removes the ticked providers from each entry's assignment and deletes the records from them — other assignments are untouched. Paused providers keep their records.",
                label: (entries) => (keepRemote ? `Detach ${entries}, keep records` : `Detach ${entries}, delete records`),
            };
        default:
            return {
                description: keepRemote
                    ? "Replaces each entry's provider assignment: records sync to the ticked providers; at unticked ones they are kept but no longer managed. Providers that don't manage an entry's record type are skipped for that entry."
                    : "Replaces each entry's provider assignment: records sync to the ticked providers and are deleted from unticked ones. Providers that don't manage an entry's record type are skipped for that entry.",
                label: (entries) => `Apply to ${entries}`,
            };
    }
};

interface BulkProvidersDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    selectedIds: number[];
    /** The (single) zone's provider attachments — the entries must all belong to it. */
    attachments: ZoneAttachment[];
    onDone: () => void;
}

export function BulkProvidersDialog({ open, onOpenChange, selectedIds, attachments, onDone }: BulkProvidersDialogProps) {
    const enabledAttachments = attachments.filter((attachment) => attachment.enabled);
    const [mode, setMode] = useState<BulkProvidersMode>('replace');
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [keepRemote, setKeepRemote] = useState(false);
    const [processing, setProcessing] = useState(false);

    // Replace starts from "everything ticked" (the common retarget); attach
    // and detach start empty — they act only on what the user ticks.
    const defaultSelection = (forMode: BulkProvidersMode) =>
        forMode === 'replace' ? new Set(enabledAttachments.map((attachment) => attachment.id)) : new Set<number>();

    useEffect(() => {
        if (open) {
            setMode('replace');
            setSelected(defaultSelection('replace'));
            setKeepRemote(false);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const changeMode = (next: string) => {
        if (next !== 'replace' && next !== 'attach' && next !== 'detach') return;

        setMode(next);
        setSelected(defaultSelection(next));
        setKeepRemote(false);
    };

    const toggle = (id: number, checked: boolean) => {
        setSelected((current) => {
            const next = new Set(current);
            if (checked) {
                next.add(id);
            } else {
                next.delete(id);
            }

            return next;
        });
    };

    // Only removals can keep records: detaching, or a replace that unticks something.
    const offersKeep = mode === 'detach' || (mode === 'replace' && selected.size < enabledAttachments.length);
    const copy = modeCopy(mode, offersKeep && keepRemote);
    const deletesRemote = mode === 'detach' && !keepRemote;

    const submit = () => {
        setProcessing(true);
        router.post(
            route('entries.bulk.providers'),
            { ids: selectedIds, zone_providers: [...selected], mode, ...(offersKeep ? { keep_remote: keepRemote } : {}) },
            {
                preserveScroll: true,
                onSuccess: () => {
                    onOpenChange(false);
                    onDone();
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        Providers for {selectedIds.length} {selectedIds.length === 1 ? 'entry' : 'entries'}
                    </DialogTitle>
                    <DialogDescription>{copy.description}</DialogDescription>
                </DialogHeader>

                <ToggleGroup type="single" variant="outline" size="sm" className="justify-start" value={mode} onValueChange={changeMode}>
                    <ToggleGroupItem value="replace">Replace</ToggleGroupItem>
                    <ToggleGroupItem value="attach">Attach</ToggleGroupItem>
                    <ToggleGroupItem value="detach">Detach</ToggleGroupItem>
                </ToggleGroup>

                {enabledAttachments.length > 0 ? (
                    <div className="grid gap-2 rounded-lg border p-3">
                        {enabledAttachments.map((attachment) => (
                            <label key={attachment.id} className="flex items-center gap-2.5 text-sm">
                                <Checkbox
                                    checked={selected.has(attachment.id)}
                                    onCheckedChange={(checked) => toggle(attachment.id, checked === true)}
                                />
                                <ProviderMark type={attachment.providerType} className="text-muted-foreground size-3.5" />
                                <span className="flex-1">{attachment.providerName}</span>
                                <span className="text-muted-foreground text-xs">{attachment.managedRecordTypes.join(', ')}</span>
                            </label>
                        ))}
                    </div>
                ) : (
                    <p className="text-muted-foreground rounded-lg border border-dashed p-3 text-sm">
                        No enabled providers are attached to this zone.
                    </p>
                )}

                {mode === 'replace' && selected.size === 0 && (
                    <p className="flex items-start gap-2 text-xs text-amber-700 dark:text-amber-400">
                        <TriangleAlert className="mt-px size-3.5 shrink-0" />
                        {keepRemote
                            ? 'No providers selected — the app stops managing the entries at all their providers (records stay there) and keeps them locally only.'
                            : 'No providers selected — the entries will be deleted from all their providers and kept locally only.'}{' '}
                        Disabled providers are paused and keep their records.
                    </p>
                )}

                {offersKeep && (
                    <label className="flex items-start gap-2.5 rounded-lg border p-3 text-sm">
                        <Checkbox className="mt-0.5" checked={keepRemote} onCheckedChange={(checked) => setKeepRemote(checked === true)} />
                        <span className="grid gap-0.5">
                            <span className="font-medium">Keep the records at the provider</span>
                            <span className="text-muted-foreground text-xs">
                                Stop managing them without syncing a delete — the records stay live at {mode === 'detach' ? 'the ticked' : 'unticked'}{' '}
                                providers and the app no longer tracks or changes them.
                            </span>
                        </span>
                    </label>
                )}

                {offersKeep && !keepRemote && (
                    <p className="flex items-start gap-2 text-xs text-red-700 dark:text-red-400">
                        <TriangleAlert className="mt-px size-3.5 shrink-0" />
                        Records will be deleted at {mode === 'detach' ? 'the ticked' : 'unticked'} providers.
                    </p>
                )}

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        variant={deletesRemote ? 'destructive' : 'default'}
                        onClick={submit}
                        disabled={processing || (mode !== 'replace' && selected.size === 0)}
                    >
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        {copy.label(`${selectedIds.length} ${selectedIds.length === 1 ? 'entry' : 'entries'}`)}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
