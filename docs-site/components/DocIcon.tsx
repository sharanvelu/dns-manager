import { createElement } from "react";
import { hasIcon, ICONS } from "@/lib/icons";

/**
 * React adapter for the shared lib/icons.ts stroke set (the markdown
 * pipeline renders the same shapes via hast in remark-directives).
 */
export default function DocIcon({
  name,
  size = 16,
  strokeWidth = 1.75,
  className,
}: {
  name: string;
  size?: number;
  strokeWidth?: number;
  className?: string;
}) {
  const shapes = hasIcon(name) ? ICONS[name] : ICONS.globe;
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      viewBox="0 0 24 24"
      width={size}
      height={size}
      fill="none"
      stroke="currentColor"
      strokeWidth={strokeWidth}
      strokeLinecap="round"
      strokeLinejoin="round"
      className={className}
      aria-hidden="true"
    >
      {shapes.map((shape, i) =>
        createElement(shape.tag, { key: i, ...shape.attrs }),
      )}
    </svg>
  );
}
