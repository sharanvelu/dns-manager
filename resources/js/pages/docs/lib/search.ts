import type { SearchEntry } from '../types';

export interface SearchResult {
    slug: string;
    title: string;
    description: string;
    /** Set when the match is a specific heading rather than the page itself. */
    heading?: { id: string; text: string };
    score: number;
}

/**
 * Rank docs pages and headings against a query. Field-weighted substring
 * scoring — title > headings > description > body — good enough for a
 * handful of pages, no search dependency needed.
 */
export function searchDocs(index: SearchEntry[], query: string, limit = 10): SearchResult[] {
    const needle = query.trim().toLowerCase();

    if (needle.length < 2) {
        return [];
    }

    const results: SearchResult[] = [];

    for (const entry of index) {
        const title = entry.title.toLowerCase();

        let pageScore = 0;

        if (title.includes(needle)) {
            pageScore += title.startsWith(needle) ? 120 : 100;
        }

        if (entry.description.toLowerCase().includes(needle)) {
            pageScore += 30;
        }

        if (entry.text.toLowerCase().includes(needle)) {
            pageScore += 10;
        }

        if (pageScore > 0) {
            results.push({
                slug: entry.slug,
                title: entry.title,
                description: entry.description,
                score: pageScore,
            });
        }

        for (const heading of entry.headings) {
            if (heading.text.toLowerCase().includes(needle)) {
                results.push({
                    slug: entry.slug,
                    title: entry.title,
                    description: entry.description,
                    heading: { id: heading.id, text: heading.text },
                    score: 60,
                });
            }
        }
    }

    return results.sort((a, b) => b.score - a.score).slice(0, limit);
}
