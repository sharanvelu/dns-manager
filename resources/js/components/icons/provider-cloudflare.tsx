import type { SVGProps } from 'react';

/**
 * Original abstract mark for the Cloudflare provider: a stylized
 * cloud with a subtle bolt/edge line. Not the official logo.
 */
export function ProviderCloudflareMark(props: SVGProps<SVGSVGElement>) {
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
            <path d="M17.25 18.25H9.25a6.5 6.5 0 1 1 6.23-8.35h1.77a4.175 4.175 0 0 1 0 8.35Z" />
            <path d="m12.5 10.75-1.75 2.75h2.5l-1.75 2.75" opacity={0.75} />
        </svg>
    );
}
