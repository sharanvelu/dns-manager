import { useEffect, useState } from 'react';

import { cn } from '@/lib/utils';

import type { DocHeading } from '../types';

/**
 * "On this page" rail with IntersectionObserver scroll-spy. Rendered only
 * when the page actually has headings; xl+ only (handled by the caller).
 */
export function DocsToc({ headings }: { headings: DocHeading[] }) {
    const [activeId, setActiveId] = useState<string | null>(null);

    useEffect(() => {
        if (headings.length === 0) {
            return;
        }

        const observer = new IntersectionObserver(
            (entries) => {
                const visible = entries.filter((entry) => entry.isIntersecting);

                if (visible.length > 0) {
                    setActiveId(visible[0].target.id);
                }
            },
            { rootMargin: '-80px 0px -70% 0px' },
        );

        for (const heading of headings) {
            const element = document.getElementById(heading.id);

            if (element) {
                observer.observe(element);
            }
        }

        return () => observer.disconnect();
    }, [headings]);

    if (headings.length === 0) {
        return null;
    }

    return (
        <nav className="text-[13px]" aria-label="On this page">
            <p className="text-muted-foreground mb-2 text-[11px] font-semibold tracking-wider uppercase">On this page</p>
            <ul className="space-y-0.5 border-l">
                {headings.map((heading) => (
                    <li key={heading.id}>
                        <a
                            href={`#${heading.id}`}
                            className={cn(
                                '-ml-px block border-l py-1 transition-colors',
                                heading.level === 3 ? 'pl-6' : 'pl-3',
                                activeId === heading.id
                                    ? 'border-docs-accent text-docs-accent font-medium'
                                    : 'text-muted-foreground hover:text-foreground border-transparent',
                            )}
                        >
                            {heading.text}
                        </a>
                    </li>
                ))}
            </ul>
        </nav>
    );
}
