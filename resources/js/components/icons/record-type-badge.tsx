import { cn } from '@/lib/utils';

const recordTypeStyles: Record<string, string> = {
    A: 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-400',
    AAAA: 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-400',
    CNAME: 'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-400',
    MX: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
    TXT: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400',
    SRV: 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-400',
    NS: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-400',
    CAA: 'bg-orange-100 text-orange-700 dark:bg-orange-500/15 dark:text-orange-400',
    PTR: 'bg-teal-100 text-teal-700 dark:bg-teal-500/15 dark:text-teal-400',
};

const fallbackStyle = 'bg-zinc-100 text-zinc-600 dark:bg-zinc-500/15 dark:text-zinc-400';

export interface RecordTypeBadgeProps {
    type: string;
    className?: string;
}

/**
 * Small monospace badge for a DNS record type (A, AAAA, CNAME, ...).
 * Unknown types fall back to a neutral zinc style.
 */
export function RecordTypeBadge({ type, className }: RecordTypeBadgeProps) {
    const normalized = type.trim().toUpperCase();

    return (
        <span
            className={cn(
                'inline-flex items-center justify-center rounded px-1.5 py-0.5 font-mono text-[11px] leading-none font-semibold tracking-wide',
                recordTypeStyles[normalized] ?? fallbackStyle,
                className,
            )}
        >
            {normalized}
        </span>
    );
}
