import type { SVGProps } from 'react';

/**
 * Zone mark: a root node branching to two subdomain nodes — kin to the
 * DnsLogo's route-dot language. Reads at 16px.
 *
 * Dots declare their own fill so the mark stays correct even when a
 * `fill-current` utility class is applied to the root svg.
 */
export function ZoneMark(props: SVGProps<SVGSVGElement>) {
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
            {/* root (apex) node */}
            <circle cx="6.5" cy="12" r="2.4" fill="none" />
            {/* branches to subdomain nodes */}
            <path d="M8.6 10.9c2.6-1.3 4.6-2.1 7.1-2.9M8.6 13.1c2.6 1.3 4.6 2.1 7.1 2.9" fill="none" />
            {/* subdomain nodes */}
            <circle cx="17.5" cy="7.4" r="1.6" fill="currentColor" stroke="none" />
            <circle cx="17.5" cy="16.6" r="1.6" fill="currentColor" stroke="none" />
            {/* apex dot */}
            <circle cx="6.5" cy="12" r="0.9" fill="currentColor" stroke="none" />
        </svg>
    );
}
