import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

export default function TwoFactorChallenge() {
    const [useRecovery, setUseRecovery] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({ code: '', recovery_code: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('two-factor.verify'), { onFinish: () => reset() });
    };

    const toggle = () => {
        reset();
        clearErrors();
        setUseRecovery((value) => !value);
    };

    return (
        <AuthLayout
            title="Two-factor authentication"
            description={useRecovery ? 'Enter one of your recovery codes' : 'Enter the 6-digit code from your authenticator app'}
        >
            <Head title="Two-factor authentication" />

            <form onSubmit={submit} className="flex flex-col gap-4">
                {useRecovery ? (
                    <div className="grid gap-2">
                        <Label htmlFor="recovery_code">Recovery code</Label>
                        <Input
                            id="recovery_code"
                            type="text"
                            required
                            autoFocus
                            autoComplete="off"
                            spellCheck={false}
                            className="font-mono"
                            value={data.recovery_code}
                            onChange={(e) => setData('recovery_code', e.target.value)}
                            placeholder="xxxxxxxxxx-xxxxxxxxxx"
                        />
                        <InputError message={errors.recovery_code} />
                    </div>
                ) : (
                    <div className="grid gap-2">
                        <Label htmlFor="code">Authentication code</Label>
                        <Input
                            id="code"
                            type="text"
                            inputMode="numeric"
                            pattern="[0-9]*"
                            maxLength={6}
                            required
                            autoFocus
                            autoComplete="one-time-code"
                            className="text-center font-mono text-lg tracking-[0.5em]"
                            value={data.code}
                            onChange={(e) => setData('code', e.target.value.replace(/\D/g, ''))}
                            placeholder="000000"
                        />
                        <InputError message={errors.code} />
                    </div>
                )}

                <Button type="submit" className="w-full" size="lg" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    Verify
                </Button>

                <div className="text-muted-foreground flex items-center justify-between text-sm">
                    <button type="button" onClick={toggle} className="hover:text-foreground underline-offset-4 hover:underline">
                        {useRecovery ? 'Use an authenticator code' : 'Use a recovery code'}
                    </button>
                    <Link href={route('login')} className="hover:text-foreground underline-offset-4 hover:underline">
                        Back to sign in
                    </Link>
                </div>
            </form>
        </AuthLayout>
    );
}
