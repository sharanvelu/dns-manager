import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Pure static export — deployed as flat files (Vercel).
  output: "export",
  // Every page becomes /{slug}/index.html so relative links resolve
  // cleanly on any static host.
  trailingSlash: true,
  images: {
    unoptimized: true,
  },
};

export default nextConfig;
