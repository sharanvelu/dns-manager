import { type BreadcrumbItem, type SharedData } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import AppearanceTabs from '@/components/appearance-tabs';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Profile',
        href: '/profile',
    },
];

interface ProfileProps {
    basicEnabled: boolean;
    hasPassword: boolean;
    ssoLinked: boolean;
}

function PasswordSection({ hasPassword, ssoLinked }: { hasPassword: boolean; ssoLinked: boolean }) {
    const { data, setData, put, errors, processing, recentlySuccessful, reset } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        put(route('profile.password'), { preserveScroll: true, onSuccess: () => reset() });
    };

    return (
        <div className="max-w-xl space-y-6">
            <HeadingSmall
                title={hasPassword ? 'Change password' : 'Set a password'}
                description={
                    hasPassword
                        ? 'Changing it signs you out of your other sessions'
                        : ssoLinked
                          ? 'Optional — lets you sign in with your email and password as well as single sign-on'
                          : 'Choose a password to sign in with your email address'
                }
            />

            <form onSubmit={submit} className="space-y-6">
                {hasPassword && (
                    <div className="grid gap-2">
                        <Label htmlFor="current_password">Current password</Label>
                        <Input
                            id="current_password"
                            type="password"
                            className="mt-1 block w-full"
                            value={data.current_password}
                            onChange={(e) => setData('current_password', e.target.value)}
                            autoComplete="current-password"
                        />
                        <InputError message={errors.current_password} />
                    </div>
                )}

                <div className="grid gap-2">
                    <Label htmlFor="password">New password</Label>
                    <Input
                        id="password"
                        type="password"
                        className="mt-1 block w-full"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        autoComplete="new-password"
                    />
                    <InputError message={errors.password} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="password_confirmation">Confirm password</Label>
                    <Input
                        id="password_confirmation"
                        type="password"
                        className="mt-1 block w-full"
                        value={data.password_confirmation}
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                        autoComplete="new-password"
                    />
                    <InputError message={errors.password_confirmation} />
                </div>

                <div className="flex items-center gap-4">
                    <Button disabled={processing}>{hasPassword ? 'Update password' : 'Set password'}</Button>

                    <Transition
                        show={recentlySuccessful}
                        enter="transition ease-in-out"
                        enterFrom="opacity-0"
                        leave="transition ease-in-out"
                        leaveTo="opacity-0"
                    >
                        <p className="text-sm text-neutral-600">Saved</p>
                    </Transition>
                </div>
            </form>
        </div>
    );
}

export default function Profile({ basicEnabled, hasPassword, ssoLinked }: ProfileProps) {
    const { auth } = usePage<SharedData>().props;

    const { data, setData, patch, errors, processing, recentlySuccessful } = useForm({
        name: auth.user.name,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        patch(route('profile.update'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Profile" />

            <div className="flex h-full flex-1 flex-col gap-8 p-4 md:p-6">
                <div className="max-w-xl space-y-6">
                    <HeadingSmall
                        title="Profile information"
                        description={ssoLinked ? 'Your account is managed by your identity provider' : 'Your account details'}
                    />

                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>

                            <Input
                                id="name"
                                className="mt-1 block w-full"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                required
                                autoComplete="name"
                                placeholder="Full name"
                            />

                            <InputError className="mt-2" message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="email">Email address</Label>

                            <Input id="email" type="email" className="mt-1 block w-full" value={auth.user.email} disabled readOnly />

                            <p className="text-muted-foreground text-sm">
                                {ssoLinked
                                    ? 'Email is managed by your identity provider and refreshed on sign-in.'
                                    : 'Ask an administrator to change your email address.'}
                            </p>
                        </div>

                        <div className="flex items-center gap-4">
                            <Button disabled={processing}>Save</Button>

                            <Transition
                                show={recentlySuccessful}
                                enter="transition ease-in-out"
                                enterFrom="opacity-0"
                                leave="transition ease-in-out"
                                leaveTo="opacity-0"
                            >
                                <p className="text-sm text-neutral-600">Saved</p>
                            </Transition>
                        </div>
                    </form>
                </div>

                {basicEnabled && (
                    <>
                        <Separator className="max-w-xl" />
                        <PasswordSection hasPassword={hasPassword} ssoLinked={ssoLinked} />
                    </>
                )}

                <Separator className="max-w-xl" />

                <div className="max-w-xl space-y-6">
                    <HeadingSmall title="Appearance" description="Choose how DNS Manager looks for you" />
                    <AppearanceTabs />
                </div>
            </div>
        </AppLayout>
    );
}
