import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
import { LoaderCircle, TriangleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ProviderMark } from './helpers';
import type { ProviderInfo } from './types';

interface BulkProvidersDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    selectedIds: number[];
    providers: ProviderInfo[];
    onDone: () => void;
}

export function BulkProvidersDialog({ open, onOpenChange, selectedIds, providers, onDone }: BulkProvidersDialogProps) {
    const enabledProviders = providers.filter((provider) => provider.enabled);
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        if (open) {
            setSelected(new Set(enabledProviders.map((provider) => provider.id)));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

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

    const submit = () => {
        setProcessing(true);
        router.post(
            route('entries.bulk.providers'),
            { ids: selectedIds, providers: [...selected] },
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
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        Set providers for {selectedIds.length} {selectedIds.length === 1 ? 'entry' : 'entries'}
                    </DialogTitle>
                    <DialogDescription>
                        Replaces each entry's provider assignment: records sync to the ticked providers and are removed from unticked ones. Providers
                        that don't manage an entry's record type are skipped for that entry.
                    </DialogDescription>
                </DialogHeader>

                {enabledProviders.length > 0 ? (
                    <div className="grid gap-2 rounded-lg border p-3">
                        {enabledProviders.map((provider) => (
                            <label key={provider.id} className="flex items-center gap-2.5 text-sm">
                                <Checkbox checked={selected.has(provider.id)} onCheckedChange={(checked) => toggle(provider.id, checked === true)} />
                                <ProviderMark type={provider.type} className="text-muted-foreground size-3.5" />
                                <span className="flex-1">{provider.name}</span>
                                <span className="text-muted-foreground text-xs">{provider.managedRecordTypes.join(', ')}</span>
                            </label>
                        ))}
                    </div>
                ) : (
                    <p className="text-muted-foreground rounded-lg border border-dashed p-3 text-sm">No enabled providers configured.</p>
                )}

                {selected.size === 0 && (
                    <p className="flex items-start gap-2 text-xs text-amber-700 dark:text-amber-400">
                        <TriangleAlert className="mt-px size-3.5 shrink-0" />
                        No providers selected — the entries will be removed from all their providers and kept locally only. Disabled providers are
                        paused and keep their records.
                    </p>
                )}

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        Cancel
                    </Button>
                    <Button type="button" onClick={submit} disabled={processing}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        Apply to {selectedIds.length} {selectedIds.length === 1 ? 'entry' : 'entries'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
