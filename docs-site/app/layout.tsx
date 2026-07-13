import type { Metadata } from "next";
import type { ReactNode } from "react";
import { getVersion } from "@/lib/docs";
import VersionBanner from "@/components/VersionBanner";
import "./globals.css";

export const metadata: Metadata = {
  title: "DNS Manager Docs",
  description: "Documentation for DNS Manager, the homelab DNS control plane.",
};

export default function RootLayout({ children }: { children: ReactNode }) {
  const version = getVersion();
  return (
    <html lang="en">
      <head>
        <link rel="preconnect" href="https://fonts.bunny.net" />
        <link
          rel="stylesheet"
          href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap"
        />
      </head>
      <body>
        <VersionBanner version={version} />
        {children}
      </body>
    </html>
  );
}
