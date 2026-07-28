import fs from "node:fs";
import path from "node:path";

/**
 * Theme-aware screenshot pair in a rounded, captioned frame (server
 * component — checks the filesystem at build time).
 *
 *   <Screenshot src="dashboard" alt="The dashboard"
 *     caption="Stat tiles, zone cards and provider health at a glance." />
 *
 * Renders /docs/images/screenshots/{src}-light.png (light theme) and
 * {src}-dark.png (dark theme); `.svg` is the fallback extension for
 * stylized mockups. Files live physically in public/docs/images/… so the
 * URLs work when out/docs/ is mounted alone at /docs (Docker) as well as
 * from out/ at a domain root (Vercel).
 *
 * NEVER fails the build on missing files (screenshots are produced
 * concurrently): one file → shown in both themes; none → placeholder.
 */

const SCREENSHOT_FS_DIR = path.join(process.cwd(), "public", "docs", "images", "screenshots");
const SCREENSHOT_URL_DIR = "/docs/images/screenshots";

function findScreenshot(name: string, theme: "light" | "dark"): string | null {
  for (const ext of ["png", "svg"]) {
    const file = `${name}-${theme}.${ext}`;
    try {
      if (fs.existsSync(path.join(SCREENSHOT_FS_DIR, file))) {
        return `${SCREENSHOT_URL_DIR}/${file}`;
      }
    } catch {
      /* unreadable public dir — treat as missing */
    }
  }
  return null;
}

export default function Screenshot({
  src,
  alt,
  caption,
}: {
  src: string;
  alt: string;
  caption?: string;
}) {
  const light = findScreenshot(src, "light");
  const dark = findScreenshot(src, "dark");

  return (
    <figure className="screenshot" data-screenshot={src}>
      <div className="screenshot-frame">
        {light && dark ? (
          <>
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src={light} alt={alt} loading="lazy" className="screenshot-light" />
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src={dark} alt={alt} loading="lazy" className="screenshot-dark" aria-hidden="true" />
          </>
        ) : light || dark ? (
          // Only one theme captured (yet) — show it in both themes.
          // eslint-disable-next-line @next/next/no-img-element
          <img src={(light ?? dark) as string} alt={alt} loading="lazy" />
        ) : (
          // Nothing captured yet — placeholder keeps layout + build intact.
          <div className="screenshot-placeholder">{alt || "Screenshot coming soon"}</div>
        )}
      </div>
      {caption && <figcaption className="screenshot-caption">{caption}</figcaption>}
    </figure>
  );
}
