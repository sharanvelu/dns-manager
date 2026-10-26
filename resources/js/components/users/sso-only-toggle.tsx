import { router, usePage } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { useState } from 'react';

import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import type { ManagedUser } from '@/pages/users/types';

interface SsoOnlyToggleProps {
    user: Pick<ManagedUser, 'id' | 'email' | 'ssoLinked' | 'ssoOnly'>;
    canManage: boolean;
}

export function SsoOnlyToggle({ user, canManage }: SsoOnlyToggleProps) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [busy, setBusy] = useState(false);

    const apply = (ssoOnly: boolean) => {
        router.patch(
            route('users.sso-only', user.id),
            { sso_only: ssoOnly },
            { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) },
        );
    };

    return (
        <div className="grid gap-1.5">
            <label htmlFor="sso-only-toggle" className="flex items-start gap-2.5 rounded-md border p-2.5">
                {busy ? (
                    <LoaderCircle className="mt-0.5 size-4 shrink-0 animate-spin" />
                ) : (
                    <Checkbox
                        id="sso-only-toggle"
                        checked={user.ssoOnly}
                        disabled={!canManage}
                        onCheckedChange={(checked) => apply(checked === true)}
                    />
                )}
                <span className="grid gap-0.5">
                    <span className="text-sm leading-none font-medium">Single sign-on only</span>
                    <span className="text-muted-foreground text-xs">
                        Blocks email + password sign-in for this account. Their password is kept and works again if you turn this off.
                    </span>
                </span>
            </label>
            {user.ssoOnly && !user.ssoLinked && (
                <p className="text-xs text-amber-700 dark:text-amber-400">
                    They have not signed in with SSO yet — their identity provider email must match {user.email}.
                </p>
            )}
            <InputError message={errors.sso_only} />
        </div>
    );
}
