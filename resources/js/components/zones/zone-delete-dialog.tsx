import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { useRef, useState } from 'react';

export interface DeletableZone {
    id: number;
    name: string;
    entriesCount?: number;
}

interface ZoneDeleteDialogProps {
    zone: DeletableZone | null;
    onOpenChange: (open: boolean) => void;
}

/** Confirm zone deletion — local records go away, remote provider records stay. */
export function ZoneDeleteDialog({ zone, onOpenChange }: ZoneDeleteDialogProps) {
    const [processing, setProcessing] = useState(false);

    // Keep the last zone around so the dialog content doesn't blank out during the close animation.
    const lastZone = useRef<DeletableZone | null>(null);
    if (zone) {
        lastZone.current = zone;
    }
    const display = zone ?? lastZone.current;

    const recordsPhrase =
        display?.entriesCount !== undefined
            ? `Its ${display.entriesCount} ${display.entriesCount === 1 ? 'record is' : 'records are'} removed from DNS Manager.`
            : 'Its records are removed from DNS Manager.';

    const confirmDelete = () => {
        if (!display) return;

        router.delete(route('zones.destroy', display.id), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={zone !== null} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        Delete <span className="font-mono">{display?.name}</span>?
                    </DialogTitle>
                    <DialogDescription>
                        {recordsPhrase} Records at attached providers will NOT be deleted — the app just stops managing them. This cannot be undone.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter className="gap-2">
                    <Button variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        Cancel
                    </Button>
                    <Button variant="destructive" onClick={confirmDelete} disabled={processing}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        Delete zone
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
