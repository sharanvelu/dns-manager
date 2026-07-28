import type { ReactNode } from "react";
import { slugify } from "@/lib/slug";

/**
 * Anchored headings for docs pages. Ids are derived from the text with
 * the shared slugger (lib/slug.ts — GitHub style), overridable via `id`.
 * The hover `#` anchor and scroll-margin come from app/prose.css; the
 * client TOC (components/Toc.tsx) picks these up from the DOM.
 *
 *   <H2>Attach a provider</H2>        → <h2 id="attach-a-provider">
 *   <H3 id="custom">Fine print</H3>   → <h3 id="custom">
 */

function textOf(node: ReactNode): string {
  if (node == null || typeof node === "boolean") return "";
  if (typeof node === "string" || typeof node === "number") return String(node);
  if (Array.isArray(node)) return node.map(textOf).join("");
  if (typeof node === "object" && "props" in node) {
    return textOf((node.props as { children?: ReactNode }).children);
  }
  return "";
}

function Anchor({ id }: { id: string }) {
  return (
    <a
      href={`#${id}`}
      className="heading-anchor"
      aria-hidden="true"
      tabIndex={-1}
    >
      #
    </a>
  );
}

export function H2({ id, children }: { id?: string; children: ReactNode }) {
  const anchor = id ?? slugify(textOf(children));
  return (
    <h2 id={anchor}>
      {children}
      <Anchor id={anchor} />
    </h2>
  );
}

export function H3({ id, children }: { id?: string; children: ReactNode }) {
  const anchor = id ?? slugify(textOf(children));
  return (
    <h3 id={anchor}>
      {children}
      <Anchor id={anchor} />
    </h3>
  );
}
