import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

const INTERVALS = [
    { value: 'off', label: 'Auto-reload off' },
    { value: '5', label: 'Every 5s' },
    { value: '15', label: 'Every 15s' },
    { value: '30', label: 'Every 30s' },
    { value: '60', label: 'Every 1m' },
    { value: '300', label: 'Every 5m' },
] as const;

const STORAGE_KEY = 'dns-manager:entries-auto-reload';

function reloadEntries(onFinish?: () => void) {
    router.reload({
        only: ['entries'],
        onFinish,
    });
}

function formatRemaining(seconds: number): string {
    if (seconds < 60) return `${seconds}s`;

    const minutes = Math.floor(seconds / 60);
    const rest = seconds % 60;

    return `${minutes}:${String(rest).padStart(2, '0')}`;
}

/** Small circular progress ring that fills as the countdown elapses. */
function CountdownRing({ total, remaining }: { total: number; remaining: number }) {
    const radius = 7;
    const circumference = 2 * Math.PI * radius;
    const progress = total > 0 ? (total - remaining) / total : 0;

    return (
        <svg viewBox="0 0 18 18" className="size-4 -rotate-90" aria-hidden>
            <circle cx="9" cy="9" r={radius} fill="none" strokeWidth="2" className="stroke-border" />
            <circle
                cx="9"
                cy="9"
                r={radius}
                fill="none"
                strokeWidth="2"
                strokeLinecap="round"
                strokeDasharray={circumference}
                strokeDashoffset={circumference * (1 - progress)}
                className="stroke-emerald-500 transition-[stroke-dashoffset] duration-1000 ease-linear dark:stroke-emerald-400"
            />
        </svg>
    );
}

export function RefreshControls() {
    const [refreshing, setRefreshing] = useState(false);
    const [interval, setIntervalValue] = useState<string>(() => {
        const stored = typeof window !== 'undefined' ? localStorage.getItem(STORAGE_KEY) : null;

        return stored && INTERVALS.some((option) => option.value === stored) ? stored : 'off';
    });
    const seconds = interval === 'off' ? 0 : Number(interval);
    const [remaining, setRemaining] = useState(seconds);
    const reloadingRef = useRef(false);

    const refresh = () => {
        setRefreshing(true);
        setRemaining(seconds);
        reloadEntries(() => setRefreshing(false));
    };

    useEffect(() => {
        localStorage.setItem(STORAGE_KEY, interval);
        setRemaining(seconds);

        if (interval === 'off') {
            return;
        }

        const ticker = setInterval(() => {
            setRemaining((current) => {
                if (current > 1) {
                    return current - 1;
                }

                // Countdown reached zero: reload (unless the tab is hidden or a
                // reload is already in flight) and restart the countdown.
                if (!document.hidden && !reloadingRef.current) {
                    reloadingRef.current = true;
                    reloadEntries(() => {
                        reloadingRef.current = false;
                    });
                }

                return seconds;
            });
        }, 1000);

        return () => clearInterval(ticker);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [interval]);

    return (
        <div className="flex items-center gap-2">
            <Tooltip>
                <TooltipTrigger asChild>
                    <Button variant="outline" size="icon" onClick={refresh} disabled={refreshing} aria-label="Refresh entries">
                        <RefreshCw className={cn('size-4', refreshing && 'animate-spin')} />
                    </Button>
                </TooltipTrigger>
                <TooltipContent>Refresh the list without reloading the page</TooltipContent>
            </Tooltip>

            {interval !== 'off' && (
                <Tooltip>
                    <TooltipTrigger asChild>
                        <div
                            className="text-muted-foreground flex h-9 cursor-default items-center gap-1.5 rounded-md border px-2.5 text-xs tabular-nums"
                            role="timer"
                            aria-label={`Next refresh in ${remaining} seconds`}
                        >
                            <CountdownRing total={seconds} remaining={remaining} />
                            {formatRemaining(remaining)}
                        </div>
                    </TooltipTrigger>
                    <TooltipContent>Next auto-refresh</TooltipContent>
                </Tooltip>
            )}

            <Select value={interval} onValueChange={setIntervalValue}>
                <SelectTrigger className="h-9 w-[150px] text-xs" aria-label="Auto-reload interval">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {INTERVALS.map((option) => (
                        <SelectItem key={option.value} value={option.value} className="text-xs">
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}
