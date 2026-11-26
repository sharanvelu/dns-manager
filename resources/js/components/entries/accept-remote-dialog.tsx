import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
import { ArrowRight, LoaderCircle } from 'lucide-react';
import { useRef, useState } from 'react';
import { driftFieldLabel, formatDriftValue } from './drift';
import type { EntryItem, SyncStateItem } from './types';

export interface AcceptRemoteTarget {
    entry: EntryItem;
    state: SyncStateItem;
}

interface AcceptRemoteDialogProps {
    target: AcceptRemoteTarget | null;
    onOpenChange: (open: boolean) => void;
}

/**
 * Confirms adopting one provider's values for a drifted entry, listing each
 * drifted field as tracked → actual. The server re-reads the record live on
 * confirm, so the values shown are the last drift check's.
 */
export function AcceptRemoteDialog({ target, onOpenChange }: AcceptRemoteDialogProps) {
    const [processing, setProcessing] = useState(false);

    // Keep the last target so the content doesn't blank out during the close animation.
    const lastTarget = useRef<AcceptRemoteTarget | null>(null);
    if (target) {
        lastTarget.current = target;
    }
    const display = target ?? lastTarget.current;
    const providerName = display?.state.providerName ?? 'the provider';
    const otherProviders = display ? display.entry.syncStates.filter((state) => state.id !== display.state.id).length : 0;

    const accept = () => {
        if (!display) return;

        router.post(
            route('entries.accept-remote', display.entry.id),
            { zone_provider_id: display.state.zoneProviderId },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => onOpenChange(false),
            },
        );
    };

    return (
        <Dialog open={target !== null} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Accept values from {providerName}?</DialogTitle>
                    <DialogDescription>
                        <span className="text-foreground font-mono font-medium">{display?.entry.fqdn}</span> is updated to what {providerName} holds
                        now
                        {otherProviders > 0 ? ', then re-pushed to its other active providers so they all match.' : '.'} The record is re-read from{' '}
                        {providerName} when you confirm.
                    </DialogDescription>
                </DialogHeader>

                {display?.state.driftDetails && display.state.driftDetails.length > 0 && (
                    <div className="overflow-hidden rounded-lg border">
                        <table className="w-full text-xs">
                            <thead>
                                <tr className="bg-muted/40 text-muted-foreground border-b text-left">
                                    <th className="px-3 py-2 font-medium">Field</th>
                                    <th className="px-3 py-2 font-medium">Tracked</th>
                                    <th className="w-4 px-0 py-2" aria-hidden />
                                    <th className="px-3 py-2 font-medium">Actual at {providerName}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {display.state.driftDetails.map((detail) => (
                                    <tr key={detail.field} className="border-b last:border-b-0">
                                        <td className="px-3 py-2 font-medium capitalize">{driftFieldLabel(detail.field)}</td>
                                        <td className="text-muted-foreground px-3 py-2 font-mono break-all line-through">
                                            {formatDriftValue(detail, detail.tracked)}
                                        </td>
                                        <td className="text-muted-foreground px-0 py-2">
                                            <ArrowRight className="size-3" />
                                        </td>
                                        <td className="px-3 py-2 font-mono break-all text-amber-700 dark:text-amber-400">
                                            {formatDriftValue(detail, detail.actual)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <DialogFooter className="gap-2">
                    <Button variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        Cancel
                    </Button>
                    <Button onClick={accept} disabled={processing}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        Accept values
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
