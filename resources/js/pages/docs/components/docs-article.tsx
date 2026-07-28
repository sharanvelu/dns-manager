import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

import type { Doc } from '../types';

const COPY_ICON =
    '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>';
const CHECK_ICON =
    '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';

/** Pretty labels for the grammar names Phiki resolves to. */
const LANGUAGE_LABELS: Record<string, string> = {
    shellscript: 'sh',
    javascript: 'js',
    typescript: 'ts',
    markdown: 'md',
};

/**
 * Renders the server-generated docs HTML and progressively enhances it:
 * copy buttons + language labels on code frames, and Inertia navigation
 * for internal /docs links (no full page reloads).
 */
export function DocsArticle({ doc }: { doc: Doc }) {
    const ref = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const container = ref.current;

        if (!container) {
            return;
        }

        for (const pre of container.querySelectorAll<HTMLPreElement>('pre.phiki')) {
            if (pre.querySelector('.docs-code-actions')) {
                continue;
            }

            const actions = document.createElement('div');
            actions.className = 'docs-code-actions';

            const language = pre.dataset.language ?? '';

            if (language && language !== 'txt') {
                const label = document.createElement('span');
                label.className = 'docs-code-lang';
                label.textContent = LANGUAGE_LABELS[language] ?? language;
                actions.appendChild(label);
            }

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'docs-code-copy';
            button.setAttribute('aria-label', 'Copy code');
            button.innerHTML = COPY_ICON;
            button.addEventListener('click', () => {
                void navigator.clipboard.writeText(pre.textContent ?? '').then(() => {
                    button.dataset.copied = 'true';
                    button.innerHTML = CHECK_ICON;

                    setTimeout(() => {
                        delete button.dataset.copied;
                        button.innerHTML = COPY_ICON;
                    }, 1500);
                });
            });

            actions.appendChild(button);
            pre.appendChild(actions);
        }

        const onClick = (event: MouseEvent) => {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            const anchor = (event.target as HTMLElement).closest('a');

            if (!anchor || !anchor.getAttribute('href')?.startsWith('/docs')) {
                return;
            }

            event.preventDefault();
            router.visit(anchor.getAttribute('href') ?? '/docs');
        };

        container.addEventListener('click', onClick);

        return () => container.removeEventListener('click', onClick);
    }, [doc.slug, doc.html]);

    return <div ref={ref} className="docs-prose" dangerouslySetInnerHTML={{ __html: doc.html }} />;
}
