import type { Metadata } from "next";
import type { ReactNode } from "react";
import Header from "@/components/Header";
import ThemeScript from "@/components/ThemeScript";
import { getVersion } from "@/lib/version";
import { siteConfig } from "@/lib/site";
import "./globals.css";

export const metadata: Metadata = {
  title: siteConfig.title,
  description: siteConfig.description,
};

export default function RootLayout({ children }: { children: ReactNode }) {
  const version = getVersion();

  return (
    // suppressHydrationWarning: ThemeScript mutates <html> before hydration.
    <html lang="en" suppressHydrationWarning>
      <head>
        <ThemeScript />
        <link rel="preconnect" href="https://fonts.bunny.net" />
        <link
          rel="stylesheet"
          href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700|jetbrains-mono:400,500&display=swap"
        />
      </head>
      <body className="min-h-screen">
        <Header version={version} />
        {children}
      </body>
    </html>
  );
}
