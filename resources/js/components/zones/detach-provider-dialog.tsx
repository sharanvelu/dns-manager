import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { useRef, useState } from 'react';

export interface DetachableAttachment {
    id: number;
    providerName: string;
    /** false ⇒ zoneless provider — detaching is an opt-out, phrased as such. */
    supportsZones: boolean;
}

interface DetachProviderDialogProps {
    zone: { id: number; name: string };
    attachment: DetachableAttachment | null;
    onOpenChange: (open: boolean) => void;
}

/** Confirm detaching a provider from a zone. Remote records are never touched. */
export function DetachProviderDialog({ zone, attachment, onOpenChange }: DetachProviderDialogProps) {
    const [processing, setProcessing] = useState(false);

    // Keep the last attachment around so the dialog content doesn't blank out during the close animation.
    const last = useRef<DetachableAttachment | null>(null);
    if (attachment) {
        last.current = attachment;
    }
    const display = attachment ?? last.current;
    const optOut = display ? !display.supportsZones : false;

    const confirmDetach = () => {
        if (!display) return;

        router.delete(route('zone-providers.destroy', [zone.id, display.id]), {
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
                        Records at the provider will NOT be deleted — the app just stops managing them.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter className="gap-2">
                    <Button variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        Cancel
                    </Button>
                    <Button variant="destructive" onClick={confirmDetach} disabled={processing}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        {optOut ? 'Opt out' : 'Detach'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
