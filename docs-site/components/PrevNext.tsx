import { ArrowLeft, ArrowRight } from "lucide-react";
import Link from "next/link";
import { adjacentPages, pageUrl } from "@/lib/registry";

/**
 * Previous / next pagination in the registry's canonical order (group
 * order, then page order within the group).
 */
export default function PrevNext({
  group,
  slug = "",
}: {
  group: string;
  slug?: string;
}) {
  const { prev, next } = adjacentPages(group, slug);
  if (!prev && !next) return null;

  return (
    <nav
      aria-label="Pagination"
      className="mt-14 grid gap-3 border-t border-border-soft pt-6 sm:grid-cols-2"
    >
      {prev ? (
        <Link
          href={pageUrl(prev)}
          className="group flex flex-col gap-1 rounded-xl border border-border p-4 transition-all hover:-translate-y-px hover:border-accent/50 hover:bg-background-soft hover:shadow-sm"
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
          href={pageUrl(next)}
          className="group flex flex-col items-end gap-1 rounded-xl border border-border p-4 text-right transition-all hover:-translate-y-px hover:border-accent/50 hover:bg-background-soft hover:shadow-sm"
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
