import type { Metadata } from "next";
import Link from "next/link";

export const metadata: Metadata = {
  title: "Page not found — DNS Manager Docs",
};

export default function NotFound() {
  return (
    <div className="notfound">
      <h1>404</h1>
      <p>This page does not exist in the DNS Manager documentation.</p>
      <Link className="btn btn-primary" href="/">
        Back to the docs
      </Link>
    </div>
  );
}
