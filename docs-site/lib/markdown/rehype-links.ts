import type { Root } from "hast";
import { visit } from "unist-util-visit";

/**
 * Rewrite relative slug links authored in `docs/content` (e.g.
 * `href="providers"`, `"providers#zones"`, optionally with a `.md`
 * extension) to the root-relative trailing-slash URLs the static export
 * serves (`/providers/#zones`). External, absolute, in-page anchor and
 * scheme (mailto: etc.) links are left untouched. `index` points at the
 * landing page.
 *
 * AST replacement for the old regex-over-HTML rewriter.
 */
export default function rehypeLinks() {
  return (tree: Root) => {
    visit(tree, "element", (node) => {
      if (node.tagName !== "a") return;
      const href = node.properties.href;
      if (typeof href !== "string" || href === "") return;
      if (/^(?:[a-zA-Z][a-zA-Z0-9+.-]*:|\/|#)/.test(href)) {
        return; // http(s):, mailto:, absolute path, in-page anchor
      }
      const [target, hash] = href.split("#", 2);
      const slug = target.replace(/\.md$/, "").replace(/\/+$/, "");
      const base = slug === "" || slug === "index" ? "/" : `/${slug}/`;
      node.properties.href = `${base}${hash ? `#${hash}` : ""}`;
    });
  };
}
