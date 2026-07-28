import { Globe } from "lucide-react";
import Link from "next/link";
import type { NavGroup } from "@/lib/site";
import { siteConfig } from "@/lib/site";
import GitHubIcon from "./GitHubIcon";
import MobileNav from "./MobileNav";
import SearchDialog from "./SearchDialog";
import ThemeToggle from "./ThemeToggle";

export default function Header({
  version,
  groups,
}: {
  version: string;
  groups: NavGroup[];
}) {
  return (
    <header className="sticky top-0 z-40 border-b border-border bg-background/80 backdrop-blur-md">
      <div className="mx-auto flex h-14 max-w-7xl items-center gap-3 px-4 sm:px-6">
        <MobileNav groups={groups} version={version} />

        <Link href="/" className="flex min-w-0 items-center gap-2.5">
          <span
            aria-hidden="true"
            className="flex size-7 shrink-0 items-center justify-center rounded-lg text-accent-fg shadow-sm"
            style={{
              background:
                "linear-gradient(135deg, var(--docs-gradient-from), var(--docs-gradient-via) 55%, var(--docs-gradient-to))",
            }}
          >
            <Globe size={15} strokeWidth={1.75} />
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
