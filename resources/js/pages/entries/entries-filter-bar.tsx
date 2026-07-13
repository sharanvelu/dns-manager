import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { router } from '@inertiajs/react';
import { Search, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { RECORD_TYPES, SYNC_STATUSES, type EntryFilters, type ProviderInfo } from './types';

const ALL = 'all';

interface EntriesFilterBarProps {
    filters: EntryFilters;
    providers: ProviderInfo[];
}

export function EntriesFilterBar({ filters, providers }: EntriesFilterBarProps) {
    const [search, setSearch] = useState(filters.search ?? '');
    const skippedInitial = useRef(false);
    const suppressNextSearchApply = useRef(false);

    const apply = (overrides: Partial<Record<keyof EntryFilters, string>>) => {
        const next: Record<string, string> = {
            search: search.trim(),
            type: filters.type ?? '',
            provider: filters.provider ?? '',
            status: filters.status ?? '',
            ...overrides,
        };

        const params = Object.fromEntries(Object.entries(next).filter(([, value]) => value !== ''));

        router.get('/entries', params, { preserveState: true, replace: true });
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

    const hasActiveFilters = Boolean(search.trim() || filters.type || filters.provider || filters.status);

    return (
        <div className="flex flex-wrap items-center gap-2">
            <div className="relative min-w-56 flex-1">
                <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                    type="search"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder="Search by name or content…"
                    className="h-9 pl-9"
                    aria-label="Search entries"
                />
            </div>

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
                    className="h-9 text-muted-foreground"
                    onClick={() => {
                        suppressNextSearchApply.current = true;
                        setSearch('');
                        router.get('/entries', {}, { preserveState: true, replace: true });
                    }}
                >
                    <X className="size-3.5" />
                    Clear
                </Button>
            )}
        </div>
    );
}
