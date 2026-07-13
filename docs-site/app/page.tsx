import type { Metadata } from "next";
import Link from "next/link";
import {
  getDoc,
  getFeatureCards,
  getNav,
  getSection,
  getVersion,
  markdownToHtml,
} from "@/lib/docs";

const GITHUB_URL = "https://github.com/OWNER/dns-manager";
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
      ? `/${nav.find((d) => d.slug !== "index")!.slug}/`
      : "/";

  return (
    <div className="landing">
      <section className="hero">
        <span className="hero-badge">
          <span className="dot" />
          v{version}
        </span>
        <h1>DNS Manager</h1>
        <p>{pitch}</p>
        <div className="hero-actions">
          <Link className="btn btn-primary" href={getStartedHref}>
            Get started
          </Link>
          <a
            className="btn btn-secondary"
            href={GITHUB_URL}
            target="_blank"
            rel="noopener noreferrer"
          >
            GitHub
          </a>
        </div>
      </section>

      {features.length > 0 && (
        <section className="features">
          <p className="section-label">Features</p>
          <div className="features-grid">
            {features.map((f, i) => (
              <div className="feature-card" key={i}>
                {f.title && <h3>{f.title}</h3>}
                <div
                  className="feature-body"
                  dangerouslySetInnerHTML={{ __html: f.html }}
                />
              </div>
            ))}
          </div>
        </section>
      )}

      {providersHtml && (
        <section className="providers-section">
          <p className="section-label">Supported providers</p>
          <div
            className="prose"
            dangerouslySetInnerHTML={{ __html: providersHtml }}
          />
        </section>
      )}

      <footer className="landing-footer">
        <span>DNS Manager documentation · v{version}</span>
        <span>
          <a href={GITHUB_URL} target="_blank" rel="noopener noreferrer">
            GitHub
          </a>
          {nav.length > 0 && (
            <>
              {" · "}
              <Link href={getStartedHref}>Documentation</Link>
            </>
          )}
        </span>
      </footer>
    </div>
  );
}
