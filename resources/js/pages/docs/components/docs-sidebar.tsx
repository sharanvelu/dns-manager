import { Link } from '@inertiajs/react';

import { cn } from '@/lib/utils';

import type { DocNavPage } from '../types';

interface DocsSidebarProps {
    pages: DocNavPage[];
    current: string;
    docsSiteUrl: string;
    onNavigate?: () => void;
}

export function DocsSidebarNav({ pages, current, docsSiteUrl, onNavigate }: DocsSidebarProps) {
    return (
        <nav className="flex h-full flex-col gap-1 text-sm" aria-label="Documentation">
            <p className="text-muted-foreground mb-1.5 px-2.5 text-[11px] font-semibold tracking-wider uppercase">Documentation</p>

            {pages.map((page) => {
                const active = page.slug === current;

                return (
                    <Link
                        key={page.slug}
                        href={page.slug === 'index' ? '/docs' : `/docs/${page.slug}`}
                        onClick={onNavigate}
                        aria-current={active ? 'page' : undefined}
                        className={cn(
                            'relative rounded-md px-2.5 py-1.5 transition-colors',
                            active
                                ? 'bg-docs-accent-soft text-docs-accent font-medium'
                                : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                        )}
                    >
                        {active && <span className="bg-docs-accent absolute inset-y-1.5 left-0 w-0.5 rounded-full" aria-hidden />}
                        {page.title}
                    </Link>
                );
            })}

            <div className="text-muted-foreground mt-auto border-t pt-3 text-xs">
                <p className="px-2.5">
                    Covers your installed version.{' '}
                    <a href={docsSiteUrl} target="_blank" rel="noreferrer" className="text-docs-accent font-medium hover:underline">
                        Latest docs ↗
                    </a>
                </p>
            </div>
        </nav>
    );
}
