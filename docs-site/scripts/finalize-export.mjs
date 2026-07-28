/**
 * Restructure the static export to the deployment layout ("export layout
 * contract", see the redesign spec + next.config.ts).
 *
 * `next build` (output: "export", assetPrefix: "/docs") writes the asset
 * files to out/_next/ while every emitted URL already points at
 * /docs/_next/. This script moves the files to match:
 *
 *   out/                 ← Vercel serves this at the domain root:
 *     index.html           landing page at /
 *     docs/                complete documentation at /docs/
 *       _next/             ALL runtime assets (moved here)
 *       images/            static images (physically public/docs/images/)
 *       404.html           copy for the /docs-only container deployment
 *     404.html
 *
 * The Docker image copies ONLY out/docs/ and serves it at /docs — pages,
 * assets and images are all self-contained under that prefix.
 */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const out = path.join(root, "out");
const nextDir = path.join(out, "_next");
const docsDir = path.join(out, "docs");

if (!fs.existsSync(out)) {
  console.error("finalize-export: out/ does not exist — run `next build` first.");
  process.exit(1);
}
if (!fs.existsSync(docsDir)) {
  console.error("finalize-export: out/docs/ missing — docs routes did not build.");
  process.exit(1);
}

if (fs.existsSync(nextDir)) {
  const target = path.join(docsDir, "_next");
  fs.rmSync(target, { recursive: true, force: true });
  fs.renameSync(nextDir, target);
  console.log("finalize-export: moved out/_next -> out/docs/_next");
} else if (!fs.existsSync(path.join(docsDir, "_next"))) {
  console.error("finalize-export: no _next assets found in out/ or out/docs/.");
  process.exit(1);
}

// The container serves out/docs/ alone — give it a 404 page too.
const rootNotFound = path.join(out, "404.html");
if (fs.existsSync(rootNotFound)) {
  fs.copyFileSync(rootNotFound, path.join(docsDir, "404.html"));
}

console.log("finalize-export: done.");
