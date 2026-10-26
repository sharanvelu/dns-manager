import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

interface ResetPasswordProps {
    token: string;
    email: string;
    /** `invite` = admin-created user choosing a first password; `reset` = forgotten password. */
    mode: 'invite' | 'reset';
}

export default function ResetPassword({ token, email, mode }: ResetPasswordProps) {
    const invite = mode === 'invite';
    const { data, setData, post, processing, errors, reset } = useForm({ token, email, password: '', password_confirmation: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route(invite ? 'password.invite.store' : 'password.store'), { onFinish: () => reset('password', 'password_confirmation') });
    };

    return (
        <AuthLayout
            title={invite ? 'Create your password' : 'Reset password'}
            description={invite ? 'Choose a password to finish setting up your account' : 'Choose a new password for your account'}
        >
            <Head title={invite ? 'Create password' : 'Reset password'} />

            <form onSubmit={submit} className="flex flex-col gap-4">
                <div className="grid gap-2">
                    <Label htmlFor="email">Email address</Label>
                    <Input id="email" type="email" value={data.email} readOnly autoComplete="email" className="bg-muted/40" />
                    <InputError message={errors.email} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="password">Password</Label>
                    <Input
                        id="password"
                        type="password"
                        required
                        autoFocus
                        autoComplete="new-password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                    />
                    <InputError message={errors.password} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="password_confirmation">Confirm password</Label>
                    <Input
                        id="password_confirmation"
                        type="password"
                        required
                        autoComplete="new-password"
                        value={data.password_confirmation}
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                    />
                    <InputError message={errors.password_confirmation} />
                </div>

                <Button type="submit" className="w-full" size="lg" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    {invite ? 'Set password' : 'Reset password'}
                </Button>
            </form>
        </AuthLayout>
    );
}
