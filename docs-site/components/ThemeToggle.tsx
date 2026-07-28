"use client";

import { Monitor, Moon, Sun } from "lucide-react";
import { useEffect, useState } from "react";

type Theme = "light" | "dark" | "system";

const CYCLE: Record<Theme, Theme> = {
  light: "dark",
  dark: "system",
  system: "light",
};

const LABELS: Record<Theme, string> = {
  light: "Theme: light",
  dark: "Theme: dark",
  system: "Theme: system",
};

function isTheme(value: string | null): value is Theme {
  return value === "light" || value === "dark" || value === "system";
}

/**
 * Same-origin theme sync with the DNS Manager app: the app stores its
 * theme in localStorage("appearance") — read that first, fall back to the
 * docs site's own "theme" key, and write BOTH on change so app and docs
 * stay in lockstep when served together. Keep in sync with
 * components/ThemeScript.tsx.
 */
function readStoredTheme(): Theme {
  const app = localStorage.getItem("appearance");
  if (isTheme(app)) return app;
  const own = localStorage.getItem("theme");
  if (isTheme(own)) return own;
  return "system";
}

function isDark(theme: Theme): boolean {
  return (
    theme === "dark" ||
    (theme === "system" &&
      window.matchMedia("(prefers-color-scheme: dark)").matches)
  );
}

function applyTheme(theme: Theme) {
  document.documentElement.classList.toggle("dark", isDark(theme));
}

export default function ThemeToggle() {
  const [mounted, setMounted] = useState(false);
  const [theme, setTheme] = useState<Theme>("system");

  useEffect(() => {
    setMounted(true);
    setTheme(readStoredTheme());
  }, []);

  useEffect(() => {
    if (!mounted) return;
    applyTheme(theme);
    const query = window.matchMedia("(prefers-color-scheme: dark)");
    const onChange = () => {
      if (theme === "system") applyTheme("system");
    };
    query.addEventListener("change", onChange);
    return () => query.removeEventListener("change", onChange);
  }, [theme, mounted]);

  const cycle = () => {
    const next = CYCLE[theme];
    setTheme(next);
    localStorage.setItem("theme", next);
    localStorage.setItem("appearance", next);
  };

  const Icon = !mounted || theme === "system" ? Monitor : theme === "light" ? Sun : Moon;

  return (
    <button
      type="button"
      onClick={cycle}
      aria-label={mounted ? LABELS[theme] : "Toggle theme"}
      title={mounted ? LABELS[theme] : "Toggle theme"}
      className="inline-flex size-8 items-center justify-center rounded-md text-muted transition-colors hover:bg-background-soft hover:text-foreground"
    >
      <Icon size={16} strokeWidth={1.5} />
    </button>
  );
}
