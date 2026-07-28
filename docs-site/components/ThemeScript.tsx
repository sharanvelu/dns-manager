/**
 * Inline no-flash script — runs before first paint, applies `.dark` to
 * <html>. Theme resolution ∈ {light, dark, system}:
 *
 * 1. localStorage("appearance") — the DNS Manager app's key. When the
 *    docs are served same-origin with the app (nginx mounts them at
 *    /docs), this keeps the docs theme in sync with the app.
 * 2. localStorage("theme") — the docs site's own key (standalone visits).
 * 3. system / missing → prefers-color-scheme.
 *
 * Must stay in <head> and in sync with components/ThemeToggle.tsx (which
 * writes BOTH keys).
 */
const THEME_SCRIPT = `(function () {
  try {
    var theme = localStorage.getItem("appearance") || localStorage.getItem("theme");
    if (theme !== "light" && theme !== "dark" && theme !== "system") theme = null;
    var dark =
      theme === "dark" ||
      ((theme === null || theme === "system") &&
        window.matchMedia("(prefers-color-scheme: dark)").matches);
    document.documentElement.classList.toggle("dark", dark);
  } catch (e) {}
})();`;

export default function ThemeScript() {
  return <script dangerouslySetInnerHTML={{ __html: THEME_SCRIPT }} />;
}
