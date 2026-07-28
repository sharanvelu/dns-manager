import type { ReactNode } from "react";

/**
 * GitHub-vocabulary callout. Colors + icons come from app/prose.css,
 * which reads only palette tokens.
 *
 *   <Callout type="warning">Deleting a zone removes …</Callout>
 *   <Callout type="tip" title="Shortcut">…</Callout>
 */

export type CalloutType = "note" | "tip" | "important" | "warning" | "caution";

const LABELS: Record<CalloutType, string> = {
  note: "Note",
  tip: "Tip",
  important: "Important",
  warning: "Warning",
  caution: "Caution",
};

export default function Callout({
  type = "note",
  title,
  children,
}: {
  type?: CalloutType;
  title?: string;
  children: ReactNode;
}) {
  return (
    <div className={`callout callout-${type}`}>
      <p className="callout-title">{title ?? LABELS[type]}</p>
      <div className="callout-body">{children}</div>
    </div>
  );
}
