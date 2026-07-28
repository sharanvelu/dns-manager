import type { Element, Root } from "hast";
import { toString } from "hast-util-to-string";
import { visit } from "unist-util-visit";

export interface TocItem {
  depth: 2 | 3;
  id: string;
  text: string;
}

export interface ExtractTocOptions {
  /** Called once per document with the collected h2/h3 items. */
  collect: (items: TocItem[]) => void;
}

/**
 * Collect the `h2`/`h3` outline ({depth, id, text}) after `rehype-slug`
 * has assigned ids. Anchor links injected by `rehype-autolink-headings`
 * (class `heading-anchor`) are excluded from the text.
 */
export default function rehypeExtractToc(options: ExtractTocOptions) {
  return (tree: Root) => {
    const items: TocItem[] = [];
    visit(tree, "element", (node) => {
      if (node.tagName !== "h2" && node.tagName !== "h3") return;
      const id = node.properties.id;
      if (typeof id !== "string" || id === "") return;
      const withoutAnchor: Element = {
        ...node,
        children: node.children.filter(
          (child) =>
            !(
              child.type === "element" &&
              child.tagName === "a" &&
              Array.isArray(child.properties.className) &&
              child.properties.className.includes("heading-anchor")
            ),
        ),
      };
      items.push({
        depth: node.tagName === "h2" ? 2 : 3,
        id,
        text: toString(withoutAnchor).trim(),
      });
    });
    options.collect(items);
  };
}
