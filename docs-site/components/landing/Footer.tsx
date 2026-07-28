import Link from "next/link";
import GitHubIcon from "@/components/GitHubIcon";
import { siteConfig } from "@/lib/site";

export default function Footer({
  version,
  getStartedHref,
}: {
  version: string;
  getStartedHref: string;
}) {
  return (
    <footer className="border-t border-border">
      <div className="mx-auto flex max-w-7xl flex-col items-center justify-between gap-3 px-4 py-8 text-sm text-muted sm:flex-row sm:px-6">
        <span>
          {siteConfig.name} documentation ·{" "}
          <span className="tabular-nums">v{version}</span>
        </span>
        <span className="flex items-center gap-5">
          <a
            href={siteConfig.githubUrl}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-1.5 transition-colors hover:text-foreground"
          >
            <GitHubIcon size={14} />
            GitHub
          </a>
          <Link
            href={getStartedHref}
            className="transition-colors hover:text-foreground"
          >
            Documentation
          </Link>
        </span>
      </div>
    </footer>
  );
}
