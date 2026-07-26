import type { SVGProps } from 'react';

/**
 * Empty-state illustration for the zones list: a root domain node
 * fanning out to subdomain cards. Decorative, monochrome via
 * currentColor at layered opacities.
 */
export function EmptyZonesIllustration(props: SVGProps<SVGSVGElement>) {
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
            {/* root domain card */}
            <rect x="16" y="48" width="44" height="24" rx="6" opacity={0.45} />
            <path d="M24 58h28M24 63h18" opacity={0.3} />
            {/* branches */}
            <g opacity={0.35}>
                <path d="M60 55c18-8 28-16 42-21M60 60h44M60 65c18 8 28 16 42 21" />
            </g>
            {/* subdomain cards */}
            <g opacity={0.45}>
                <rect x="106" y="22" width="38" height="18" rx="5" />
                <rect x="108" y="51" width="38" height="18" rx="5" />
                <rect x="106" y="80" width="38" height="18" rx="5" />
            </g>
            <g opacity={0.25}>
                <path d="M113 31h24M115 60h24M113 89h24" />
            </g>
            {/* junction dots */}
            <g fill="currentColor" stroke="none" opacity={0.3}>
                <circle cx="60" cy="60" r="2" />
                <circle cx="102" cy="34" r="1.5" />
                <circle cx="104" cy="60" r="1.5" />
                <circle cx="102" cy="86" r="1.5" />
            </g>
        </svg>
    );
}
