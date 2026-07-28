import Link from "next/link";
import type { CSSProperties, ReactNode } from "react";
import DocIcon from "@/components/DocIcon";
import { iconHue } from "@/lib/icons";

/**
 * Responsive 2-column card grid for concept navigation.
 *
 *   <Cards>
 *     <Card title="Create a zone" icon="zones" href="/docs/zones/creating/">
 *       One-or-two-line description.
 *     </Card>
 *     <Card title="Info card" icon="shield">No href — not a link.</Card>
 *   </Cards>
 *
 * `icon` is a lib/icons.ts name (installation, authentication, dashboard,
 * zones, dns-entries, providers, users, activity, cloudflare, pihole,
 * technitium, sync, shield, globe, terminal); it also selects the card's
 * hue (palette --docs-group-* tokens). Styling lives in app/prose.css.
 */

export function Cards({ children }: { children: ReactNode }) {
  return <div className="card-grid">{children}</div>;
}

export function Card({
  title,
  icon,
  href,
  children,
}: {
  title: string;
  icon?: string;
  href?: string;
  children?: ReactNode;
}) {
  const style = {
    "--card-hue": `var(${iconHue(icon ?? "globe")})`,
  } as CSSProperties;

  const body = (
    <>
      {icon && (
        <span className="doc-card-icon" aria-hidden="true">
          <DocIcon name={icon} size={18} />
        </span>
      )}
      <span className="doc-card-body">
        <span className="doc-card-title">{title}</span>
        {children != null && <span className="doc-card-text">{children}</span>}
      </span>
    </>
  );

  if (href) {
    return (
      <Link href={href} className="doc-card doc-card-link" style={style}>
        {body}
      </Link>
    );
  }
  return (
    <div className="doc-card" style={style}>
      {body}
    </div>
  );
}
