import { cn } from '@/lib/utils';
import { SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { CircleAlert, CircleCheck } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Flash {
    success?: string | null;
    error?: string | null;
}

/**
 * Subtle bottom-right toast for flash messages, auto-dismissed after 4s.
 */
export function FlashToast() {
    const { flash } = usePage<SharedData & { flash?: Flash }>().props;
    const [visible, setVisible] = useState<{ kind: 'success' | 'error'; message: string } | null>(null);

    useEffect(() => {
        const message = flash?.success ?? flash?.error;

        if (!message) {
            return;
        }

        setVisible({ kind: flash?.success ? 'success' : 'error', message });

        const timer = setTimeout(() => setVisible(null), 4000);

        return () => clearTimeout(timer);
        // Re-run whenever a new response arrives (the flash object identity changes).
    }, [flash]);

    if (!visible) {
        return null;
    }

    return (
        <div
            role="status"
            className={cn(
                'bg-background fixed right-4 bottom-4 z-50 flex max-w-sm items-start gap-2 rounded-lg border px-4 py-3 text-sm shadow-lg',
                'animate-in fade-in-0 slide-in-from-bottom-2',
                visible.kind === 'success'
                    ? 'border-emerald-300 text-emerald-800 dark:border-emerald-500/30 dark:text-emerald-300'
                    : 'border-red-300 text-red-800 dark:border-red-500/30 dark:text-red-300',
            )}
        >
            {visible.kind === 'success' ? <CircleCheck className="mt-0.5 size-4 shrink-0" /> : <CircleAlert className="mt-0.5 size-4 shrink-0" />}
            <span>{visible.message}</span>
        </div>
    );
}
