import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { router } from '@inertiajs/react';
import { Search, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { RECORD_TYPES, SYNC_STATUSES, type EntriesScope, type EntryFilters, type ZoneOption } from './types';

const ALL = 'all';

export interface ProviderOption {
    id: number;
    name: string;
}

interface EntriesFilterBarProps {
    scope: EntriesScope;
    filters: EntryFilters;
    zones: ZoneOption[];
    providers: ProviderOption[];
}

export function EntriesFilterBar({ scope, filters, zones, providers }: EntriesFilterBarProps) {
    const [search, setSearch] = useState(filters.search ?? '');
    const skippedInitial = useRef(false);
    const suppressNextSearchApply = useRef(false);
    const zoneLocked = scope.zone !== undefined;

    const apply = (overrides: Partial<Record<keyof EntryFilters, string>>) => {
        const next: Record<string, string> = {
            search: search.trim(),
            type: filters.type ?? '',
            provider: filters.provider ?? '',
            status: filters.status ?? '',
            ...(zoneLocked ? {} : { zone: filters.zone ?? '' }),
            // Filtering must not reset the active column sort.
            sort: filters.sort ?? '',
            direction: filters.direction ?? '',
            ...overrides,
        };

        const params = Object.fromEntries(Object.entries(next).filter(([, value]) => value !== ''));

        router.get(scope.baseUrl, params, { preserveState: true, replace: true });
    };

    useEffect(() => {
        if (!skippedInitial.current) {
            skippedInitial.current = true;
            return;
        }

        if (suppressNextSearchApply.current) {
            suppressNextSearchApply.current = false;
            return;
        }

        const timeout = setTimeout(() => apply({ search: search.trim() }), 300);

        return () => clearTimeout(timeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const hasActiveFilters = Boolean(search.trim() || filters.type || filters.provider || filters.status || (!zoneLocked && filters.zone));

    return (
        <div className="flex flex-wrap items-center gap-2">
            <div className="relative min-w-56 flex-1">
                <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <Input
                    type="search"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder="Search by name or content…"
                    className="h-9 pl-9"
                    aria-label="Search entries"
                />
            </div>

            {!zoneLocked && (
                <Select value={filters.zone ?? ALL} onValueChange={(value) => apply({ zone: value === ALL ? '' : value })}>
                    <SelectTrigger className="h-9 w-[160px]" aria-label="Filter by zone">
                        <SelectValue placeholder="Zone" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL}>All zones</SelectItem>
                        {zones.map((zone) => (
                            <SelectItem key={zone.id} value={String(zone.id)} className="font-mono text-xs">
                                {zone.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            )}

            <Select value={filters.type ?? ALL} onValueChange={(value) => apply({ type: value === ALL ? '' : value })}>
                <SelectTrigger className="h-9 w-[130px]" aria-label="Filter by record type">
                    <SelectValue placeholder="Type" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={ALL}>All types</SelectItem>
                    {RECORD_TYPES.map((type) => (
                        <SelectItem key={type} value={type}>
                            {type}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            <Select value={filters.provider ?? ALL} onValueChange={(value) => apply({ provider: value === ALL ? '' : value })}>
                <SelectTrigger className="h-9 w-[160px]" aria-label="Filter by provider">
                    <SelectValue placeholder="Provider" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={ALL}>All providers</SelectItem>
                    {providers.map((provider) => (
                        <SelectItem key={provider.id} value={String(provider.id)}>
                            {provider.name}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            <Select value={filters.status ?? ALL} onValueChange={(value) => apply({ status: value === ALL ? '' : value })}>
                <SelectTrigger className="h-9 w-[140px]" aria-label="Filter by sync status">
                    <SelectValue placeholder="Status" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={ALL}>All statuses</SelectItem>
                    {SYNC_STATUSES.map((status) => (
                        <SelectItem key={status} value={status} className="capitalize">
                            {status}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            {hasActiveFilters && (
                <Button
                    variant="ghost"
                    size="sm"
                    className="text-muted-foreground h-9"
                    onClick={() => {
                        suppressNextSearchApply.current = true;
                        setSearch('');
                        // Clearing filters keeps the active column sort.
                        const params = Object.fromEntries(
                            Object.entries({ sort: filters.sort ?? '', direction: filters.direction ?? '' }).filter(([, value]) => value !== ''),
                        );
                        router.get(scope.baseUrl, params, { preserveState: true, replace: true });
                    }}
                >
                    <X className="size-3.5" />
                    Clear
                </Button>
            )}
        </div>
    );
}
