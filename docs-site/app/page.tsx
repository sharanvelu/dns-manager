import type { Metadata } from "next";
import FeatureCard from "@/components/landing/FeatureCard";
import Footer from "@/components/landing/Footer";
import Hero from "@/components/landing/Hero";
import Providers from "@/components/landing/Providers";
import {
  getDoc,
  getFeatureCards,
  getNav,
  getSection,
  getVersion,
  markdownToHtml,
} from "@/lib/docs";
import { docHref } from "@/lib/site";

const FALLBACK_PITCH =
  "Manage DNS entries across multiple providers from one place.";

export function generateMetadata(): Metadata {
  const index = getDoc("index");
  return {
    title: "DNS Manager Docs",
    description: index?.description || FALLBACK_PITCH,
  };
}

export default async function LandingPage() {
  const version = getVersion();
  const index = getDoc("index");
  const nav = getNav();

  const pitch = index?.description || FALLBACK_PITCH;
  const features = index ? await getFeatureCards(index.markdown) : [];
  const providersMd = index
    ? getSection(index.markdown, "supported providers")
    : undefined;
  const providersHtml = providersMd ? await markdownToHtml(providersMd) : "";

  const getStartedHref = nav.some((d) => d.slug === "installation")
    ? "/installation/"
    : nav.find((d) => d.slug !== "index")
      ? docHref(nav.find((d) => d.slug !== "index")!.slug)
      : "/";

  return (
    <>
      <Hero version={version} pitch={pitch} getStartedHref={getStartedHref} />

      {features.length > 0 && (
        <section className="mx-auto max-w-7xl px-4 pb-20 sm:px-6">
          <p className="mb-2 text-center text-xs font-semibold uppercase tracking-[0.1em] text-accent">
            Features
          </p>
          <h2 className="mb-10 text-center text-2xl font-semibold tracking-tight">
            Everything your homelab DNS needs
          </h2>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {features.map((feature, i) => (
              <FeatureCard key={i} feature={feature} index={i} />
            ))}
          </div>
        </section>
      )}

      {providersHtml && <Providers html={providersHtml} />}

      <Footer version={version} getStartedHref={getStartedHref} />
    </>
  );
}
