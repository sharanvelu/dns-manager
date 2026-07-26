import { EmptyActivityIllustration } from '@/components/icons';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ChevronRight, LoaderCircle, TriangleAlert } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

interface ActivityCauser {
    id: number;
    name: string;
}

interface ActivityChanges {
    attributes?: Record<string, unknown>;
    old?: Record<string, unknown>;
}

interface ActivityItem {
    id: number;
    logName: string;
    event: string;
    description: string;
    causer: ActivityCauser | null;
    subjectType: 'entry' | 'provider' | 'user' | 'zone' | null;
    subjectId: number | null;
    subjectLabel: string | null;
    changes: ActivityChanges | null;
    createdAt: string;
}

interface ActivityMeta {
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
}

interface ActivityResponse {
    data: ActivityItem[];
    meta: ActivityMeta;
}

interface ActivityLogDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    subjectType: 'entry' | 'provider' | 'zone';
    subjectId: number;
    subjectLabel: string;
    /** JSON endpoint to fetch from — zone contexts pass `/zones/{id}/activity/data`. */
    dataUrl?: string;
}

/** Tiny relative-time formatter for ISO 8601 timestamps ("3m ago", "2d ago"). */
function relativeTime(iso: string): string {
    const seconds = Math.round((Date.now() - new Date(iso).getTime()) / 1000);

    if (seconds < 45) return 'just now';
    if (seconds < 3600) return `${Math.max(1, Math.round(seconds / 60))}m ago`;
    if (seconds < 86400) return `${Math.round(seconds / 3600)}h ago`;
    if (seconds < 30 * 86400) return `${Math.round(seconds / 86400)}d ago`;

    return new Date(iso).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

/** Event → badge tint, following the status color language in DESIGN.md. */
function eventBadgeClass(event: string): string {
    if (event === 'created') return 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400';
    if (event === 'updated') return 'bg-amber-500/10 text-amber-700 dark:text-amber-400';
    if (event === 'deleted' || event.includes('delete')) return 'bg-red-500/10 text-red-700 dark:text-red-400';

    return 'text-muted-foreground';
}

function formatValue(value: unknown): string {
    if (value === null || value === undefined) return '—';
    if (typeof value === 'string') return value === '' ? '""' : value;
    if (typeof value === 'object') return JSON.stringify(value);

    return String(value);
}

/** Collapsible field-level old → new summary for a log item's recorded changes. */
function ChangeSummary({ changes }: { changes: ActivityChanges }) {
    const attributes = changes.attributes ?? {};
    const old = changes.old ?? {};
    const fields = Array.from(new Set([...Object.keys(attributes), ...Object.keys(old)]));

    if (fields.length === 0) {
        return null;
    }

    return (
        <Collapsible>
            <CollapsibleTrigger className="text-muted-foreground hover:text-foreground group mt-1.5 flex items-center gap-1 text-xs transition-colors">
                <ChevronRight className="size-3 transition-transform group-data-[state=open]:rotate-90" />
                {fields.length} changed {fields.length === 1 ? 'field' : 'fields'}
            </CollapsibleTrigger>
            <CollapsibleContent>
                <dl className="mt-1.5 space-y-1.5 border-l pl-3">
                    {fields.map((field) => (
                        <div key={field} className="text-xs">
                            <dt className="text-muted-foreground">{field}</dt>
                            <dd className="mt-0.5 flex flex-wrap items-center gap-1">
                                {field in old && (
                                    <code className="bg-muted rounded px-1 py-0.5 font-mono text-[11px] break-all line-through opacity-70">
                                        {formatValue(old[field])}
                                    </code>
                                )}
                                {field in old && field in attributes && <span className="text-muted-foreground">→</span>}
                                {field in attributes && (
                                    <code className="bg-muted rounded px-1 py-0.5 font-mono text-[11px] break-all">
                                        {formatValue(attributes[field])}
                                    </code>
                                )}
                            </dd>
                        </div>
                    ))}
                </dl>
            </CollapsibleContent>
        </Collapsible>
    );
}

export function ActivityLogDialog({ open, onOpenChange, subjectType, subjectId, subjectLabel, dataUrl = '/activity/data' }: ActivityLogDialogProps) {
    const [items, setItems] = useState<ActivityItem[]>([]);
    const [meta, setMeta] = useState<ActivityMeta | null>(null);
    const [loading, setLoading] = useState(false);
    const [loadingMore, setLoadingMore] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const fetchPage = useCallback(
        (page: number, append: boolean) => {
            if (append) {
                setLoadingMore(true);
            } else {
                setLoading(true);
            }
            setError(null);

            const params = new URLSearchParams({ subject_type: subjectType, subject_id: String(subjectId), page: String(page) });

            fetch(`${dataUrl}?${params}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            })
                .then(async (response) => {
                    if (!response.ok) {
                        throw new Error(`Failed to load activity (HTTP ${response.status}).`);
                    }

                    const body: ActivityResponse = await response.json();
                    setItems((current) => (append ? [...current, ...body.data] : body.data));
                    setMeta(body.meta);
                })
                .catch((fetchError: Error) => setError(fetchError.message))
                .finally(() => {
                    setLoading(false);
                    setLoadingMore(false);
                });
        },
        [subjectType, subjectId, dataUrl],
    );

    useEffect(() => {
        if (!open) return;

        setItems([]);
        setMeta(null);
        fetchPage(1, false);
    }, [open, fetchPage]);

    // The full-log page is the GLOBAL trail — hide the link for users who
    // can only see zone-scoped activity.
    const canViewGlobalActivity = usePage<SharedData>().props.auth.can.viewGlobalActivity;
    const fullLogUrl = `/activity?subject_type=${subjectType}&subject_id=${subjectId}`;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Activity — {subjectLabel}</DialogTitle>
                    <DialogDescription>Audit trail for this {subjectType}, newest first.</DialogDescription>
                </DialogHeader>

                <div className="-mr-2 max-h-[55vh] overflow-y-auto pr-2">
                    {loading && (
                        <div className="space-y-3">
                            {[0, 1, 2].map((index) => (
                                <div key={index} className="rounded-lg border p-3">
                                    <div className="flex items-center justify-between gap-2">
                                        <Skeleton className="h-5 w-24" />
                                        <Skeleton className="h-3 w-14" />
                                    </div>
                                    <Skeleton className="mt-2 h-3 w-44" />
                                </div>
                            ))}
                        </div>
                    )}

                    {!loading && error && (
                        <div className="flex items-start gap-2 rounded-lg border border-red-500/40 bg-red-500/10 p-3 text-sm text-red-700 dark:text-red-400">
                            <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                            <span className="min-w-0 flex-1 break-words">{error}</span>
                            <Button variant="outline" size="sm" className="shrink-0" onClick={() => fetchPage(1, false)}>
                                Retry
                            </Button>
                        </div>
                    )}

                    {!loading && !error && items.length === 0 && (
                        <div className="flex flex-col items-center gap-2 py-8 text-center">
                            <EmptyActivityIllustration className="text-muted-foreground h-20 w-auto" />
                            <p className="text-muted-foreground text-sm">No activity recorded yet.</p>
                        </div>
                    )}

                    {!loading && !error && items.length > 0 && (
                        <>
                            <ol className="space-y-3">
                                {items.map((item) => (
                                    <li key={item.id} className="rounded-lg border p-3">
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="flex min-w-0 flex-wrap items-center gap-1.5">
                                                <Badge variant="secondary" className={cn('capitalize', eventBadgeClass(item.event))}>
                                                    {item.event || 'event'}
                                                </Badge>
                                                <span className="min-w-0 text-sm break-words">{item.description}</span>
                                            </div>
                                            <time
                                                className="text-muted-foreground shrink-0 text-xs whitespace-nowrap"
                                                title={new Date(item.createdAt).toLocaleString()}
                                            >
                                                {relativeTime(item.createdAt)}
                                            </time>
                                        </div>
                                        <p className="text-muted-foreground mt-1 text-xs">
                                            by {item.causer ? item.causer.name : <span className="text-muted-foreground/70">System</span>}
                                        </p>
                                        {item.changes && <ChangeSummary changes={item.changes} />}
                                    </li>
                                ))}
                            </ol>

                            {meta && meta.currentPage < meta.lastPage && (
                                <div className="mt-3 flex justify-center">
                                    <Button variant="outline" size="sm" onClick={() => fetchPage(meta.currentPage + 1, true)} disabled={loadingMore}>
                                        {loadingMore && <LoaderCircle className="size-3.5 animate-spin" />}
                                        Load more
                                    </Button>
                                </div>
                            )}
                        </>
                    )}
                </div>

                {canViewGlobalActivity && (
                    <DialogFooter className="sm:justify-start">
                        <Link
                            href={fullLogUrl}
                            className="text-muted-foreground hover:text-foreground text-sm underline-offset-4 transition-colors hover:underline"
                            onClick={() => onOpenChange(false)}
                        >
                            Open full activity log
                        </Link>
                    </DialogFooter>
                )}
            </DialogContent>
        </Dialog>
    );
}
