import { ArrowLeft, ArrowRight } from "lucide-react";
import Link from "next/link";
import type { DocMeta } from "@/lib/docs";
import { docHref } from "@/lib/site";

/**
 * Previous / next pagination from the shared nav_order (nav arrives
 * already sorted by getNav()).
 */
export default function PrevNext({
  nav,
  currentSlug,
}: {
  nav: DocMeta[];
  currentSlug: string;
}) {
  const index = nav.findIndex((doc) => doc.slug === currentSlug);
  if (index === -1) return null;
  const prev = index > 0 ? nav[index - 1] : undefined;
  const next = index < nav.length - 1 ? nav[index + 1] : undefined;
  if (!prev && !next) return null;

  return (
    <nav
      aria-label="Pagination"
      className="mt-12 grid gap-3 border-t border-border-soft pt-6 sm:grid-cols-2"
    >
      {prev ? (
        <Link
          href={docHref(prev.slug)}
          className="group flex flex-col gap-1 rounded-xl border border-border p-4 transition-colors hover:border-accent/50 hover:bg-background-soft"
        >
          <span className="flex items-center gap-1 text-xs text-faint">
            <ArrowLeft size={13} strokeWidth={1.5} />
            Previous
          </span>
          <span className="text-sm font-medium transition-colors group-hover:text-accent">
            {prev.title}
          </span>
        </Link>
      ) : (
        <span aria-hidden="true" />
      )}
      {next && (
        <Link
          href={docHref(next.slug)}
          className="group flex flex-col items-end gap-1 rounded-xl border border-border p-4 text-right transition-colors hover:border-accent/50 hover:bg-background-soft"
        >
          <span className="flex items-center gap-1 text-xs text-faint">
            Next
            <ArrowRight size={13} strokeWidth={1.5} />
          </span>
          <span className="text-sm font-medium transition-colors group-hover:text-accent">
            {next.title}
          </span>
        </Link>
      )}
    </nav>
  );
}
