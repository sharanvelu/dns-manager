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

export function RefreshControls() {
    const [refreshing, setRefreshing] = useState(false);
    const [interval, setIntervalValue] = useState<string>(() => {
        const stored = typeof window !== 'undefined' ? localStorage.getItem(STORAGE_KEY) : null;

        return stored && INTERVALS.some((option) => option.value === stored) ? stored : 'off';
    });
    const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);

    const refresh = () => {
        setRefreshing(true);
        reloadEntries(() => setRefreshing(false));
    };

    useEffect(() => {
        localStorage.setItem(STORAGE_KEY, interval);

        if (timerRef.current) {
            clearInterval(timerRef.current);
            timerRef.current = null;
        }

        if (interval !== 'off') {
            timerRef.current = setInterval(() => {
                // Skip while the tab is in the background.
                if (!document.hidden) {
                    reloadEntries();
                }
            }, Number(interval) * 1000);
        }

        return () => {
            if (timerRef.current) {
                clearInterval(timerRef.current);
            }
        };
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
