import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowRight, LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

interface LoginProps {
    providerLabel: string;
    ssoEnabled: boolean;
    basicEnabled: boolean;
    status?: string | null;
    errors: { oidc?: string };
}

export default function Login({ providerLabel, ssoEnabled, basicEnabled, status, errors: pageErrors }: LoginProps) {
    const { data, setData, post, processing, errors, reset } = useForm<{ email: string; password: string; remember: boolean }>({
        email: '',
        password: '',
        remember: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('login.store'), { onFinish: () => reset('password') });
    };

    const description =
        ssoEnabled && !basicEnabled ? 'Sign in with your identity provider to manage your DNS entries' : 'Sign in to manage your DNS entries';

    return (
        <AuthLayout title="Welcome back" description={description}>
            <Head title="Sign in" />

            <div className="flex flex-col gap-6">
                {status && <div className="text-center text-sm font-medium text-emerald-600 dark:text-emerald-400">{status}</div>}

                {basicEnabled && (
                    <form onSubmit={submit} className="flex flex-col gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="email">Email address</Label>
                            <Input
                                id="email"
                                type="email"
                                required
                                autoFocus
                                autoComplete="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                placeholder="email@example.com"
                            />
                            <InputError message={errors.email} />
                        </div>

                        <div className="grid gap-2">
                            <div className="flex items-center justify-between">
                                <Label htmlFor="password">Password</Label>
                                <Link href={route('password.request')} className="text-muted-foreground text-sm hover:underline" tabIndex={-1}>
                                    Forgot password?
                                </Link>
                            </div>
                            <Input
                                id="password"
                                type="password"
                                required
                                autoComplete="current-password"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                placeholder="Password"
                            />
                            <InputError message={errors.password} />
                        </div>

                        <div className="flex items-center gap-2">
                            <Checkbox id="remember" checked={data.remember} onCheckedChange={(checked) => setData('remember', checked === true)} />
                            <Label htmlFor="remember">Remember me</Label>
                        </div>

                        <Button type="submit" className="w-full" size="lg" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Sign in
                        </Button>
                    </form>
                )}

                {basicEnabled && ssoEnabled && (
                    <div className="text-muted-foreground flex items-center gap-3 text-xs">
                        <span className="bg-border h-px flex-1" />
                        or
                        <span className="bg-border h-px flex-1" />
                    </div>
                )}

                {ssoEnabled && (
                    <Button asChild className="w-full" size="lg" variant={basicEnabled ? 'outline' : 'default'}>
                        <a href={route('oidc.redirect')}>
                            Sign in with {providerLabel}
                            <ArrowRight className="h-4 w-4" />
                        </a>
                    </Button>
                )}

                {pageErrors.oidc && <div className="text-center text-sm font-medium text-red-600 dark:text-red-400">{pageErrors.oidc}</div>}
            </div>
        </AuthLayout>
    );
}
