import Link from "next/link";
import DocIcon from "@/components/DocIcon";
import { navGroups } from "@/lib/registry";
import { DOCS_HOME_URL, siteConfig } from "@/lib/site";
import GitHubIcon from "./GitHubIcon";
import MobileNav from "./MobileNav";
import SearchDialog from "./SearchDialog";
import ThemeToggle from "./ThemeToggle";

export default function Header({ version }: { version: string }) {
  const groups = navGroups();

  return (
    <header className="sticky top-0 z-40 border-b border-border bg-background/80 backdrop-blur-md">
      {/* Gradient hairline under the header. */}
      <div aria-hidden="true" className="gradient-hairline absolute inset-x-0 bottom-0" />
      <div className="mx-auto flex h-14 max-w-7xl items-center gap-3 px-4 sm:px-6">
        <MobileNav groups={groups} version={version} />

        <Link href="/" className="flex min-w-0 items-center gap-2.5">
          <span
            aria-hidden="true"
            className="gradient-mark flex size-7 shrink-0 items-center justify-center rounded-lg text-accent-fg shadow-sm"
          >
            <DocIcon name="globe" size={15} />
          </span>
          <span className="truncate text-[15px] font-semibold tracking-tight">
            {siteConfig.name}
          </span>
          <span
            title={`Docs for the latest release (v${version}). Running an older version? Open /docs on your own instance.`}
            className="hidden rounded-full border border-border bg-background-soft px-2 py-0.5 text-[11px] font-medium tabular-nums text-muted sm:inline-block"
          >
            v{version}
          </span>
        </Link>

        <nav className="ml-4 hidden items-center gap-1 lg:flex" aria-label="Primary">
          <Link
            href={DOCS_HOME_URL}
            className="rounded-md px-2.5 py-1.5 text-[13px] font-medium text-muted transition-colors hover:bg-background-soft hover:text-foreground"
          >
            Documentation
          </Link>
        </nav>

        <div className="ml-auto flex items-center gap-1">
          <SearchDialog />
          <a
            href={siteConfig.githubUrl}
            target="_blank"
            rel="noopener noreferrer"
            aria-label="GitHub repository"
            title="GitHub"
            className="inline-flex size-8 items-center justify-center rounded-md text-muted transition-colors hover:bg-background-soft hover:text-foreground"
          >
            <GitHubIcon size={16} />
          </a>
          <ThemeToggle />
        </div>
      </div>
    </header>
  );
}
