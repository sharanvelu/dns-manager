import type { Element, ElementContent, Root } from "hast";
import { SKIP, visit } from "unist-util-visit";

/**
 * Wrap every fenced code block (post-Shiki `pre`) in a
 * `figure.code-frame` with a header row: language label + copy button.
 * The copy button is inert HTML here; `components/CodeCopy.tsx` wires it
 * up client-side via event delegation and reads the code text from the
 * sibling `pre` at click time.
 */

function svgIcon(className: string, children: ElementContent[]): Element {
  return {
    type: "element",
    tagName: "svg",
    properties: {
      xmlns: "http://www.w3.org/2000/svg",
      viewBox: "0 0 24 24",
      width: "14",
      height: "14",
      fill: "none",
      stroke: "currentColor",
      strokeWidth: "1.5",
      strokeLinecap: "round",
      strokeLinejoin: "round",
      className: [className],
      ariaHidden: "true",
    },
    children,
  };
}

function path(d: string): Element {
  return { type: "element", tagName: "path", properties: { d }, children: [] };
}

function copyIcon(): Element {
  return svgIcon("code-copy-icon", [
    {
      type: "element",
      tagName: "rect",
      properties: { width: "14", height: "14", x: "8", y: "8", rx: "2", ry: "2" },
      children: [],
    },
    path("M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"),
  ]);
}

function checkIcon(): Element {
  return svgIcon("code-check-icon", [path("M20 6 9 17l-5-5")]);
}

/** Language of the block, from the `language-*` class Shiki keeps on `code`. */
function detectLanguage(pre: Element): string {
  for (const child of pre.children) {
    if (child.type !== "element" || child.tagName !== "code") continue;
    // Shiki sets `class` as a string; other tools use a `className` array.
    const raw = child.properties.className ?? child.properties.class;
    const classes = Array.isArray(raw)
      ? raw
      : typeof raw === "string"
        ? raw.split(/\s+/)
        : [];
    for (const cls of classes) {
      if (typeof cls === "string" && cls.startsWith("language-")) {
        return cls.slice("language-".length);
      }
    }
  }
  return "text";
}

export default function rehypeCodeFrame() {
  return (tree: Root) => {
    visit(tree, "element", (node, index, parent) => {
      if (node.tagName !== "pre" || !parent || typeof index !== "number") return;
      if (parent.type === "element" && parent.tagName === "figure") return;

      const language = detectLanguage(node);
      const figure: Element = {
        type: "element",
        tagName: "figure",
        properties: { className: ["code-frame"], dataLanguage: language },
        children: [
          {
            type: "element",
            tagName: "figcaption",
            properties: { className: ["code-frame-header"] },
            children: [
              {
                type: "element",
                tagName: "span",
                properties: { className: ["code-frame-lang"] },
                children: [{ type: "text", value: language }],
              },
              {
                type: "element",
                tagName: "button",
                properties: {
                  type: "button",
                  className: ["code-copy"],
                  ariaLabel: "Copy code",
                  title: "Copy code",
                },
                children: [copyIcon(), checkIcon()],
              },
            ],
          },
          node,
        ],
      };

      parent.children[index] = figure;
      return SKIP;
    });
  };
}
