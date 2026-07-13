import type { SVGProps } from 'react';

/**
 * Empty-state illustration for the DNS entries list: a records card
 * with type chips and value lines. Decorative, monochrome via
 * currentColor at layered opacities.
 */
export function EmptyEntriesIllustration(props: SVGProps<SVGSVGElement>) {
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
            {/* offset backdrop card */}
            <rect x="44" y="18" width="92" height="72" rx="8" opacity={0.12} />
            {/* main card */}
            <rect x="30" y="30" width="92" height="72" rx="8" opacity={0.45} />
            {/* record rows: type chip + value lines */}
            <g opacity={0.45}>
                <rect x="38" y="40" width="12" height="12" rx="3" />
                <rect x="38" y="60" width="12" height="12" rx="3" />
                <rect x="38" y="80" width="12" height="12" rx="3" />
            </g>
            <g opacity={0.3}>
                <path d="M58 44h44M58 64h36M58 84h44" />
            </g>
            <g opacity={0.18}>
                <path d="M58 49h24M58 69h30M58 89h20" />
            </g>
            {/* floating resolve dots */}
            <g fill="currentColor" stroke="none" opacity={0.25}>
                <circle cx="136" cy="42" r="2" />
                <circle cx="142" cy="58" r="1.5" />
                <circle cx="22" cy="24" r="1.5" />
            </g>
        </svg>
    );
}
