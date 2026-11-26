import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { LoaderCircle, TriangleAlert } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

export interface DetachableAttachment {
    id: number;
    providerName: string;
    /** false ⇒ zoneless provider — detaching is an opt-out, phrased as such. */
    supportsZones: boolean;
    /** Attachment or provider disabled — its records can't be deleted (paused invariant). */
    paused: boolean;
    /** Entries assigned to this attachment. */
    recordsCount: number;
}

interface DetachProviderDialogProps {
    zone: { id: number; name: string };
    attachment: DetachableAttachment | null;
    onOpenChange: (open: boolean) => void;
}

/**
 * Confirm detaching a provider from a zone. By default remote records are never touched; the
 * opt-in "also delete" path queues remote deletes and detaches once they have all succeeded.
 */
export function DetachProviderDialog({ zone, attachment, onOpenChange }: DetachProviderDialogProps) {
    const [processing, setProcessing] = useState(false);
    const [deleteRecords, setDeleteRecords] = useState(false);

    // Keep the last attachment around so the dialog content doesn't blank out during the close animation.
    const last = useRef<DetachableAttachment | null>(null);
    if (attachment) {
        last.current = attachment;
    }
    const display = attachment ?? last.current;
    const optOut = display ? !display.supportsZones : false;
    const canDelete = display ? !display.paused && display.recordsCount > 0 : false;
    const deleting = deleteRecords && canDelete;
    const records = display ? `${display.recordsCount.toLocaleString()} ${display.recordsCount === 1 ? 'record' : 'records'}` : '';

    useEffect(() => {
        if (attachment) setDeleteRecords(false);
    }, [attachment]);

    const confirmDetach = () => {
        if (!display) return;

        router.delete(route('zone-providers.destroy', [zone.id, display.id]), {
            data: { delete_records: deleting },
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={attachment !== null} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {optOut ? `Opt ${zone.name} out of ${display?.providerName}?` : `Detach ${display?.providerName} from ${zone.name}?`}
                    </DialogTitle>
                    <DialogDescription>
                        {optOut
                            ? `${display?.providerName} serves all zones — opting out stops this zone's records from syncing there.`
                            : `This zone's records stop syncing to ${display?.providerName}.`}{' '}
                        {deleting
                            ? `This zone's ${records} will also be deleted at ${display?.providerName}.`
                            : 'Records at the provider will NOT be deleted — the app just stops managing them.'}
                    </DialogDescription>
                </DialogHeader>

                {display && display.recordsCount > 0 && (
                    <label
                        className={cn(
                            'flex items-start gap-2.5 rounded-lg border p-3 text-sm',
                            deleting && 'border-red-500/40 bg-red-500/5',
                            !canDelete && 'opacity-60',
                        )}
                    >
                        <Checkbox
                            className="mt-0.5"
                            checked={deleting}
                            disabled={!canDelete || processing}
                            onCheckedChange={(checked) => setDeleteRecords(checked === true)}
                        />
                        <span className="grid gap-0.5">
                            <span className="font-medium">
                                Also delete this zone's {records} at {display.providerName}
                            </span>
                            <span className="text-muted-foreground text-xs">
                                {display.paused
                                    ? `${display.providerName} is disabled for this zone, so its records can't be deleted — enable it first, or ${optOut ? 'opt out' : 'detach'} without deleting.`
                                    : `Deletes are queued first; ${optOut ? 'the opt-out completes' : 'the provider is detached'} once they all succeed. If one fails, it stays attached so you can retry.`}
                            </span>
                        </span>
                    </label>
                )}

                {deleting && (
                    <p className="flex items-start gap-2 text-xs text-red-700 dark:text-red-400">
                        <TriangleAlert className="mt-px size-3.5 shrink-0" />
                        The records are removed from {display?.providerName} itself — this cannot be undone from the app.
                    </p>
                )}

                <DialogFooter className="gap-2">
                    <Button variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        Cancel
                    </Button>
                    <Button variant="destructive" onClick={confirmDetach} disabled={processing}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        {optOut ? 'Opt out' : 'Detach'}
                        {deleting && ' and delete records'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
