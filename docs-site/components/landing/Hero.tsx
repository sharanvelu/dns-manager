import { ArrowRight } from "lucide-react";
import Link from "next/link";
import GitHubIcon from "@/components/GitHubIcon";
import Screenshot from "@/components/docs/Screenshot";
import { GET_STARTED_URL, siteConfig } from "@/lib/site";

/**
 * Landing hero: gradient headline, version pill, CTAs and the dashboard
 * screenshot pair (theme-aware; tolerates missing files while captures
 * are being produced).
 */
export default function Hero({ version }: { version: string }) {
  return (
    <section className="relative overflow-hidden">
      {/* Ambient gradient glows — tokens only. */}
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-x-0 -top-40 flex justify-center"
      >
        <div
          className="h-[30rem] w-[52rem] max-w-full rounded-full opacity-25 blur-3xl dark:opacity-20"
          style={{
            background:
              "radial-gradient(closest-side, var(--docs-gradient-via), transparent 72%)",
          }}
        />
      </div>
      <div
        aria-hidden="true"
        className="pointer-events-none absolute -top-24 left-1/2 hidden -translate-x-[40rem] sm:block"
      >
        <div
          className="h-80 w-80 rounded-full opacity-15 blur-3xl"
          style={{
            background:
              "radial-gradient(closest-side, var(--docs-gradient-from), transparent 70%)",
          }}
        />
      </div>
      <div
        aria-hidden="true"
        className="pointer-events-none absolute -top-16 left-1/2 hidden translate-x-[24rem] sm:block"
      >
        <div
          className="h-80 w-80 rounded-full opacity-15 blur-3xl"
          style={{
            background:
              "radial-gradient(closest-side, var(--docs-gradient-to), transparent 70%)",
          }}
        />
      </div>

      <div className="relative mx-auto max-w-4xl px-4 pt-20 text-center sm:px-6 sm:pt-28">
        <p className="mb-6 inline-flex items-center gap-2 rounded-full border border-border bg-surface/60 px-3 py-1 text-xs font-medium text-muted backdrop-blur">
          <span className="size-1.5 rounded-full bg-accent" aria-hidden="true" />
          v{version} · Latest release
        </p>

        <h1 className="text-balance text-5xl font-semibold leading-[1.05] tracking-tighter sm:text-6xl">
          Every DNS record.
          <br />
          Every provider. <span className="gradient-text">One source of truth.</span>
        </h1>

        <p className="mx-auto mt-6 max-w-2xl text-balance text-base leading-relaxed text-muted sm:text-lg">
          {siteConfig.name} is a self-hosted control plane for your DNS records.
          Organize records into zones, connect each provider once, and every
          change you save is pushed to its targets — with scheduled drift checks
          flagging anything changed behind the app&apos;s back.
        </p>

        <div className="mt-8 flex flex-wrap items-center justify-center gap-3">
          <Link
            href={GET_STARTED_URL}
            className="gradient-cta inline-flex h-10 items-center gap-2 rounded-lg px-5 text-sm font-medium text-accent-fg shadow-sm transition-opacity hover:opacity-90"
          >
            Get started
            <ArrowRight size={15} strokeWidth={1.75} />
          </Link>
          <a
            href={siteConfig.githubUrl}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex h-10 items-center gap-2 rounded-lg border border-border bg-surface px-5 text-sm font-medium text-foreground transition-colors hover:bg-background-soft"
          >
            <GitHubIcon size={15} />
            GitHub
          </a>
        </div>
      </div>

      <div className="relative mx-auto mt-14 max-w-5xl px-4 sm:px-6">
        <div
          aria-hidden="true"
          className="pointer-events-none absolute inset-x-8 -top-6 h-24 opacity-30 blur-2xl"
          style={{
            background:
              "linear-gradient(90deg, var(--docs-gradient-from), var(--docs-gradient-via), var(--docs-gradient-to))",
          }}
        />
        <div className="landing-shot relative">
          <Screenshot
            src="dashboard"
            alt="The DNS Manager dashboard"
            caption=""
          />
        </div>
      </div>
    </section>
  );
}
