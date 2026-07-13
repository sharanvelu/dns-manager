import type { SVGProps } from 'react';

/**
 * Empty-state illustration for the activity log: a calm pulse line
 * on a panel. Decorative, monochrome via currentColor at layered
 * opacities.
 */
export function EmptyActivityIllustration(props: SVGProps<SVGSVGElement>) {
    return (
        <svg
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 160 120"
            width={160}
            height={120}
            fill="none"
            stroke="currentColor"
            strokeWidth={1.5}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            {...props}
        >
            {/* panel */}
            <rect x="24" y="30" width="112" height="60" rx="8" opacity={0.4} />
            {/* baseline */}
            <path d="M14 60h132" strokeDasharray="2 6" opacity={0.15} />
            {/* calm pulse line */}
            <path d="M32 60h22l6-13 8 24 6-16 4 5h34" opacity={0.6} />
            <circle cx="112" cy="60" r="2.25" fill="currentColor" stroke="none" opacity={0.6} />
            {/* tick marks */}
            <g opacity={0.2}>
                <path d="M44 98v4M68 98v4M92 98v4M116 98v4" />
            </g>
        </svg>
    );
}
