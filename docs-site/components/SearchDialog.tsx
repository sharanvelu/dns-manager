"use client";

import { CornerDownLeft, FileText, Hash, Search } from "lucide-react";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import type { DocPage } from "@/lib/registry";
import { orderedPages, pageUrl, groupConfig } from "@/lib/registry";
import { slugify } from "@/lib/slug";

/**
 * ⌘K search over the page registry (lib/registry.ts — statically
 * imported, no fetch). Field-weighted scoring: title > headings >
 * description. Heading hits deep-link to their anchors (ids derived with
 * the same slugger the <H2>/<H3> helpers use). Results show their group
 * as context.
 */

interface SearchResult {
  url: string;
  title: string;
  group: string;
  context: string;
  kind: "page" | "heading";
  score: number;
}

function tokenize(query: string): string[] {
  return query.toLowerCase().split(/\s+/).filter(Boolean);
}

function fieldScore(field: string, tokens: string[], weight: number): number {
  const value = field.toLowerCase();
  let score = 0;
  for (const token of tokens) {
    const at = value.indexOf(token);
    if (at === -1) return 0; // all tokens must match the field
    score += weight;
    // Word-boundary bonus.
    if (at === 0 || /[^a-z0-9]/.test(value[at - 1])) score += weight / 2;
  }
  return score;
}

export function searchDocs(pages: DocPage[], query: string): SearchResult[] {
  const tokens = tokenize(query);
  if (tokens.length === 0) return [];

  const results: SearchResult[] = [];
  for (const page of pages) {
    const url = pageUrl(page);
    const group = groupConfig(page.group).label;
    const titleScore = fieldScore(page.title, tokens, 40);
    const groupScore = fieldScore(group, tokens, 10);
    const descriptionScore = fieldScore(page.description, tokens, 12);

    const pageScore = titleScore + groupScore + descriptionScore;
    if (pageScore > 0) {
      results.push({
        url,
        title: page.title,
        group,
        context: page.description,
        kind: "page",
        score: pageScore,
      });
    }

    let headingHits = 0;
    for (const heading of page.headings) {
      if (headingHits >= 3) break;
      const headingScore = fieldScore(heading, tokens, 20);
      if (headingScore === 0) continue;
      headingHits += 1;
      results.push({
        url: `${url}#${slugify(heading)}`,
        title: heading,
        group,
        context: page.title,
        kind: "heading",
        score: headingScore + titleScore / 4,
      });
    }
  }

  return results.sort((a, b) => b.score - a.score).slice(0, 12);
}

export default function SearchDialog() {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [active, setActive] = useState(0);
  const [isMac, setIsMac] = useState(true);
  const inputRef = useRef<HTMLInputElement>(null);
  const listRef = useRef<HTMLUListElement>(null);
  const pages = useMemo(() => orderedPages(), []);

  const openDialog = useCallback(() => setOpen(true), []);

  const closeDialog = useCallback(() => {
    setOpen(false);
    setQuery("");
    setActive(0);
  }, []);

  useEffect(() => {
    setIsMac(/mac|iphone|ipad/i.test(navigator.platform || navigator.userAgent));
  }, []);

  // Global shortcuts: ⌘K / Ctrl+K toggle, `/` opens.
  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "k") {
        event.preventDefault();
        if (open) closeDialog();
        else openDialog();
        return;
      }
      if (event.key === "/" && !open) {
        const target = event.target as HTMLElement | null;
        if (
          target &&
          (target.tagName === "INPUT" ||
            target.tagName === "TEXTAREA" ||
            target.isContentEditable)
        ) {
          return;
        }
        event.preventDefault();
        openDialog();
      }
    }
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [open, openDialog, closeDialog]);

  // Focus input + scroll lock while open.
  useEffect(() => {
    if (!open) return;
    inputRef.current?.focus();
    const previous = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => {
      document.body.style.overflow = previous;
    };
  }, [open]);

  const results = useMemo(
    () => (query.trim() !== "" ? searchDocs(pages, query) : []),
    [pages, query],
  );

  useEffect(() => setActive(0), [query]);

  useEffect(() => {
    listRef.current
      ?.querySelector('[data-active="true"]')
      ?.scrollIntoView({ block: "nearest" });
  }, [active]);

  function onDialogKeyDown(event: React.KeyboardEvent) {
    if (event.key === "Escape") {
      event.preventDefault();
      closeDialog();
    } else if (event.key === "ArrowDown") {
      event.preventDefault();
      setActive((current) => Math.min(current + 1, results.length - 1));
    } else if (event.key === "ArrowUp") {
      event.preventDefault();
      setActive((current) => Math.max(current - 1, 0));
    } else if (event.key === "Enter" && results[active]) {
      event.preventDefault();
      closeDialog();
      window.location.assign(results[active].url);
    }
  }

  const shortcutLabel = isMac ? "⌘K" : "Ctrl K";

  return (
    <>
      <button
        type="button"
        onClick={openDialog}
        aria-label="Search documentation"
        className="inline-flex size-8 items-center justify-center rounded-md text-muted transition-colors hover:bg-background-soft hover:text-foreground sm:hidden"
      >
        <Search size={16} strokeWidth={1.5} />
      </button>
      <button
        type="button"
        onClick={openDialog}
        className="hidden h-8 items-center gap-2 rounded-md border border-border bg-background-soft px-2.5 text-[13px] text-faint transition-colors hover:border-faint/50 hover:text-muted sm:inline-flex"
      >
        <Search size={14} strokeWidth={1.5} />
        <span className="pr-4">Search docs…</span>
        <kbd className="ml-auto rounded border border-border bg-surface px-1.5 py-px font-sans text-[10px] font-medium text-faint">
          {shortcutLabel}
        </kbd>
      </button>

      {open && (
        <div
          className="fixed inset-0 z-50 flex items-start justify-center px-4 pt-[12vh]"
          onKeyDown={onDialogKeyDown}
        >
          <button
            type="button"
            aria-label="Close search"
            onClick={closeDialog}
            className="absolute inset-0 cursor-default bg-black/40 backdrop-blur-xs"
            tabIndex={-1}
          />
          <div
            role="dialog"
            aria-modal="true"
            aria-label="Search documentation"
            className="relative w-full max-w-xl overflow-hidden rounded-xl border border-border bg-surface shadow-2xl"
          >
            <div className="flex items-center gap-2.5 border-b border-border px-4">
              <Search size={16} strokeWidth={1.5} className="shrink-0 text-faint" />
              <input
                ref={inputRef}
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                placeholder="Search documentation…"
                aria-label="Search documentation"
                className="h-12 w-full bg-transparent text-sm outline-none placeholder:text-faint"
              />
              <kbd className="shrink-0 rounded border border-border bg-background-soft px-1.5 py-0.5 text-[10px] font-medium text-faint">
                Esc
              </kbd>
            </div>

            <div className="max-h-[50vh] overflow-y-auto overscroll-contain p-2">
              {query.trim() === "" ? (
                <p className="px-3 py-8 text-center text-sm text-faint">
                  Search titles, headings and descriptions…
                </p>
              ) : results.length === 0 ? (
                <p className="px-3 py-8 text-center text-sm text-faint">
                  No results for <span className="font-medium text-muted">“{query}”</span>
                </p>
              ) : (
                <ul ref={listRef}>
                  {results.map((result, i) => (
                    <li key={result.url}>
                      <a
                        href={result.url}
                        data-active={i === active}
                        onMouseEnter={() => setActive(i)}
                        onClick={closeDialog}
                        className={[
                          "flex items-start gap-3 rounded-lg px-3 py-2.5",
                          i === active ? "bg-accent-soft" : "",
                        ].join(" ")}
                      >
                        <span
                          className={[
                            "mt-0.5 shrink-0",
                            i === active ? "text-accent" : "text-faint",
                          ].join(" ")}
                        >
                          {result.kind === "heading" ? (
                            <Hash size={15} strokeWidth={1.5} />
                          ) : (
                            <FileText size={15} strokeWidth={1.5} />
                          )}
                        </span>
                        <span className="min-w-0 flex-1">
                          <span className="flex items-baseline gap-2">
                            <span
                              className={[
                                "truncate text-sm font-medium",
                                i === active ? "text-accent" : "text-foreground",
                              ].join(" ")}
                            >
                              {result.title}
                            </span>
                            <span className="shrink-0 rounded-full border border-border bg-background-soft px-1.5 py-px text-[10px] font-medium text-faint">
                              {result.group}
                            </span>
                          </span>
                          {result.context && (
                            <span className="mt-0.5 block truncate text-xs text-muted">
                              {result.context}
                            </span>
                          )}
                        </span>
                        {i === active && (
                          <CornerDownLeft
                            size={14}
                            strokeWidth={1.5}
                            className="mt-1 shrink-0 text-faint"
                          />
                        )}
                      </a>
                    </li>
                  ))}
                </ul>
              )}
            </div>

            <div className="flex items-center gap-3 border-t border-border px-4 py-2 text-[11px] text-faint">
              <span>
                <kbd className="rounded border border-border bg-background-soft px-1 py-px">↑↓</kbd>{" "}
                navigate
              </span>
              <span>
                <kbd className="rounded border border-border bg-background-soft px-1 py-px">↵</kbd>{" "}
                open
              </span>
              <span>
                <kbd className="rounded border border-border bg-background-soft px-1 py-px">esc</kbd>{" "}
                close
              </span>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
