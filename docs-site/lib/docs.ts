import fs from "node:fs";
import path from "node:path";
import matter from "gray-matter";
import rehypeAutolinkHeadings from "rehype-autolink-headings";
import rehypeSlug from "rehype-slug";
import rehypeStringify from "rehype-stringify";
import remarkGfm from "remark-gfm";
import remarkParse from "remark-parse";
import remarkRehype from "remark-rehype";
import rehypeShiki from "@shikijs/rehype";
import { unified } from "unified";
import remarkAlerts from "./markdown/remark-alerts";
import rehypeCodeFrame from "./markdown/rehype-code-frame";
import rehypeExtractToc, { type TocItem } from "./markdown/rehype-extract-toc";
import rehypeLinks from "./markdown/rehype-links";

export type { TocItem } from "./markdown/rehype-extract-toc";

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

export interface RenderedDoc {
  html: string;
  toc: TocItem[];
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
 * Strip a leading `# Heading` so pages can render their own title block
 * (h1 + description lede) with layout control. No-op when the document
 * does not start with an H1.
 */
export function stripLeadingH1(markdown: string): string {
  return markdown.replace(/^\s*#[ \t]+.*(?:\r?\n|$)/, "");
}

/**
 * Markdown → HTML pipeline shared by docs pages, the landing page and the
 * search index:
 *
 * remark-parse → remark-gfm → remark-alerts (GitHub-style callouts) →
 * remark-rehype → rehype-slug → rehype-autolink-headings (hover `#`) →
 * rehype-extract-toc (h2/h3) → rehype-links (relative slug links →
 * /{slug}/#hash) → @shikijs/rehype (dual github-light/dark themes via
 * CSS `light-dark()`) → rehype-code-frame (figure + language label +
 * copy button) → rehype-stringify.
 *
 * Shiki's highlighter is a process-wide singleton, so building a
 * processor per call is cheap.
 */
export async function renderDoc(markdown: string): Promise<RenderedDoc> {
  let toc: TocItem[] = [];
  const file = await unified()
    .use(remarkParse)
    .use(remarkGfm)
    .use(remarkAlerts)
    .use(remarkRehype)
    .use(rehypeSlug)
    .use(rehypeAutolinkHeadings, {
      behavior: "append",
      test: ["h2", "h3", "h4"],
      properties: {
        className: ["heading-anchor"],
        ariaHidden: "true",
        tabIndex: -1,
      },
      content: { type: "text", value: "#" },
    })
    .use(rehypeExtractToc, {
      collect: (items) => {
        toc = items;
      },
    })
    .use(rehypeLinks)
    .use(rehypeShiki, {
      themes: {
        light: "github-light-default",
        dark: "github-dark-default",
      },
      defaultColor: "light-dark()",
      addLanguageClass: true,
      defaultLanguage: "text",
      fallbackLanguage: "text",
    })
    .use(rehypeCodeFrame)
    .use(rehypeStringify)
    .process(markdown);
  return { html: String(file), toc };
}

export async function markdownToHtml(markdown: string): Promise<string> {
  return (await renderDoc(markdown)).html;
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
