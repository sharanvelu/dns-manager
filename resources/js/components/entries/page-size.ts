import type { EntryFilters } from './types';

/** Mirrors App\Support\EntryQuery::PAGE_SIZES / DEFAULT_PAGE_SIZE / PAGE_SIZE_COOKIE. */
export const PAGE_SIZES = [10, 25, 50, 100] as const;
export const DEFAULT_PAGE_SIZE = 25;
const PAGE_SIZE_COOKIE = 'entries_per_page';

/** `per_page` query param for the active page size — omitted at the default to keep URLs clean. */
export function pageSizeParam(filters: EntryFilters): Record<string, string> {
    const size = filters.per_page ?? DEFAULT_PAGE_SIZE;

    return size === DEFAULT_PAGE_SIZE ? {} : { per_page: String(size) };
}

/**
 * Remember the choice for later visits. The server reads this cookie when a
 * request has no `per_page`, so a returning visit renders at the chosen size
 * straight away (no flash of the default, no extra request).
 */
export function rememberPageSize(size: number): void {
    const secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${PAGE_SIZE_COOKIE}=${size}; path=/; max-age=31536000; SameSite=Lax${secure}`;
}
