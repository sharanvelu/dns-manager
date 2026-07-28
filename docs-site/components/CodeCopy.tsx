"use client";

import { useEffect } from "react";

/**
 * Wires up the copy buttons that rehype-code-frame renders into every
 * code frame. One instance per article; event delegation on the document
 * so it survives any number of code blocks.
 */
export default function CodeCopy() {
  useEffect(() => {
    function onClick(event: MouseEvent) {
      const target = event.target;
      if (!(target instanceof Element)) return;
      const button = target.closest("button.code-copy");
      if (!button) return;
      const pre = button.closest("figure.code-frame")?.querySelector("pre");
      if (!pre) return;
      navigator.clipboard
        .writeText(pre.textContent?.replace(/\n$/, "") ?? "")
        .then(() => {
          button.classList.add("copied");
          window.setTimeout(() => button.classList.remove("copied"), 2000);
        })
        .catch(() => {
          /* clipboard unavailable (e.g. insecure context) — no-op */
        });
    }
    document.addEventListener("click", onClick);
    return () => document.removeEventListener("click", onClick);
  }, []);

  return null;
}
