import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Pure static export — the site ships as flat files behind nginx.
  output: "export",
  // Every page becomes /{slug}/index.html so relative links and nginx
  // try_files both resolve cleanly.
  trailingSlash: true,
  images: {
    unoptimized: true,
  },
};

export default nextConfig;
