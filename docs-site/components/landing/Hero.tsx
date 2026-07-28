import { ArrowRight } from "lucide-react";
import Link from "next/link";
import GitHubIcon from "@/components/GitHubIcon";
import { siteConfig } from "@/lib/site";

export default function Hero({
  version,
  pitch,
  getStartedHref,
}: {
  version: string;
  pitch: string;
  getStartedHref: string;
}) {
  return (
    <section className="relative overflow-hidden">
      {/* Ambient gradient glow — restrained, tokens only. */}
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-x-0 -top-40 flex justify-center"
      >
        <div
          className="h-[26rem] w-[46rem] max-w-full rounded-full opacity-20 blur-3xl dark:opacity-15"
          style={{
            background:
              "radial-gradient(closest-side, var(--docs-gradient-via), transparent 72%)",
          }}
        />
      </div>
      <div
        aria-hidden="true"
        className="pointer-events-none absolute -top-24 left-1/2 hidden -translate-x-[38rem] sm:block"
      >
        <div
          className="h-72 w-72 rounded-full opacity-10 blur-3xl"
          style={{
            background:
              "radial-gradient(closest-side, var(--docs-gradient-from), transparent 70%)",
          }}
        />
      </div>
      <div
        aria-hidden="true"
        className="pointer-events-none absolute -top-16 left-1/2 hidden translate-x-[22rem] sm:block"
      >
        <div
          className="h-72 w-72 rounded-full opacity-10 blur-3xl"
          style={{
            background:
              "radial-gradient(closest-side, var(--docs-gradient-to), transparent 70%)",
          }}
        />
      </div>

      <div className="relative mx-auto max-w-3xl px-4 pb-16 pt-20 text-center sm:px-6 sm:pb-20 sm:pt-28">
        <p className="mb-6 inline-flex items-center gap-2 rounded-full border border-border bg-surface/60 px-3 py-1 text-xs font-medium text-muted backdrop-blur">
          <span className="size-1.5 rounded-full bg-accent" aria-hidden="true" />
          v{version} · Latest release
        </p>

        <h1 className="text-5xl font-semibold leading-[1.05] tracking-tighter sm:text-6xl">
          <span
            className="bg-clip-text text-transparent"
            style={{
              backgroundImage:
                "linear-gradient(100deg, var(--docs-gradient-from), var(--docs-gradient-via) 55%, var(--docs-gradient-to))",
            }}
          >
            DNS
          </span>{" "}
          Manager
        </h1>

        <p className="mx-auto mt-5 max-w-xl text-balance text-base leading-relaxed text-muted sm:text-lg">
          {pitch}
        </p>

        <div className="mt-8 flex flex-wrap items-center justify-center gap-3">
          <Link
            href={getStartedHref}
            className="inline-flex h-10 items-center gap-2 rounded-lg bg-accent px-5 text-sm font-medium text-accent-fg shadow-sm transition-opacity hover:opacity-90"
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
    </section>
  );
}
