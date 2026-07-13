import type { SVGProps } from 'react';

/**
 * Original abstract mark for the Pi-hole provider: a shield with a
 * filter-funnel motif (blocking/filtering). Not the official logo.
 */
export function ProviderPiholeMark(props: SVGProps<SVGSVGElement>) {
    return (
        <svg
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 24 24"
            width={24}
            height={24}
            fill="none"
            stroke="currentColor"
            strokeWidth={1.5}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            {...props}
        >
            <path d="M12 3.25 18.75 5.9v4.85c0 4.05-2.7 7.15-6.75 8.5-4.05-1.35-6.75-4.45-6.75-8.5V5.9L12 3.25Z" />
            <path d="M9.25 8.75h5.5l-2 2.75v3.15l-1.5-1.15v-2L9.25 8.75Z" opacity={0.75} />
        </svg>
    );
}
