/**
 * Inline no-flash script — runs before first paint, applies `.dark` to
 * <html> from localStorage("theme") ∈ {light, dark, system} (system /
 * missing falls back to prefers-color-scheme). Must stay in <head> and in
 * sync with components/ThemeToggle.tsx.
 */
const THEME_SCRIPT = `(function () {
  try {
    var theme = localStorage.getItem("theme");
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
