import type { SVGProps } from 'react';

/**
 * Technitium logo mark — the interlocking-T maze in the brand blue
 * (#6699FF), traced rectangle-for-rectangle from the official 48×48
 * logo.png shipped with Technitium DNS Server (coordinates halved).
 * Technitium is a trademark of Technitium, used here to identify the
 * connector.
 */
export function ProviderTechnitiumMark(props: SVGProps<SVGSVGElement>) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width={24} height={24} aria-hidden="true" {...props}>
            <path
                fill="#69F"
                d="M0 0h24v1H0zM0 23h24v1H0zM0 1h1v22H0zM23 1h1v22h-1zM18.5 1h1.5v3h-1.5zM1 4h4.5v1.5H1zM8.5 4h11.5v1.5H8.5zM4 5.5h1.5v10H4zM4 18.5h1.5v4.5H4zM8.5 5.5h1.5v3H8.5zM8.5 8.5h11.5v1.5H8.5zM8.5 10h7v4h-7zM4 14h11.5v1.5H4zM14 15.5h1.5v3H14zM18.5 10h1.5v8.5h-1.5zM4 18.5h11.5v1.5H4zM18.5 18.5h5.5v1.5h-5.5z"
            />
        </svg>
    );
}
