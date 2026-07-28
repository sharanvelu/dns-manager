import type { Metadata } from "next";
import Link from "next/link";

/**
 * Static redirect for the renamed slug: the pre-redesign docs lived at
 * /docs/activity-log/, the group is now /docs/activity/. Static export
 * cannot emit HTTP redirects, so this page meta-refreshes (plus a JS
 * fallback and a visible link).
 */

export const metadata: Metadata = {
  title: "Activity — DNS Manager Docs",
  robots: { index: false },
};

const TARGET = "/docs/activity/";

export default function ActivityLogRedirect() {
  return (
    <div className="flex min-h-[60vh] flex-col items-center justify-center px-4 text-center">
      {/* Browsers honor meta refresh in <body>; the script covers the rest. */}
      <meta httpEquiv="refresh" content={`0; url=${TARGET}`} />
      <script
        dangerouslySetInnerHTML={{
          __html: `window.location.replace(${JSON.stringify(TARGET)});`,
        }}
      />
      <p className="text-sm text-muted">
        This page has moved.{" "}
        <Link href={TARGET} className="font-medium text-accent underline underline-offset-4">
          Continue to Activity
        </Link>
        .
      </p>
    </div>
  );
}
