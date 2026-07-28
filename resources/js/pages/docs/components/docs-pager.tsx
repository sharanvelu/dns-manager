import { Link } from '@inertiajs/react';
import { ArrowLeft, ArrowRight } from 'lucide-react';

import type { DocNavPage } from '../types';

export function DocsPager({ pages, current }: { pages: DocNavPage[]; current: string }) {
    const index = pages.findIndex((page) => page.slug === current);

    if (index === -1) {
        return null;
    }

    const previous = index > 0 ? pages[index - 1] : null;
    const next = index < pages.length - 1 ? pages[index + 1] : null;

    if (!previous && !next) {
        return null;
    }

    const url = (page: DocNavPage) => (page.slug === 'index' ? '/docs' : `/docs/${page.slug}`);

    return (
        <nav className="mt-10 grid grid-cols-1 gap-3 border-t pt-6 sm:grid-cols-2" aria-label="Pagination">
            {previous ? (
                <Link
                    href={url(previous)}
                    className="group hover:border-docs-accent/50 hover:bg-muted/40 flex flex-col gap-1 rounded-lg border p-4 transition-colors"
                >
                    <span className="text-muted-foreground flex items-center gap-1 text-xs">
                        <ArrowLeft className="size-3.5 transition-transform group-hover:-translate-x-0.5" />
                        Previous
                    </span>
                    <span className="group-hover:text-docs-accent text-sm font-medium">{previous.title}</span>
                </Link>
            ) : (
                <span aria-hidden />
            )}

            {next && (
                <Link
                    href={url(next)}
                    className="group hover:border-docs-accent/50 hover:bg-muted/40 flex flex-col items-end gap-1 rounded-lg border p-4 text-right transition-colors"
                >
                    <span className="text-muted-foreground flex items-center gap-1 text-xs">
                        Next
                        <ArrowRight className="size-3.5 transition-transform group-hover:translate-x-0.5" />
                    </span>
                    <span className="group-hover:text-docs-accent text-sm font-medium">{next.title}</span>
                </Link>
            )}
        </nav>
    );
}
