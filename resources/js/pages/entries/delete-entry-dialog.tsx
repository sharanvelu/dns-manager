import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { useRef, useState } from 'react';
import type { EntryItem } from './types';

interface DeleteEntryDialogProps {
    entry: EntryItem | null;
    onOpenChange: (open: boolean) => void;
}

export function DeleteEntryDialog({ entry, onOpenChange }: DeleteEntryDialogProps) {
    const [processing, setProcessing] = useState(false);

    // Keep the last entry around so the dialog content doesn't blank out during the close animation.
    const lastEntry = useRef<EntryItem | null>(null);
    if (entry) {
        lastEntry.current = entry;
    }
    const display = entry ?? lastEntry.current;

    const confirmDelete = () => {
        if (!display) return;

        router.delete(route('entries.destroy', display.id), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={entry !== null} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Delete entry</DialogTitle>
                    <DialogDescription>
                        This removes <span className="text-foreground font-mono font-medium">{display?.name}</span> from all providers. The remote
                        records will be deleted.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter className="gap-2">
                    <Button variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        Cancel
                    </Button>
                    <Button variant="destructive" onClick={confirmDelete} disabled={processing}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        Delete entry
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
