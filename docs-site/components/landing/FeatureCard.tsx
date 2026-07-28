import {
  Activity,
  BadgeCheck,
  CloudUpload,
  Container,
  FileSpreadsheet,
  Globe,
  HeartPulse,
  KeyRound,
  LayoutDashboard,
  ListChecks,
  Lock,
  Moon,
  Plug,
  Puzzle,
  Radar,
  RefreshCw,
  ScrollText,
  Shield,
  SlidersHorizontal,
  Sparkles,
  Target,
  Users,
  Zap,
  type LucideIcon,
} from "lucide-react";
import type { CSSProperties } from "react";
import type { FeatureCard as FeatureCardData } from "@/lib/docs";

/** Keyword → icon, first match wins; content-driven titles stay authoritative. */
const ICON_RULES: Array<[RegExp, LucideIcon]> = [
  [/audit/i, ScrollText],
  [/dark/i, Moon],
  [/kubernetes/i, Container],
  [/csv/i, FileSpreadsheet],
  [/bulk/i, ListChecks],
  [/import/i, CloudUpload],
  [/adopt/i, BadgeCheck],
  [/form/i, SlidersHorizontal],
  [/access|role/i, Users],
  [/sign-on|oidc|sso/i, Shield],
  [/encrypt/i, Lock],
  [/health/i, HeartPulse],
  [/discovery/i, Radar],
  [/drift|activity/i, Activity],
  [/push|save/i, Zap],
  [/targeting/i, Target],
  [/pane|glass/i, LayoutDashboard],
  [/credential/i, KeyRound],
  [/refresh/i, RefreshCw],
  [/connector|extensib/i, Puzzle],
  [/provider/i, Plug],
  [/zone|dns/i, Globe],
];

function pickIcon(title: string): LucideIcon {
  for (const [pattern, icon] of ICON_RULES) {
    if (pattern.test(title)) return icon;
  }
  return Sparkles;
}

export default function FeatureCard({
  feature,
  index,
}: {
  feature: FeatureCardData;
  index: number;
}) {
  const hue = (index % 6) + 1;
  const Icon = pickIcon(feature.title);

  return (
    <div
      className="group flex flex-col gap-3 rounded-xl border border-border bg-surface p-5 transition-all duration-200 hover:-translate-y-0.5 hover:border-(--card-hue)/50 hover:shadow-md"
      style={{ "--card-hue": `var(--docs-card-${hue})` } as CSSProperties}
    >
      <span
        aria-hidden="true"
        className="flex size-9 items-center justify-center rounded-lg"
        style={{
          color: "var(--card-hue)",
          backgroundColor:
            "color-mix(in srgb, var(--card-hue) 11%, transparent)",
        }}
      >
        <Icon size={17} strokeWidth={1.5} />
      </span>
      {feature.title && (
        <h3 className="text-sm font-semibold tracking-tight">{feature.title}</h3>
      )}
      <div
        className="prose feature-body text-[13px] leading-relaxed text-muted [&_p]:mb-2 [&_p:last-child]:mb-0"
        dangerouslySetInnerHTML={{ __html: feature.html }}
      />
    </div>
  );
}
