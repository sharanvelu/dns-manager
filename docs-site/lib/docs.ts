import fs from "node:fs";
import path from "node:path";
import matter from "gray-matter";
import { remark } from "remark";
import remarkGfm from "remark-gfm";
import remarkHtml from "remark-html";

/**
 * Single source of truth shared with the Laravel app's in-app /docs endpoint:
 * ../docs/content/*.md with `title`, `nav_order`, `description` frontmatter.
 *
 * The build must tolerate any set of files matching that contract, including
 * an empty or missing directory (content is authored independently).
 */
const CONTENT_DIR = path.join(process.cwd(), "..", "docs", "content");

export interface DocMeta {
  slug: string;
  title: string;
  navOrder: number;
  description: string;
}

export interface Doc extends DocMeta {
  /** Raw markdown body (frontmatter stripped). */
  markdown: string;
}

export interface FeatureCard {
  title: string;
  html: string;
}

export function getVersion(): string {
  const override = process.env.NEXT_PUBLIC_APP_VERSION;
  if (override && override.trim() !== "") {
    return override.trim();
  }
  try {
    return fs
      .readFileSync(path.join(process.cwd(), "..", "VERSION"), "utf8")
      .trim();
  } catch {
    return "0.0.0";
  }
}

function slugToTitle(slug: string): string {
  const words = slug.replace(/[-_]+/g, " ").trim();
  return words.charAt(0).toUpperCase() + words.slice(1);
}

export function getAllDocs(): Doc[] {
  let files: string[];
  try {
    files = fs.readdirSync(CONTENT_DIR);
  } catch {
    return [];
  }

  const docs: Doc[] = [];
  for (const file of files) {
    if (!file.endsWith(".md")) continue;
    const slug = file.replace(/\.md$/, "");
    let raw: string;
    try {
      raw = fs.readFileSync(path.join(CONTENT_DIR, file), "utf8");
    } catch {
      continue;
    }
    const { data, content } = matter(raw);
    docs.push({
      slug,
      title: typeof data.title === "string" && data.title.trim() !== "" ? data.title : slugToTitle(slug),
      navOrder: typeof data.nav_order === "number" ? data.nav_order : Number(data.nav_order) || 999,
      description: typeof data.description === "string" ? data.description : "",
      markdown: content,
    });
  }

  docs.sort((a, b) => a.navOrder - b.navOrder || a.title.localeCompare(b.title));
  return docs;
}

export function getDoc(slug: string): Doc | undefined {
  return getAllDocs().find((d) => d.slug === slug);
}

export function getNav(): DocMeta[] {
  return getAllDocs().map(({ slug, title, navOrder, description }) => ({
    slug,
    title,
    navOrder,
    description,
  }));
}

/**
 * Rewrite relative slug links (e.g. href="providers" or "providers#zones",
 * optionally with a .md extension) to root-relative trailing-slash URLs that
 * the static export serves. External, absolute, anchor and mailto links are
 * left untouched. `index` points at the landing page.
 */
function rewriteRelativeLinks(html: string): string {
  return html.replace(/href="([^"]+)"/g, (full, href: string) => {
    if (/^(?:[a-zA-Z][a-zA-Z0-9+.-]*:|\/|#)/.test(href)) {
      return full; // http(s):, mailto:, absolute path, in-page anchor
    }
    const [target, hash] = href.split("#", 2);
    const slug = target.replace(/\.md$/, "").replace(/\/+$/, "");
    const base = slug === "" || slug === "index" ? "/" : `/${slug}/`;
    return `href="${base}${hash ? `#${hash}` : ""}"`;
  });
}

export async function markdownToHtml(markdown: string): Promise<string> {
  const processed = await remark()
    .use(remarkGfm)
    .use(remarkHtml, { sanitize: false })
    .process(markdown);
  return rewriteRelativeLinks(String(processed));
}

/** Split a markdown document into H2 sections, keyed by lowercased heading text. */
function splitH2Sections(markdown: string): Map<string, string> {
  const sections = new Map<string, string>();
  const regex = /^##\s+(.+?)\s*$/gm;
  const matches = [...markdown.matchAll(regex)];
  for (let i = 0; i < matches.length; i++) {
    const start = matches[i].index! + matches[i][0].length;
    const end = i + 1 < matches.length ? matches[i + 1].index! : markdown.length;
    sections.set(matches[i][1].toLowerCase(), markdown.slice(start, end).trim());
  }
  return sections;
}

export function getSection(markdown: string, heading: string): string | undefined {
  return splitH2Sections(markdown).get(heading.toLowerCase());
}

/**
 * Parse the "## Features" section of index.md into cards.
 * Tolerates two authoring styles:
 *   1. `### Feature title` subheadings, each followed by body text.
 *   2. A bullet list where each item leads with `**Feature title**`.
 * Anything unparseable falls back to a single untitled card so content
 * never disappears.
 */
export async function getFeatureCards(markdown: string): Promise<FeatureCard[]> {
  const section = getSection(markdown, "features");
  if (!section) return [];

  const cards: FeatureCard[] = [];

  const h3 = /^###\s+(.+?)\s*$/gm;
  const headings = [...section.matchAll(h3)];
  if (headings.length > 0) {
    for (let i = 0; i < headings.length; i++) {
      const start = headings[i].index! + headings[i][0].length;
      const end = i + 1 < headings.length ? headings[i + 1].index! : section.length;
      cards.push({
        title: headings[i][1],
        html: await markdownToHtml(section.slice(start, end).trim()),
      });
    }
    return cards;
  }

  const items = section
    .split(/^[-*]\s+/m)
    .map((s) => s.trim())
    .filter(Boolean);
  const looksLikeList = /^[-*]\s+/m.test(section);
  if (looksLikeList && items.length > 0) {
    for (const item of items) {
      const lead = item.match(/^\*\*(.+?)\*\*[\s:—–-]*([\s\S]*)$/);
      if (lead) {
        cards.push({ title: lead[1], html: await markdownToHtml(lead[2].trim()) });
      } else {
        cards.push({ title: "", html: await markdownToHtml(item) });
      }
    }
    return cards;
  }

  return [{ title: "", html: await markdownToHtml(section) }];
}
