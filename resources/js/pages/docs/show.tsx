import { Head } from '@inertiajs/react';
import { useState } from 'react';

import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';

import { DocsArticle } from './components/docs-article';
import { DocsHeader } from './components/docs-header';
import { DocsPager } from './components/docs-pager';
import { DocsSearch } from './components/docs-search';
import { DocsSidebarNav } from './components/docs-sidebar';
import { DocsToc } from './components/docs-toc';
import type { DocsPageProps } from './types';

export default function DocsShow({ doc, pages, current, version, docsSiteUrl, searchIndex }: DocsPageProps) {
    const [searchOpen, setSearchOpen] = useState(false);
    const [navOpen, setNavOpen] = useState(false);

    return (
        <div className="bg-background text-foreground min-h-screen">
            <Head title={`${doc.title} — Docs`}>
                <meta name="description" content={doc.description} />
            </Head>

            <DocsHeader version={version} onOpenSearch={() => setSearchOpen(true)} onOpenNav={() => setNavOpen(true)} />

            <div className="mx-auto flex w-full max-w-screen-2xl px-4 sm:px-6">
                <aside className="sticky top-14 hidden h-[calc(100vh-3.5rem)] w-60 shrink-0 overflow-y-auto border-r py-6 pr-4 lg:block">
                    <DocsSidebarNav pages={pages} current={current} docsSiteUrl={docsSiteUrl} />
                </aside>

                <main className="min-w-0 flex-1 py-8 lg:px-10">
                    <article className="mx-auto max-w-3xl">
                        {doc.description && doc.slug !== 'index' && (
                            <p className="text-muted-foreground mb-6 border-b pb-6 text-[15px]">{doc.description}</p>
                        )}
                        <DocsArticle doc={doc} />
                        <DocsPager pages={pages} current={current} />
                    </article>
                </main>

                <aside className="sticky top-14 hidden h-[calc(100vh-3.5rem)] w-56 shrink-0 overflow-y-auto py-8 pl-4 xl:block">
                    <DocsToc headings={doc.headings} />
                </aside>
            </div>

            <DocsSearch index={searchIndex} open={searchOpen} onOpenChange={setSearchOpen} />

            <Sheet open={navOpen} onOpenChange={setNavOpen}>
                <SheetContent side="left" className="w-72 p-4 pt-10">
                    <SheetHeader className="sr-only">
                        <SheetTitle>Documentation navigation</SheetTitle>
                        <SheetDescription>Pages in this documentation.</SheetDescription>
                    </SheetHeader>
                    <DocsSidebarNav pages={pages} current={current} docsSiteUrl={docsSiteUrl} onNavigate={() => setNavOpen(false)} />
                </SheetContent>
            </Sheet>
        </div>
    );
}
