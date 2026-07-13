import type { SVGProps } from 'react';

/**
 * Empty-state illustration for providers: an unplugged connector —
 * plug and socket separated by a small gap with spark ticks.
 * Decorative, monochrome via currentColor at layered opacities.
 */
export function EmptyProvidersIllustration(props: SVGProps<SVGSVGElement>) {
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
            {/* backdrop ring */}
            <circle cx="80" cy="60" r="44" strokeDasharray="2 7" opacity={0.15} />
            {/* left cable + plug */}
            <g opacity={0.5}>
                <path d="M12 68c14 0 18-8 30-8" />
                <rect x="42" y="50" width="20" height="20" rx="5" />
                <path d="M62 55.5h7M62 64.5h7" />
            </g>
            {/* gap sparks */}
            <g opacity={0.3}>
                <path d="m77 48 4-4M79 60h6M77 72l4 4" />
            </g>
            {/* right socket + cable */}
            <g opacity={0.5}>
                <rect x="94" y="48" width="24" height="24" rx="6" />
                <path d="M101.5 55.5h.01M110.5 55.5h.01" />
                <path d="M101 65h10" opacity={0.7} />
                <path d="M118 60c14 0 16 8 30 8" />
            </g>
        </svg>
    );
}
