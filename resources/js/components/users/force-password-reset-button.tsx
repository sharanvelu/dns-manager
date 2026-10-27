import { router } from '@inertiajs/react';
import { KeyRound, LoaderCircle } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import type { ManagedUser } from '@/pages/users/types';

export function ForcePasswordResetButton({ user }: { user: Pick<ManagedUser, 'id' | 'name' | 'email'> }) {
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);

    const submit = () => {
        setBusy(true);
        router.post(
            route('users.force-reset', user.id),
            {},
            {
                preserveScroll: true,
                onSuccess: () => setConfirming(false),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <>
            <Button variant="outline" size="sm" onClick={() => setConfirming(true)}>
                <KeyRound className="size-3.5" />
                Force password reset
            </Button>

            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Force a password reset for {user.name}?</DialogTitle>
                        <DialogDescription>
                            They are signed out everywhere and emailed a reset link at {user.email}. Until they set a new password they cannot sign in
                            with their current one. Single sign-on is not affected.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter className="gap-2">
                        <Button variant="outline" onClick={() => setConfirming(false)}>
                            Cancel
                        </Button>
                        <Button onClick={submit} disabled={busy}>
                            {busy && <LoaderCircle className="size-3.5 animate-spin" />}
                            Force reset
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
