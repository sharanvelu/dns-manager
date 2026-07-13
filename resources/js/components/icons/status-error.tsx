import type { SVGProps } from 'react';

/** Sync status: error — circle with an exclamation mark. */
export function StatusErrorIcon(props: SVGProps<SVGSVGElement>) {
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
            <path d="M12 7.75v5" />
            <path d="M12 16h.01" />
        </svg>
    );
}
