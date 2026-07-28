import type { Metadata } from "next";
import Features from "@/components/landing/Features";
import Footer from "@/components/landing/Footer";
import Hero from "@/components/landing/Hero";
import HowItWorks from "@/components/landing/HowItWorks";
import ProviderShowcase from "@/components/landing/ProviderShowcase";
import { getVersion } from "@/lib/version";
import { siteConfig } from "@/lib/site";

/**
 * Product landing page, served at `/` (Vercel deployment only — the
 * Docker image mounts out/docs/ alone, where `/` remains the Laravel
 * app). All claims sourced from the docs overview, README.md and
 * ARCHITECTURE.md.
 */

export const metadata: Metadata = {
  title: `${siteConfig.name} — self-hosted multi-provider DNS`,
  description:
    "Manage DNS entries across Cloudflare, Pi-hole and Technitium from one place — zones, automatic pushes, drift detection, RBAC and a full audit trail.",
};

export default function LandingPage() {
  const version = getVersion();

  return (
    <>
      <Hero version={version} />
      <HowItWorks />
      <Features />
      <ProviderShowcase />
      <Footer version={version} />
    </>
  );
}
