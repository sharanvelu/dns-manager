import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

export default function ForgotPassword() {
    const { status } = usePage<{ status?: string | null }>().props;
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('password.email'));
    };

    return (
        <AuthLayout title="Forgot password" description="Enter your email and we will send you a link to reset it">
            <Head title="Forgot password" />

            <div className="flex flex-col gap-6">
                {status && <div className="text-center text-sm font-medium text-emerald-600 dark:text-emerald-400">{status}</div>}

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

                    <Button type="submit" className="w-full" size="lg" disabled={processing}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        Email reset link
                    </Button>
                </form>

                <p className="text-muted-foreground text-center text-sm">
                    <Link href={route('login')} className="hover:underline">
                        Back to sign in
                    </Link>
                </p>
            </div>
        </AuthLayout>
    );
}
