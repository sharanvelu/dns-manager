import type { SVGProps } from 'react';

/**
 * DNS Manager logo mark: a hexagon containing three route-dots
 * resolving into a single node. Reads at 16px, scales to 48px+.
 *
 * Every shape declares its own fill so the mark stays correct even
 * when a `fill-current` utility class is applied to the root svg.
 */
export function DnsLogo(props: SVGProps<SVGSVGElement>) {
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
            <path d="M12 2.75 20 7.375v9.25L12 21.25 4 16.625v-9.25L12 2.75Z" fill="none" />
            <path d="M8.2 8.2 15.4 12M7.6 12h7.8M8.2 15.8 15.4 12" fill="none" />
            <circle cx="8.2" cy="8.2" r="1.1" fill="currentColor" stroke="none" />
            <circle cx="7.6" cy="12" r="1.1" fill="currentColor" stroke="none" />
            <circle cx="8.2" cy="15.8" r="1.1" fill="currentColor" stroke="none" />
            <circle cx="15.4" cy="12" r="1.9" fill="currentColor" stroke="none" />
        </svg>
    );
}
