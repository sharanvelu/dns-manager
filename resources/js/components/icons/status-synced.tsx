import type { SVGProps } from 'react';

/** Sync status: synced — circle with a check. */
export function StatusSyncedIcon(props: SVGProps<SVGSVGElement>) {
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
            <path d="m8.4 12.3 2.5 2.5 4.7-5.2" />
        </svg>
    );
}
