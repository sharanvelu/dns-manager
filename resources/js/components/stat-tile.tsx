import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { type ComponentType, type ReactNode, type SVGProps } from 'react';

export type StatAccent = 'neutral' | 'green' | 'amber' | 'red';

const accentText: Record<StatAccent, string> = {
    neutral: 'text-foreground',
    green: 'text-emerald-600 dark:text-emerald-400',
    amber: 'text-amber-600 dark:text-amber-400',
    red: 'text-red-600 dark:text-red-400',
};

const accentIcon: Record<StatAccent, string> = {
    neutral: 'text-muted-foreground/70',
    green: 'text-emerald-500 dark:text-emerald-400',
    amber: 'text-amber-500 dark:text-amber-400',
    red: 'text-red-500 dark:text-red-400',
};

/** Small muted label over a large tabular-nums value; accent only when warranted. */
export function StatTile({
    label,
    value,
    icon: Icon,
    accent = 'neutral',
    action,
}: {
    label: string;
    value: number;
    icon: ComponentType<SVGProps<SVGSVGElement>>;
    accent?: StatAccent;
    /** Optional inline action (small button) shown beside the value. */
    action?: ReactNode;
}) {
    return (
        <Card className="flex flex-col gap-1 p-4">
            <div className="flex items-center justify-between gap-2">
                <span className="text-muted-foreground text-xs font-medium">{label}</span>
                <Icon className={cn('size-4 shrink-0', accentIcon[accent])} />
            </div>
            <div className="flex items-center justify-between gap-2">
                <span className={cn('text-2xl font-semibold tracking-tight tabular-nums', accentText[accent])}>{value.toLocaleString()}</span>
                {action}
            </div>
        </Card>
    );
}
