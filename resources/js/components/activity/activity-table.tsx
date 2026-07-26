import {
    SUBJECT_TYPES,
    type ActivityChanges,
    type ActivityFilters,
    type ActivityItem,
    type ActivityMeta,
    type ActivitySubjectChip,
} from '@/components/activity/types';
import { EmptyActivityIllustration } from '@/components/icons';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { ChevronDown, ChevronRight, X } from 'lucide-react';
import { Fragment, useState } from 'react';

export interface ActivityTableProps {
    activities: { data: ActivityItem[]; meta: ActivityMeta };
    filters: ActivityFilters;
    users: Array<{ id: number; name: string }>;
    events: string[];
    /** Where filter/pagination navigations go: '/activity' or `/zones/${id}/activity`. */
    baseUrl: string;
    /** Hide the subject-type select when the backend pre-scopes the log (zone page). */
    hideSubjectTypeFilter?: boolean;
    /** Deep-linked subject chip ("Showing: …" with a clear button). */
    subjectChip?: ActivitySubjectChip | null;
}

const ALL = 'all';

/** Tiny relative-time formatter for ISO 8601 timestamps ("3m ago", "2d ago"). */
function relativeTime(iso: string): string {
    const seconds = Math.round((Date.now() - new Date(iso).getTime()) / 1000);

    if (seconds < 45) return 'just now';
    if (seconds < 3600) return `${Math.max(1, Math.round(seconds / 60))}m ago`;
    if (seconds < 86400) return `${Math.round(seconds / 3600)}h ago`;
    if (seconds < 30 * 86400) return `${Math.round(seconds / 86400)}d ago`;

    return new Date(iso).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

/** Event badge tint per DESIGN.md status color language (label always accompanies the color). */
function eventBadgeClass(event: string | null): string {
    switch (event) {
        case 'created':
        case 'login':
            return 'border-transparent bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-400';
        case 'updated':
        case 'providers-changed':
            return 'border-transparent bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-400';
        case 'deleted':
        case 'delete-requested':
            return 'border-transparent bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-400';
        default:
            return 'border-transparent bg-muted text-muted-foreground';
    }
}

function formatValue(value: unknown): string {
    if (value === null || value === undefined) return '—';
    if (typeof value === 'boolean') return value ? 'true' : 'false';
    if (typeof value === 'object') return JSON.stringify(value, null, 2);

    return String(value);
}

function ChangeList({ changes }: { changes: ActivityChanges }) {
    const attributes = changes.attributes ?? {};
    const old = changes.old ?? {};
    const keys = Array.from(new Set([...Object.keys(old), ...Object.keys(attributes)]));

    if (keys.length === 0) {
        return <p className="text-muted-foreground text-xs">No field-level changes were recorded.</p>;
    }

    return (
        <dl className="grid gap-1.5">
            {keys.map((key) => {
                const hasOld = key in old;
                const hasNew = key in attributes;

                return (
                    <div key={key} className="flex flex-wrap items-baseline gap-x-2 gap-y-1 text-xs">
                        <dt className="text-muted-foreground w-32 shrink-0 truncate font-medium" title={key}>
                            {key}
                        </dt>
                        <dd className="flex min-w-0 flex-wrap items-baseline gap-2">
                            {hasOld && (
                                <code
                                    className={cn(
                                        'bg-muted rounded px-1.5 py-0.5 font-mono break-all whitespace-pre-wrap',
                                        hasNew && 'text-muted-foreground line-through',
                                    )}
                                >
                                    {formatValue(old[key])}
                                </code>
                            )}
                            {hasOld && hasNew && <span className="text-muted-foreground">→</span>}
                            {hasNew && (
                                <code className="bg-muted rounded px-1.5 py-0.5 font-mono break-all whitespace-pre-wrap">
                                    {formatValue(attributes[key])}
                                </code>
                            )}
                        </dd>
                    </div>
                );
            })}
        </dl>
    );
}

/** Filter bar + expandable rows table + prev/next pagination over a paginated activity log. */
export function ActivityTable({
    activities,
    filters,
    users,
    events,
    baseUrl,
    hideSubjectTypeFilter = false,
    subjectChip = null,
}: ActivityTableProps) {
    const [expanded, setExpanded] = useState<Set<number>>(new Set());

    const paramsFrom = (overrides: Partial<Record<string, string>> = {}): Record<string, string> => {
        const base: Record<string, string> = {
            subject_type: filters.subject_type ?? '',
            subject_id: filters.subject_id != null ? String(filters.subject_id) : '',
            event: filters.event ?? '',
            causer_id: filters.causer_id != null ? String(filters.causer_id) : '',
            log: filters.log ?? '',
            from: filters.from ?? '',
            to: filters.to ?? '',
            ...overrides,
        };

        return Object.fromEntries(Object.entries(base).filter(([, value]) => value !== ''));
    };

    const apply = (overrides: Partial<Record<string, string>>) => {
        router.get(baseUrl, paramsFrom(overrides), { preserveState: true, replace: true });
    };

    const goToPage = (page: number) => {
        router.get(baseUrl, { ...paramsFrom(), page: String(page) }, { preserveState: true, preserveScroll: true });
    };

    const clearAll = () => {
        router.get(baseUrl, {}, { preserveState: true, replace: true });
    };

    const toggleExpanded = (id: number) => {
        setExpanded((current) => {
            const next = new Set(current);
            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });
    };

    const hasActiveFilters = Object.keys(paramsFrom()).length > 0;
    const { data, meta } = activities;

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center gap-2">
                {!hideSubjectTypeFilter && (
                    <Select
                        value={filters.subject_type ?? ALL}
                        onValueChange={(value) => apply({ subject_type: value === ALL ? '' : value, subject_id: '' })}
                    >
                        <SelectTrigger className="h-9 w-[140px]" aria-label="Filter by subject type">
                            <SelectValue placeholder="Subject" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All subjects</SelectItem>
                            {SUBJECT_TYPES.map((type) => (
                                <SelectItem key={type.value} value={type.value}>
                                    {type.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                )}

                <Select value={filters.event ?? ALL} onValueChange={(value) => apply({ event: value === ALL ? '' : value })}>
                    <SelectTrigger className="h-9 w-[160px]" aria-label="Filter by event">
                        <SelectValue placeholder="Event" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL}>All events</SelectItem>
                        {events.map((event) => (
                            <SelectItem key={event} value={event}>
                                {event}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                <Select
                    value={filters.causer_id != null ? String(filters.causer_id) : ALL}
                    onValueChange={(value) => apply({ causer_id: value === ALL ? '' : value })}
                >
                    <SelectTrigger className="h-9 w-[160px]" aria-label="Filter by user">
                        <SelectValue placeholder="User" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL}>All users</SelectItem>
                        {users.map((user) => (
                            <SelectItem key={user.id} value={String(user.id)}>
                                {user.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                <Input
                    type="date"
                    value={filters.from ?? ''}
                    onChange={(event) => apply({ from: event.target.value })}
                    className="h-9 w-[150px]"
                    aria-label="From date"
                />
                <span className="text-muted-foreground text-xs">to</span>
                <Input
                    type="date"
                    value={filters.to ?? ''}
                    onChange={(event) => apply({ to: event.target.value })}
                    className="h-9 w-[150px]"
                    aria-label="To date"
                />

                {subjectChip && (
                    <Badge variant="secondary" className="gap-1.5 py-1 font-normal">
                        Showing:{' '}
                        <span className="max-w-48 truncate font-mono font-medium">
                            {subjectChip.label ?? `${subjectChip.type ?? 'subject'} #${subjectChip.id ?? '?'}`}
                        </span>
                        <button
                            type="button"
                            onClick={() => apply({ subject_type: '', subject_id: '' })}
                            className="hover:text-foreground -mr-0.5"
                            aria-label="Clear subject filter"
                        >
                            <X className="size-3" />
                        </button>
                    </Badge>
                )}

                {hasActiveFilters && (
                    <Button variant="ghost" size="sm" className="text-muted-foreground h-9" onClick={clearAll}>
                        <X className="size-3.5" />
                        Clear filters
                    </Button>
                )}
            </div>

            {data.length === 0 ? (
                <div className="flex flex-col items-center gap-3 rounded-lg border py-16 text-center">
                    <EmptyActivityIllustration className="text-muted-foreground" />
                    <p className="text-sm font-medium">{hasActiveFilters ? 'No activity matches these filters' : 'No activity yet'}</p>
                    <p className="text-muted-foreground max-w-sm text-xs">
                        {hasActiveFilters
                            ? 'Try widening the date range or removing a filter.'
                            : 'Changes to entries, zones, providers, and users will appear here.'}
                    </p>
                    {hasActiveFilters && (
                        <Button variant="outline" size="sm" onClick={clearAll}>
                            Clear filters
                        </Button>
                    )}
                </div>
            ) : (
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/40 border-b text-left">
                                <th className="w-8 px-2 py-2.5" aria-label="Details" />
                                <th className="text-muted-foreground px-3 py-2.5 text-xs font-medium">When</th>
                                <th className="text-muted-foreground px-3 py-2.5 text-xs font-medium">User</th>
                                <th className="text-muted-foreground px-3 py-2.5 text-xs font-medium">Event</th>
                                <th className="text-muted-foreground px-3 py-2.5 text-xs font-medium">Subject</th>
                                <th className="text-muted-foreground px-3 py-2.5 text-xs font-medium">Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.map((item) => {
                                const isExpanded = expanded.has(item.id);

                                return (
                                    <Fragment key={item.id}>
                                        <tr className="border-b last:border-b-0">
                                            <td className="px-2 py-2.5 align-top">
                                                {item.changes && (
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="text-muted-foreground size-6"
                                                        onClick={() => toggleExpanded(item.id)}
                                                        aria-expanded={isExpanded}
                                                        aria-label={isExpanded ? 'Hide changes' : 'Show changes'}
                                                    >
                                                        {isExpanded ? <ChevronDown className="size-3.5" /> : <ChevronRight className="size-3.5" />}
                                                    </Button>
                                                )}
                                            </td>
                                            <td
                                                className="text-muted-foreground px-3 py-2.5 align-top text-xs whitespace-nowrap"
                                                title={new Date(item.createdAt).toLocaleString()}
                                            >
                                                {relativeTime(item.createdAt)}
                                            </td>
                                            <td className="px-3 py-2.5 align-top text-xs">
                                                {item.causer ? item.causer.name : <span className="text-muted-foreground">System</span>}
                                            </td>
                                            <td className="px-3 py-2.5 align-top">
                                                <Badge variant="outline" className={eventBadgeClass(item.event)}>
                                                    {item.event ?? '—'}
                                                </Badge>
                                            </td>
                                            <td className="px-3 py-2.5 align-top text-xs">
                                                {item.subjectType ? (
                                                    <span className="flex items-baseline gap-1.5">
                                                        <span className="text-muted-foreground capitalize">{item.subjectType}</span>
                                                        <span className="font-mono">
                                                            {item.subjectLabel ?? (item.subjectId != null ? `#${item.subjectId}` : '')}
                                                        </span>
                                                    </span>
                                                ) : (
                                                    <span className="text-muted-foreground">—</span>
                                                )}
                                            </td>
                                            <td className="text-muted-foreground px-3 py-2.5 align-top text-xs">{item.description}</td>
                                        </tr>
                                        {isExpanded && item.changes && (
                                            <tr className="bg-muted/40 border-b last:border-b-0">
                                                <td />
                                                <td colSpan={5} className="px-3 py-3">
                                                    <ChangeList changes={item.changes} />
                                                </td>
                                            </tr>
                                        )}
                                    </Fragment>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}

            {meta.total > 0 && (
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-muted-foreground text-xs tabular-nums">
                        Page {meta.currentPage} of {meta.lastPage} · {meta.total} {meta.total === 1 ? 'event' : 'events'}
                    </p>
                    <div className="flex items-center gap-1">
                        <Button
                            variant="outline"
                            size="sm"
                            className="h-8 px-2.5 text-xs"
                            disabled={meta.currentPage <= 1}
                            onClick={() => goToPage(meta.currentPage - 1)}
                        >
                            Previous
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            className="h-8 px-2.5 text-xs"
                            disabled={meta.currentPage >= meta.lastPage}
                            onClick={() => goToPage(meta.currentPage + 1)}
                        >
                            Next
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}
