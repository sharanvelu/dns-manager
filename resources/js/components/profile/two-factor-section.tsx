import { router, useForm } from '@inertiajs/react';
import { LoaderCircle, ShieldCheck } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { RecoveryCodes } from '@/components/profile/recovery-codes';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type TwoFactorState = {
    enabled: boolean;
    pending: { qrSvg: string; secret: string } | null;
    recoveryCodes: string[] | null;
};

type PasswordAction = 'disable' | 'regenerate' | 'cancel';

const copy: Record<PasswordAction, { title: string; description: string; button: string; destructive: boolean }> = {
    disable: {
        title: 'Turn off two-factor authentication?',
        description: 'Your account will be protected by your password alone. Confirm your password to continue.',
        button: 'Disable two-factor',
        destructive: true,
    },
    regenerate: {
        title: 'Generate new recovery codes?',
        description: 'Your existing recovery codes stop working immediately. Confirm your password to continue.',
        button: 'Generate codes',
        destructive: false,
    },
    cancel: {
        title: 'Cancel two-factor setup?',
        description: 'The secret shown here is discarded. Confirm your password to continue.',
        button: 'Cancel setup',
        destructive: true,
    },
};

function groupSecret(secret: string): string {
    return secret.match(/.{1,4}/g)?.join(' ') ?? secret;
}

function PasswordDialog({ action, onClose }: { action: PasswordAction | null; onClose: () => void }) {
    const { data, setData, processing, errors, reset, clearErrors, delete: destroy, post } = useForm({ current_password: '' });
    const active = action ?? 'disable';
    const text = copy[active];

    const close = () => {
        reset();
        clearErrors();
        onClose();
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        const options = { preserveScroll: true, onSuccess: close };

        if (active === 'regenerate') {
            post(route('two-factor.recovery-codes'), options);
        } else {
            destroy(route('two-factor.disable'), options);
        }
    };

    return (
        <Dialog open={action !== null} onOpenChange={(open) => !open && close()}>
            <DialogContent className="sm:max-w-md">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>{text.title}</DialogTitle>
                        <DialogDescription>{text.description}</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="two_factor_current_password">Current password</Label>
                        <Input
                            id="two_factor_current_password"
                            type="password"
                            autoFocus
                            autoComplete="current-password"
                            value={data.current_password}
                            onChange={(e) => setData('current_password', e.target.value)}
                        />
                        <InputError message={errors.current_password} />
                    </div>

                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" onClick={close}>
                            Back
                        </Button>
                        <Button type="submit" variant={text.destructive ? 'destructive' : 'default'} disabled={processing}>
                            {processing && <LoaderCircle className="size-3.5 animate-spin" />}
                            {text.button}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function PendingSetup({ pending, onCancel }: { pending: NonNullable<TwoFactorState['pending']>; onCancel: () => void }) {
    const { data, setData, post, processing, errors } = useForm({ code: '' });
    const [restarting, setRestarting] = useState(false);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('two-factor.confirm'), { preserveScroll: true });
    };

    const restart = () => {
        router.post(
            route('two-factor.enable'),
            {},
            { preserveScroll: true, onStart: () => setRestarting(true), onFinish: () => setRestarting(false) },
        );
    };

    return (
        <div className="space-y-5">
            <p className="text-muted-foreground text-sm">
                Scan this QR code with an authenticator app (1Password, Authy, Google Authenticator, …), then enter the 6-digit code it shows.
            </p>

            <div
                className="size-48 rounded-md border bg-white p-1 [&>svg]:size-full"
                aria-label="Two-factor QR code"
                dangerouslySetInnerHTML={{ __html: pending.qrSvg }}
            />

            <div className="space-y-1">
                <p className="text-muted-foreground text-xs">Can't scan? Enter this key manually:</p>
                <code className="bg-muted inline-block rounded px-1.5 py-0.5 font-mono text-sm break-all">{groupSecret(pending.secret)}</code>
            </div>

            <form onSubmit={submit} className="space-y-3">
                <div className="grid gap-2">
                    <Label htmlFor="two_factor_code">Confirmation code</Label>
                    <Input
                        id="two_factor_code"
                        inputMode="numeric"
                        pattern="[0-9]*"
                        maxLength={6}
                        autoComplete="one-time-code"
                        className="max-w-40 text-center font-mono tracking-[0.4em]"
                        value={data.code}
                        onChange={(e) => setData('code', e.target.value.replace(/\D/g, ''))}
                        placeholder="000000"
                    />
                    <InputError message={errors.code} />
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <Button type="submit" disabled={processing || data.code.length !== 6}>
                        {processing && <LoaderCircle className="size-3.5 animate-spin" />}
                        Confirm and enable
                    </Button>
                    <Button type="button" variant="outline" onClick={restart} disabled={restarting}>
                        Start over
                    </Button>
                    <Button type="button" variant="ghost" onClick={onCancel}>
                        Cancel
                    </Button>
                </div>
            </form>
        </div>
    );
}

export function TwoFactorSection({ twoFactor }: { twoFactor: TwoFactorState }) {
    const [dialog, setDialog] = useState<PasswordAction | null>(null);
    const [starting, setStarting] = useState(false);

    const start = () => {
        router.post(route('two-factor.enable'), {}, { preserveScroll: true, onStart: () => setStarting(true), onFinish: () => setStarting(false) });
    };

    return (
        <div className="max-w-xl space-y-6">
            <HeadingSmall
                title="Two-factor authentication"
                description="Require a code from an authenticator app, in addition to your password, when you sign in"
            />

            {twoFactor.enabled ? (
                <div className="space-y-5">
                    <div className="flex items-center gap-2 text-sm font-medium text-emerald-600 dark:text-emerald-400">
                        <ShieldCheck className="size-4" />
                        Two-factor authentication is on
                    </div>

                    {twoFactor.recoveryCodes && <RecoveryCodes codes={twoFactor.recoveryCodes} />}

                    <div className="flex flex-wrap gap-2">
                        <Button type="button" variant="outline" onClick={() => setDialog('regenerate')}>
                            Regenerate recovery codes
                        </Button>
                        <Button type="button" variant="destructive" onClick={() => setDialog('disable')}>
                            Disable two-factor
                        </Button>
                    </div>
                </div>
            ) : twoFactor.pending ? (
                <PendingSetup pending={twoFactor.pending} onCancel={() => setDialog('cancel')} />
            ) : (
                <div className="space-y-3">
                    <p className="text-muted-foreground text-sm">Two-factor authentication is off.</p>
                    <Button type="button" onClick={start} disabled={starting}>
                        {starting && <LoaderCircle className="size-3.5 animate-spin" />}
                        Set up two-factor
                    </Button>
                </div>
            )}

            <PasswordDialog action={dialog} onClose={() => setDialog(null)} />
        </div>
    );
}
