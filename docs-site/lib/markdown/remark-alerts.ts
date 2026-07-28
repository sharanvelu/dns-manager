import type { Blockquote, Paragraph, Root } from "mdast";
import { visit } from "unist-util-visit";

/**
 * GitHub-style alerts / callouts:
 *
 *     > [!NOTE]
 *     > Body text…
 *
 * Blockquotes whose first line is `[!NOTE|TIP|IMPORTANT|WARNING|CAUTION]`
 * are converted into `div.callout.callout-{type}` with a leading
 * `p.callout-title` row. All colors and icons come from the prose layer
 * (`app/prose.css`), which in turn reads only palette tokens
 * (`app/palette.css`). Unknown `[!FOO]` markers stay ordinary blockquotes.
 */

const ALERT_TYPES: Record<string, string> = {
  NOTE: "Note",
  TIP: "Tip",
  IMPORTANT: "Important",
  WARNING: "Warning",
  CAUTION: "Caution",
};

const MARKER = /^\[!([A-Z]+)\]\s*(?:\r?\n|$)/;

export default function remarkAlerts() {
  return (tree: Root) => {
    visit(tree, "blockquote", (node: Blockquote) => {
      const first = node.children[0];
      if (!first || first.type !== "paragraph") return;
      const lead = first.children[0];
      if (!lead || lead.type !== "text") return;

      const match = lead.value.match(MARKER);
      if (!match) return;
      const label = ALERT_TYPES[match[1]];
      if (!label) return; // unknown marker — leave the blockquote alone

      // Strip the marker from the first paragraph.
      const rest = lead.value.slice(match[0].length);
      if (rest === "") {
        first.children.shift();
        if (first.children.length === 0) {
          node.children.shift();
        }
      } else {
        lead.value = rest;
      }

      const type = match[1].toLowerCase();
      node.data = {
        ...node.data,
        hName: "div",
        hProperties: { className: ["callout", `callout-${type}`] },
      };

      const title: Paragraph = {
        type: "paragraph",
        children: [{ type: "text", value: label }],
        data: {
          hName: "p",
          hProperties: { className: ["callout-title"] },
        },
      };
      node.children.unshift(title);
    });
  };
}
