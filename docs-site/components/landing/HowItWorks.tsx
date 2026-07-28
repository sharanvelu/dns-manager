import DocIcon from "@/components/DocIcon";

/**
 * The problem + the model, in three steps. Facts from the docs overview /
 * README / ARCHITECTURE.md — provider credentials are entered once,
 * attached per zone, entries push on save, drift checks run on schedule.
 */

const STEPS: Array<{ icon: string; title: string; body: string }> = [
  {
    icon: "providers",
    title: "Connect a provider once",
    body: "Enter Cloudflare, Pi-hole or Technitium credentials a single time — tested before you save, stored encrypted (AES-256), never sent back to the browser.",
  },
  {
    icon: "zones",
    title: "Create zones, attach providers",
    body: "Records are grouped by domain with zone-relative names. Attach a provider to the zones it should serve — Cloudflare Zone IDs are auto-discovered, and zoneless Pi-hole attaches to every zone automatically.",
  },
  {
    icon: "sync",
    title: "Save — the rest is automatic",
    body: "Every save queues a push to each targeted provider, with retries and backoff. A drift check runs every 15 minutes and flags records changed or removed behind the app's back; the database wins.",
  },
];

export default function HowItWorks() {
  return (
    <section className="relative mx-auto max-w-7xl px-4 py-20 sm:px-6 sm:py-24">
      <div className="mx-auto max-w-2xl text-center">
        <p className="mb-2 text-xs font-semibold uppercase tracking-[0.1em] text-accent">
          Why it exists
        </p>
        <h2 className="text-3xl font-semibold tracking-tight">
          DNS spread across providers drifts apart
        </h2>
        <p className="mt-4 text-balance text-[15px] leading-relaxed text-muted">
          Public records on Cloudflare, local names on Pi-hole, a self-hosted
          authoritative server on the side — the same hostnames maintained by
          hand in three dashboards. {""}
          DNS Manager makes its database the source of truth and keeps every
          provider in line.
        </p>
      </div>

      <ol className="mt-14 grid gap-4 sm:grid-cols-3">
        {STEPS.map((step, i) => (
          <li
            key={step.title}
            className="landing-step relative rounded-2xl border border-border bg-surface p-6"
          >
            <div className="flex items-center gap-3">
              <span className="gradient-mark flex size-9 items-center justify-center rounded-xl text-accent-fg shadow-sm">
                <DocIcon name={step.icon} size={17} />
              </span>
              <span className="text-xs font-semibold uppercase tracking-[0.08em] text-faint">
                Step {i + 1}
              </span>
            </div>
            <h3 className="mt-4 text-[15px] font-semibold tracking-tight">
              {step.title}
            </h3>
            <p className="mt-2 text-[13px] leading-relaxed text-muted">{step.body}</p>
          </li>
        ))}
      </ol>
    </section>
  );
}
