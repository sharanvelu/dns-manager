import type { Metadata } from "next";
import { ArrowLeft } from "lucide-react";
import Link from "next/link";

export const metadata: Metadata = {
  title: "Page not found — DNS Manager Docs",
};

export default function NotFound() {
  return (
    <div className="flex min-h-[70vh] flex-col items-center justify-center px-4 text-center">
      <p className="gradient-text text-7xl font-semibold tracking-tighter sm:text-8xl">
        404
      </p>
      <h1 className="mt-4 text-xl font-semibold tracking-tight">
        Page not found
      </h1>
      <p className="mt-2 max-w-sm text-sm text-muted">
        This page does not exist in the DNS Manager documentation.
      </p>
      <Link
        href="/docs/"
        className="gradient-cta mt-6 inline-flex h-10 items-center gap-2 rounded-lg px-5 text-sm font-medium text-accent-fg shadow-sm transition-opacity hover:opacity-90"
      >
        <ArrowLeft size={15} strokeWidth={1.75} />
        Back to the docs
      </Link>
    </div>
  );
}
