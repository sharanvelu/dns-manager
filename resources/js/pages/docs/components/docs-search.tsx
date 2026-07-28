import { router } from '@inertiajs/react';
import { CornerDownLeft, FileText, Hash, Search } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';

import { searchDocs, type SearchResult } from '../lib/search';
import type { SearchEntry } from '../types';

function resultUrl(result: SearchResult): string {
    const base = result.slug === 'index' ? '/docs' : `/docs/${result.slug}`;

    return result.heading ? `${base}#${result.heading.id}` : base;
}

interface DocsSearchProps {
    index: SearchEntry[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

export function DocsSearch({ index, open, onOpenChange }: DocsSearchProps) {
    const [query, setQuery] = useState('');
    const [selected, setSelected] = useState(0);
    const inputRef = useRef<HTMLInputElement>(null);

    const results = useMemo(() => searchDocs(index, query), [index, query]);

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                onOpenChange(!open);
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [open, onOpenChange]);

    useEffect(() => {
        setSelected(0);
    }, [query]);

    useEffect(() => {
        if (open) {
            setQuery('');
            setSelected(0);
        }
    }, [open]);

    const visit = (result: SearchResult) => {
        onOpenChange(false);
        router.visit(resultUrl(result));
    };

    const onInputKeyDown = (event: React.KeyboardEvent) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setSelected((current) => Math.min(current + 1, results.length - 1));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setSelected((current) => Math.max(current - 1, 0));
        } else if (event.key === 'Enter' && results[selected]) {
            event.preventDefault();
            visit(results[selected]);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="top-[20%] translate-y-0 gap-0 overflow-hidden p-0 sm:max-w-lg">
                <DialogTitle className="sr-only">Search documentation</DialogTitle>
                <DialogDescription className="sr-only">Search the documentation pages and headings.</DialogDescription>

                <div className="flex items-center gap-2 border-b px-3">
                    <Search className="text-muted-foreground size-4 shrink-0" />
                    <input
                        ref={inputRef}
                        autoFocus
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        onKeyDown={onInputKeyDown}
                        placeholder="Search docs…"
                        className="placeholder:text-muted-foreground h-11 flex-1 bg-transparent text-sm outline-none"
                    />
                    <kbd className="bg-muted text-muted-foreground rounded border px-1 font-mono text-[10px]">esc</kbd>
                </div>

                <div className="max-h-80 overflow-y-auto p-1.5">
                    {query.trim().length >= 2 && results.length === 0 && (
                        <p className="text-muted-foreground px-3 py-8 text-center text-sm">No results for “{query}”.</p>
                    )}

                    {query.trim().length < 2 && (
                        <p className="text-muted-foreground px-3 py-8 text-center text-sm">Type to search pages and headings…</p>
                    )}

                    {results.map((result, i) => (
                        <button
                            key={resultUrl(result)}
                            type="button"
                            onClick={() => visit(result)}
                            onMouseEnter={() => setSelected(i)}
                            className={cn(
                                'flex w-full cursor-pointer items-center gap-2.5 rounded-md px-3 py-2 text-left text-sm',
                                i === selected ? 'bg-docs-accent-soft text-docs-accent' : 'text-foreground',
                            )}
                        >
                            {result.heading ? (
                                <Hash className="text-muted-foreground size-3.5 shrink-0" />
                            ) : (
                                <FileText className="text-muted-foreground size-3.5 shrink-0" />
                            )}
                            <span className="min-w-0 flex-1">
                                <span className="block truncate font-medium">{result.heading ? result.heading.text : result.title}</span>
                                <span className="text-muted-foreground block truncate text-xs">
                                    {result.heading ? result.title : result.description}
                                </span>
                            </span>
                            {i === selected && <CornerDownLeft className="text-muted-foreground size-3.5 shrink-0" />}
                        </button>
                    ))}
                </div>
            </DialogContent>
        </Dialog>
    );
}
