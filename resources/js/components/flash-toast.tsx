import { cn } from '@/lib/utils';
import { usePage } from '@inertiajs/react';
import { CircleAlert, CircleCheck, X } from 'lucide-react';
import { useEffect, useState } from 'react';

type FlashProps = {
    flash?: { success?: string | null; error?: string | null };
    [key: string]: unknown;
};

/** Auto-dismissing (4s) toast-like banner for flash messages, fixed bottom-right. */
export function FlashToast() {
    const { flash } = usePage<FlashProps>().props;
    const [toast, setToast] = useState<{ message: string; kind: 'success' | 'error' } | null>(null);

    useEffect(() => {
        const message = flash?.success ?? flash?.error;

        if (!message) return;

        setToast({ message, kind: flash?.success ? 'success' : 'error' });

        const timeout = setTimeout(() => setToast(null), 4000);

        return () => clearTimeout(timeout);
    }, [flash]);

    if (!toast) return null;

    return (
        <div
            role="status"
            className="bg-background animate-in fade-in-0 slide-in-from-bottom-2 fixed right-4 bottom-4 z-50 flex max-w-sm items-start gap-2.5 rounded-lg border px-4 py-3 text-sm shadow-lg"
        >
            {toast.kind === 'success' ? (
                <CircleCheck className="mt-px size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
            ) : (
                <CircleAlert className="mt-px size-4 shrink-0 text-red-600 dark:text-red-400" />
            )}
            <p className={cn('leading-snug')}>{toast.message}</p>
            <button
                type="button"
                onClick={() => setToast(null)}
                className="text-muted-foreground hover:text-foreground ml-1 shrink-0 rounded-sm transition-colors"
                aria-label="Dismiss"
            >
                <X className="size-3.5" />
            </button>
        </div>
    );
}
