import type { NextConfig } from "next";

const isProd = process.env.NODE_ENV === "production";

const nextConfig: NextConfig = {
  // Pure static export — deployed as flat files (nginx in the app image,
  // Vercel for the hosted site).
  output: "export",
  /**
   * URL layout: `/` is the product landing page (Vercel only), `/docs/…`
   * the documentation (real static routes under app/docs/). There is NO
   * basePath — instead every RUNTIME asset lives under the /docs/ URL
   * space so the Docker image can serve out/docs/ alone at /docs:
   *
   *  - assetPrefix rewrites all /_next/… references to /docs/_next/…;
   *    scripts/finalize-export.mjs physically moves out/_next there.
   *  - images live physically in public/docs/images/… (URLs /docs/images/…).
   *
   * Dev keeps the default prefix — `next dev` serves /_next itself.
   */
  assetPrefix: isProd ? "/docs" : undefined,
  // Every page becomes /{route}/index.html so links resolve cleanly on
  // any static host.
  trailingSlash: true,
  images: {
    unoptimized: true,
  },
  // docs-site has no ESLint setup of its own (`npm run typecheck` is the
  // gate); without this, Next's build-time lint step errors on Vercel
  // where no parent node_modules provides eslint.
  eslint: {
    ignoreDuringBuilds: true,
  },
};

export default nextConfig;
