import { Head, usePage } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';

import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';

interface LoginProps {
    providerLabel: string;
    errors: { oidc?: string };
}

export default function Login({ providerLabel }: LoginProps) {
    const { errors } = usePage<{ errors: { oidc?: string } }>().props;

    return (
        <AuthLayout title="Welcome back" description="Sign in with your identity provider to manage your DNS entries">
            <Head title="Sign in" />

            <div className="flex flex-col gap-6">
                <Button asChild className="w-full" size="lg">
                    <a href={route('oidc.redirect')}>
                        Sign in with {providerLabel}
                        <ArrowRight className="h-4 w-4" />
                    </a>
                </Button>

                {errors.oidc && <div className="text-center text-sm font-medium text-red-600 dark:text-red-400">{errors.oidc}</div>}
            </div>
        </AuthLayout>
    );
}
