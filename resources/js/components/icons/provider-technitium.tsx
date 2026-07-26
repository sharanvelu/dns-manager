import type { SVGProps } from 'react';

/**
 * Original abstract mark for the Technitium provider: a server box
 * fanning out into resolving route-dots (authoritative answers).
 * Not the official logo.
 */
export function ProviderTechnitiumMark(props: SVGProps<SVGSVGElement>) {
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
            <rect x="4.75" y="3.75" width="14.5" height="7.5" rx="1.75" />
            <path d="M7.75 7.5h.01" />
            <path d="M11 7.5h5.25" opacity={0.75} />
            <path d="M12 11.25v3.25m0 0-5 3.5m5-3.5 5 3.5m-5-3.5v3.5" opacity={0.75} />
            <path d="M7 20.25h.01M12 20.25h.01M17 20.25h.01" />
        </svg>
    );
}
