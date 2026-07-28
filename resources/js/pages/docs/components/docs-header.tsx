import { Link } from '@inertiajs/react';
import { Github, Menu, Monitor, Moon, Search, Sun } from 'lucide-react';

import { DnsLogo } from '@/components/icons';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { useAppearance, type Appearance } from '@/hooks/use-appearance';

const GITHUB_URL = 'https://github.com/sharanvelu/dns-manager';

const APPEARANCE_CYCLE: Appearance[] = ['light', 'dark', 'system'];

function ThemeToggle() {
    const { appearance, updateAppearance } = useAppearance();

    const next = APPEARANCE_CYCLE[(APPEARANCE_CYCLE.indexOf(appearance) + 1) % APPEARANCE_CYCLE.length];
    const Icon = appearance === 'light' ? Sun : appearance === 'dark' ? Moon : Monitor;

    return (
        <TooltipProvider delayDuration={300}>
            <Tooltip>
                <TooltipTrigger asChild>
                    <Button variant="ghost" size="icon" className="size-8" onClick={() => updateAppearance(next)} aria-label={`Theme: ${appearance}`}>
                        <Icon className="size-4" />
                    </Button>
                </TooltipTrigger>
                <TooltipContent side="bottom">Theme: {appearance}</TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}

interface DocsHeaderProps {
    version: string;
    onOpenSearch: () => void;
    onOpenNav: () => void;
}

export function DocsHeader({ version, onOpenSearch, onOpenNav }: DocsHeaderProps) {
    return (
        <header className="border-border/70 bg-background/80 sticky top-0 z-40 border-b backdrop-blur-md">
            <div className="mx-auto flex h-14 w-full max-w-screen-2xl items-center gap-3 px-4 sm:px-6">
                <Button variant="ghost" size="icon" className="size-8 lg:hidden" onClick={onOpenNav} aria-label="Open navigation">
                    <Menu className="size-4" />
                </Button>

                <Link href="/docs" className="flex items-center gap-2.5">
                    <DnsLogo className="text-docs-accent size-6" />
                    <span className="text-sm font-semibold tracking-tight">
                        DNS Manager
                        <span className="from-docs-gradient-from via-docs-gradient-via to-docs-gradient-to ml-1.5 bg-gradient-to-r bg-clip-text font-medium text-transparent">
                            Docs
                        </span>
                    </span>
                </Link>

                <Badge variant="outline" className="text-muted-foreground hidden font-mono text-[11px] sm:inline-flex">
                    v{version}
                </Badge>

                <div className="ml-auto flex items-center gap-1.5">
                    <button
                        type="button"
                        onClick={onOpenSearch}
                        className="bg-muted/40 text-muted-foreground hover:bg-muted hidden h-8 w-56 cursor-pointer items-center gap-2 rounded-md border px-2.5 text-sm transition-colors sm:flex"
                    >
                        <Search className="size-3.5" />
                        <span className="flex-1 text-left text-[13px]">Search docs…</span>
                        <kbd className="bg-background rounded border px-1 font-mono text-[10px]">⌘K</kbd>
                    </button>
                    <Button variant="ghost" size="icon" className="size-8 sm:hidden" onClick={onOpenSearch} aria-label="Search docs">
                        <Search className="size-4" />
                    </Button>

                    <Button variant="ghost" size="icon" className="size-8" asChild>
                        <a href={GITHUB_URL} target="_blank" rel="noreferrer" aria-label="GitHub repository">
                            <Github className="size-4" />
                        </a>
                    </Button>

                    <ThemeToggle />

                    <Button variant="outline" size="sm" className="ml-1 hidden h-8 md:inline-flex" asChild>
                        <Link href="/dashboard">Back to app</Link>
                    </Button>
                </div>
            </div>
        </header>
    );
}
