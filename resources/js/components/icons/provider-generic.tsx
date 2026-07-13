import type { SVGProps } from 'react';

/**
 * Generic provider mark: a minimal server/database node. Used for
 * providers without a dedicated mark (Technitium, Unbound, ...).
 */
export function ProviderGenericMark(props: SVGProps<SVGSVGElement>) {
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
            <rect x="4.75" y="4.75" width="14.5" height="6" rx="1.75" />
            <rect x="4.75" y="13.25" width="14.5" height="6" rx="1.75" />
            <path d="M8 7.75h.01M8 16.25h.01" />
            <path d="M13.25 7.75h3M13.25 16.25h3" opacity={0.6} />
        </svg>
    );
}
