import { codeToHtml } from "shiki";

/**
 * Syntax-highlighted code frame (async server component — Shiki runs at
 * build time, emitting dual github-light/dark colors via CSS
 * `light-dark()`, resolved by the color-scheme on :root/.dark).
 *
 *   <CodeBlock lang="sh">{`composer run dev`}</CodeBlock>
 *   <CodeBlock lang="yaml" title="values.yaml">{`replicas: 2`}</CodeBlock>
 *
 * Code is a plain string child (template literal). Common leading
 * indentation is stripped so the JSX can be indented naturally. The copy
 * button is inert markup wired up by components/CodeCopy.tsx (mounted by
 * DocArticle) via event delegation. Unknown languages fall back to plain
 * text instead of failing the build.
 */

function dedent(code: string): string {
  const lines = code.replace(/^\n+/, "").trimEnd().split("\n");
  let indent = Infinity;
  for (const line of lines) {
    if (line.trim() === "") continue;
    indent = Math.min(indent, line.match(/^[ \t]*/)![0].length);
  }
  if (!Number.isFinite(indent) || indent === 0) return lines.join("\n");
  return lines.map((line) => line.slice(indent)).join("\n");
}

const COPY_ICON = (
  <svg
    xmlns="http://www.w3.org/2000/svg"
    viewBox="0 0 24 24"
    width="14"
    height="14"
    fill="none"
    stroke="currentColor"
    strokeWidth="1.5"
    strokeLinecap="round"
    strokeLinejoin="round"
    className="code-copy-icon"
    aria-hidden="true"
  >
    <rect width="14" height="14" x="8" y="8" rx="2" ry="2" />
    <path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2" />
  </svg>
);

const CHECK_ICON = (
  <svg
    xmlns="http://www.w3.org/2000/svg"
    viewBox="0 0 24 24"
    width="14"
    height="14"
    fill="none"
    stroke="currentColor"
    strokeWidth="1.5"
    strokeLinecap="round"
    strokeLinejoin="round"
    className="code-check-icon"
    aria-hidden="true"
  >
    <path d="M20 6 9 17l-5-5" />
  </svg>
);

export default async function CodeBlock({
  lang = "text",
  title,
  children,
}: {
  lang?: string;
  title?: string;
  children: string;
}) {
  const code = dedent(children);

  let html: string;
  try {
    html = await codeToHtml(code, {
      lang,
      themes: {
        light: "github-light-default",
        dark: "github-dark-default",
      },
      defaultColor: "light-dark()",
    });
  } catch {
    // Unknown language — highlight as plain text rather than failing.
    html = await codeToHtml(code, {
      lang: "text",
      themes: {
        light: "github-light-default",
        dark: "github-dark-default",
      },
      defaultColor: "light-dark()",
    });
  }

  return (
    <figure className="code-frame" data-language={lang}>
      <figcaption className="code-frame-header">
        <span className="code-frame-lang">{title ?? lang}</span>
        <button type="button" className="code-copy" aria-label="Copy code" title="Copy code">
          {COPY_ICON}
          {CHECK_ICON}
        </button>
      </figcaption>
      <div dangerouslySetInnerHTML={{ __html: html }} />
    </figure>
  );
}
