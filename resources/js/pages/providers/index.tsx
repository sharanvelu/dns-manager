import { EmptyProvidersIllustration } from '@/components/icons';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { FlashToast } from './flash-toast';
import { ProviderCard } from './provider-card';
import { ProviderFormDialog } from './provider-form-dialog';
import type { Provider, ProvidersPageProps } from './types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Providers',
        href: '/providers',
    },
];

type DialogState = { mode: 'create' } | { mode: 'edit'; provider: Provider } | null;

export default function ProvidersIndex({ providers, connectors }: ProvidersPageProps) {
    const [dialog, setDialog] = useState<DialogState>(null);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Providers" />

            <div className="flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">Providers</h1>
                        <p className="text-muted-foreground text-sm">DNS backends this app pushes records to.</p>
                    </div>
                    <Button onClick={() => setDialog({ mode: 'create' })}>
                        <Plus className="size-4" />
                        Add provider
                    </Button>
                </div>

                {providers.length === 0 ? (
                    <div className="flex flex-1 flex-col items-center justify-center gap-4 rounded-xl border border-dashed p-12 text-center">
                        <EmptyProvidersIllustration className="text-muted-foreground" />
                        <div className="space-y-1">
                            <h2 className="text-base font-medium">No providers yet</h2>
                            <p className="text-muted-foreground max-w-sm text-sm">
                                Connect Cloudflare, Pi-hole or another DNS backend to start managing records from one place.
                            </p>
                        </div>
                        <Button onClick={() => setDialog({ mode: 'create' })}>
                            <Plus className="size-4" />
                            Connect your first provider
                        </Button>
                    </div>
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {providers.map((provider) => (
                            <ProviderCard key={provider.id} provider={provider} onEdit={(p) => setDialog({ mode: 'edit', provider: p })} />
                        ))}
                    </div>
                )}
            </div>

            {dialog && (
                <ProviderFormDialog
                    key={dialog.mode === 'edit' ? `edit-${dialog.provider.id}` : 'create'}
                    connectors={connectors}
                    provider={dialog.mode === 'edit' ? dialog.provider : null}
                    onClose={() => setDialog(null)}
                />
            )}

            <FlashToast />
        </AppLayout>
    );
}
