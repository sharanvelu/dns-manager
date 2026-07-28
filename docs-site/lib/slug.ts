/**
 * GitHub-slugger-style anchor ids (lowercase, punctuation stripped,
 * spaces → dashes). Used by the <H2>/<H3> helpers and the search index so
 * deep links always match the rendered anchors.
 */
export function slugify(text: string): string {
  return text
    .toLowerCase()
    .trim()
    .replace(/[^\p{L}\p{N}\s-]/gu, "")
    .replace(/\s+/g, "-");
}
