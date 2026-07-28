import fs from "node:fs";
import path from "node:path";

/**
 * App version from the repo-root VERSION file (read at build time —
 * server components only). NEXT_PUBLIC_APP_VERSION overrides for builds
 * outside the repo checkout.
 */
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
