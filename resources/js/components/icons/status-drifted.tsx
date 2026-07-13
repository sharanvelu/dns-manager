import type { SVGProps } from 'react';

/** Sync status: drifted — circle with diverging arrows. */
export function StatusDriftedIcon(props: SVGProps<SVGSVGElement>) {
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
            <circle cx="12" cy="12" r="9" />
            <path d="M8.5 9.5h6.2M12.9 7.7l1.8 1.8-1.8 1.8" />
            <path d="M15.5 14.5H9.3M11.1 12.7l-1.8 1.8 1.8 1.8" />
        </svg>
    );
}
