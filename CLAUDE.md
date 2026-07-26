# CLAUDE.md

Read **[AGENTS.md](AGENTS.md)** first — it is the canonical agent guide (commands, conventions, gotchas, docs contract). [ARCHITECTURE.md](ARCHITECTURE.md) covers system design; [DESIGN.md](DESIGN.md) covers UI conventions.

> ## ⚠️ Documentation sync rule
> `AGENTS.md`, `CLAUDE.md`, `ARCHITECTURE.md`, `DESIGN.md`, and `docs/content/*.md` MUST be updated in the same change set as any application change they describe. Never finish a task leaving these files stale. Bump `VERSION` on releases.

## Quick reference

```sh
composer run dev            # app + queue worker + vite
./vendor/bin/pest           # tests — HTTP is prevented from going to the network
./vendor/bin/pint --dirty   # PHP format before finishing
npx tsc --noEmit && npm run build   # frontend checks
```

- Laravel 12 + Inertia 2 + React 19 + TS + Tailwind 4; Postgres; Redis queue (predis).
- Secrets: `encrypted:array` cast on `providers.config` (credentials) AND `zone_providers.config` (per-zone settings) — never expose either raw in Inertia props.
- Tests run on sqlite `:memory:` → keep SQL portable (no `ilike`).
- Vite preloading is deliberately disabled (nginx 502 via oversized `Link` header) — see AGENTS.md gotchas before touching.
- User docs live in `docs/content/` and are served by BOTH the in-app `/docs` endpoint (installed version) and `docs-site/` (Next.js, latest version). Behavior changes ⇒ update the matching `docs/content` page.

## Claude-specific notes

- Prefer fanning out independent workstreams to subagents (≤12) with explicitly assigned file ownership; keep shared files (routes, sidebar, workflows) owned by the coordinator.
- When verifying, favor end-to-end evidence: run the suite, boot the app or the built image, and measure rather than assume (see the nginx-buffer and stray-HTTP incidents in AGENTS.md gotchas).
