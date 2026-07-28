# CLAUDE.md

Read **[AGENTS.md](AGENTS.md)** first — it is the canonical agent guide (commands, conventions, gotchas, docs contract). [ARCHITECTURE.md](ARCHITECTURE.md) covers system design; [DESIGN.md](DESIGN.md) covers UI conventions.

> ## ⚠️ Documentation sync rule
> `AGENTS.md`, `CLAUDE.md`, `ARCHITECTURE.md`, `DESIGN.md`, and the docs-site pages (`docs-site/app/docs/**/page.tsx` + `docs-site/lib/registry.ts`) MUST be updated in the same change set as any application change they describe. Never finish a task leaving these files stale. Bump `VERSION` on releases.

## Quick reference

```sh
composer run dev            # app + queue worker + vite
composer run ci             # everything CI gates on: PHP + JS style, tests, static analysis
composer run test           # Pest — HTTP is prevented from going to the network
composer run check-style    # Pint check-only (./vendor/bin/pint --dirty to format while iterating)
composer run analyze        # PHPStan (Larastan, level 5, baseline for pre-existing debt)
npm run test                # Vitest (resources/js/tests/)
npm run analyze && npm run build    # tsc --noEmit + Vite build
```

- Laravel 12 + Inertia 2 + React 19 + TS + Tailwind 4; Postgres; Redis queue (predis).
- Secrets: `encrypted:array` cast on `providers.config` (credentials) AND `zone_providers.config` (per-zone settings) — never expose either raw in Inertia props.
- Tests run on sqlite `:memory:` → keep SQL portable (no `ilike`).
- Vite preloading is deliberately disabled (nginx 502 via oversized `Link` header) — see AGENTS.md gotchas before touching.
- User docs live in `docs-site/` (Next.js; content authored as TSX pages under `app/docs/**` + `lib/registry.ts`). One build serves both deployments: baked into the Docker image at `/docs` (installed version) and Vercel (`/` landing + `/docs` latest). Behavior changes ⇒ update the matching docs-site page.

## Claude-specific notes

- Prefer fanning out independent workstreams to subagents (≤12) with explicitly assigned file ownership; keep shared files (routes, sidebar, workflows) owned by the coordinator.
- When verifying, favor end-to-end evidence: run the suite, boot the app or the built image, and measure rather than assume (see the nginx-buffer and stray-HTTP incidents in AGENTS.md gotchas).
