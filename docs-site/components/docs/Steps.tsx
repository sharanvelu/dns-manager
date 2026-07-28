import type { ReactNode } from "react";
import { slugify } from "@/lib/slug";

/**
 * Numbered procedure. Each <Step> gets an auto-incremented number badge
 * and a connector line (CSS counters, app/prose.css). Step titles are
 * anchored h3s, so they show up in the TOC and can be deep-linked.
 *
 *   <Steps>
 *     <Step title="Open the zone">
 *       Prose, <CodeBlock>, <Screenshot> … all allowed inside a step.
 *     </Step>
 *     <Step title="Fill the form">…</Step>
 *   </Steps>
 */

export function Steps({ children }: { children: ReactNode }) {
  return <div className="steps">{children}</div>;
}

export function Step({
  title,
  id,
  children,
}: {
  title: string;
  id?: string;
  children?: ReactNode;
}) {
  const anchor = id ?? slugify(title);
  return (
    <div className="step">
      <h3 id={anchor} className="step-title">
        {title}
        <a href={`#${anchor}`} className="heading-anchor" aria-hidden="true" tabIndex={-1}>
          #
        </a>
      </h3>
      <div className="step-body">{children}</div>
    </div>
  );
}
