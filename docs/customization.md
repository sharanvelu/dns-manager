# DNS Manager — Environment Variable Customization Report

This report documents every environment variable the application reads, one entry per variable: what it does, its default, the values it accepts, and how each value changes the application's behavior.

**How defaults work.** Laravel resolves each variable as `env('NAME', default)` inside `config/*.php`. The *config default* applies when the variable is absent from the environment entirely. `.env.example` sometimes ships a different value than the config default — both are listed where they differ. In the production container the entrypoint runs `php artisan config:cache` at start, so **env changes require a container restart** to take effect.

**Sections:**

1. [Application identity & HTTP](#1-application-identity--http)
2. [Sync scheduler & automation hooks](#2-sync-scheduler--automation-hooks) *(DNS Manager–specific)*
3. [Authentication (OIDC)](#3-authentication-oidc)
4. [Database](#4-database)
5. [Redis](#5-redis)
6. [Queue](#6-queue)
7. [Cache](#7-cache)
8. [Session](#8-session)
9. [Logging](#9-logging)
10. [Container / deployment (Docker & Kubernetes)](#10-container--deployment-docker--kubernetes)
11. [Locale, maintenance & framework plumbing](#11-locale-maintenance--framework-plumbing)
12. [Mail (present but effectively unused)](#12-mail-present-but-effectively-unused)
13. [Optional / vestigial driver variables](#13-optional--vestigial-driver-variables)

---

## 1. Application identity & HTTP

### `APP_NAME`

- **Description:** Human-readable application name. Used in the browser title, the session cookie name (slugged, e.g. `dns_manager_session`), the cache key prefix, the Redis key prefix, and the mail from-name. Also re-exported to the frontend build as `VITE_APP_NAME`.
- **Default:** `Laravel` (config default) · `.env.example` ships `"DNS Manager"`.
- **Possible values:** Any string.
- **Impact:** Cosmetic in the UI, but **changing it also changes the derived session cookie name and cache/Redis prefixes** (unless those are pinned explicitly via `CACHE_PREFIX` / `REDIS_PREFIX` / `SESSION_COOKIE`). Renaming in production therefore logs every user out and orphans cached data until it expires.

### `APP_ENV`

- **Description:** Declares which environment the app believes it is running in.
- **Default:** `production` (config default) · `.env.example` ships `local`.
- **Possible values:** `local`, `production`, `testing` (any string is accepted; these three are meaningful).
- **Impact:**
  - `local` — development conveniences are enabled; some packages register dev-only service providers.
  - `production` — Laravel warns before destructive artisan commands (e.g. `migrate:fresh` requires `--force`), disables dev tooling.
  - `testing` — used by the Pest suite (set via `phpunit.xml`, not `.env`), swaps in the sqlite `:memory:` database and array drivers.

### `APP_KEY`

- **Description:** The application encryption key (32 bytes, `base64:`-prefixed). Encrypts sessions, cookies, and — critically for this app — the **provider credentials**, which are stored with an `encrypted:array` cast on `providers.config`.
- **Default:** none — the app will not boot meaningfully without it. Generate with `php artisan key:generate --show`.
- **Possible values:** `base64:<44-char base64>` string.
- **Impact:** **Losing or rotating this key makes every stored provider credential (Cloudflare tokens, Pi-hole passwords) permanently undecryptable** — every provider would need to be re-entered. Treat it as the most precious secret in the deployment; on Kubernetes it lives in the Secret. Use `APP_PREVIOUS_KEYS` when rotating.

### `APP_PREVIOUS_KEYS`

- **Description:** Comma-separated list of previous `APP_KEY` values used for graceful key rotation — data encrypted under an old key is still readable and is re-encrypted on write.
- **Default:** empty (no previous keys).
- **Possible values:** Comma-separated `base64:...` keys.
- **Impact:** Set this to the old key when rotating `APP_KEY` so provider configs and sessions survive the rotation; leave empty otherwise.

### `APP_DEBUG`

- **Description:** Toggles detailed error pages with stack traces.
- **Default:** `false` (config default) · `.env.example` ships `true`.
- **Possible values:** `true` / `false`.
- **Impact:**
  - `true` — full stack traces, environment dumps, and query details on error pages. **Never in production**: error pages could leak configuration (though provider secrets stay encrypted at rest, request context may expose sensitive values).
  - `false` — generic 500 pages; details go to the log channel only.

### `APP_URL`

- **Description:** Canonical base URL of the deployment. Used to generate absolute URLs (assets, redirects, the OIDC callback default, `storage` disk URLs).
- **Default:** `http://localhost`.
- **Possible values:** Any absolute URL, e.g. `https://dns.example.com`. Must match the ingress host on Kubernetes.
- **Impact:** A wrong value produces broken asset links and a broken OIDC redirect (the default `OIDC_REDIRECT_URI` is derived from it: `${APP_URL}/auth/callback`). Since v-`Added force HTTPS`, scheme handling behind TLS-terminating proxies is done by `FORCE_HTTPS`, not by this value alone.

### `FORCE_HTTPS`

- **Description:** Forces every generated URL (assets, redirects, and — the original motivating bug — **pagination links**) to use the `https` scheme via `URL::forceScheme('https')` in `AppServiceProvider`.
- **Default:** `false`.
- **Possible values:** `true` / `false`.
- **Impact:**
  - `true` — use when TLS terminates at a proxy/ingress in front of the app (the pod itself is reached over plain HTTP, port 8080). Prevents mixed-content errors and `http://` pagination links. The Kubernetes ConfigMap sets `"true"`.
  - `false` — URLs use the scheme of the incoming request. Correct for local dev or when the app itself serves TLS.
  - Covered by `tests/Feature/ForceHttpsTest.php`.

### `DOCS_SITE_URL`

- **Description:** Public URL of the hosted latest-version documentation site (`docs-site/`, the Next.js build). Linked from the banner on the in-app `/docs` pages ("docs for installed version vX; latest at …").
- **Default:** `https://dns-manager-docs.example.com` (config placeholder) · `.env.example` ships empty.
- **Possible values:** Any URL, or empty.
- **Impact:** Only affects the banner link on in-app docs pages (`resources/views/docs/show.blade.php`). Wrong/empty value degrades to a dead or placeholder link; nothing functional breaks.

### `BCRYPT_ROUNDS`

- **Description:** Cost factor for bcrypt hashing.
- **Default:** `12` (via `.env.example`; framework default `12`).
- **Possible values:** Integer, typically 10–14.
- **Impact:** Minimal here — authentication is OIDC-only (no local passwords), so this mainly affects any hashed values the framework creates internally. Higher = slower hashing, more brute-force resistance.

### `PHP_CLI_SERVER_WORKERS`

- **Description:** Number of workers for PHP's built-in dev server (`php artisan serve`, part of `composer run dev`).
- **Default:** `4` (`.env.example`).
- **Possible values:** Positive integer.
- **Impact:** Local development request concurrency only. Irrelevant in the production image (nginx + php-fpm).

### `VITE_APP_NAME`

- **Description:** App name exposed to the frontend bundle at **build time** (Vite inlines it).
- **Default:** `"${APP_NAME}"` (interpolated from `APP_NAME`).
- **Possible values:** Any string.
- **Impact:** Display only. Because Vite bakes it in at `npm run build`, changing it at runtime has no effect — it must be set when the image/assets are built.

---

## 2. Sync scheduler & automation hooks

These are the DNS Manager–specific variables (`config/dns.php`). They control the two background checks — the **drift check** (`dns:check-drift`: lists remote records, compares against the DB, marks entries `synced`/`drifted`) and the **provider health check** (`dns:check-provider-health`: runs each connector's connection test and refreshes the provider health badge) — plus the webhook endpoints that let external tools trigger them.

### `SCHEDULER_ENABLED`

- **Description:** Master switch for the built-in schedule registered in `routes/console.php` (executed by `php artisan schedule:work`). Governs **both** the drift-check and the provider-health-check schedules.
- **Default:** `true`.
- **Possible values:** `true` / `false`.
- **Impact:**
  - `true` — the scheduler queues `dns:check-drift` on `DRIFT_CHECK_CRON` and `dns:check-provider-health` on `PROVIDER_HEALTH_CHECK_CRON` (each guarded by `withoutOverlapping()` + `onOneServer()`).
  - `false` — **no built-in checks run at all.** Use when an external system drives checks via the `/api/hooks/*` webhooks, or when using the Kubernetes-native `cronjob.yaml` alternative. Manual checks from the UI still work (they go through the queue worker, not the scheduler).

### `DRIFT_CHECK_CRON`

- **Description:** Cron expression for the built-in drift-check schedule.
- **Default:** `*/15 * * * *` (every 15 minutes).
- **Possible values:** Any valid 5-field cron expression.
- **Impact:** Sets how quickly out-of-band changes at a provider are detected and flagged as `drifted`. Shorter intervals mean fresher drift status but more API calls to every enabled provider (each run lists **all** remote records per provider — mind Cloudflare rate limits with many providers). An invalid expression throws when the schedule is evaluated, breaking the scheduler process. Only consulted while `SCHEDULER_ENABLED=true`.

### `PROVIDER_HEALTH_CHECK_ENABLED`

- **Description:** Toggles just the provider health-check schedule (the drift check is unaffected).
- **Default:** `true`.
- **Possible values:** `true` / `false`.
- **Impact:**
  - `true` — every enabled provider's connectivity is tested on `PROVIDER_HEALTH_CHECK_CRON`, keeping the dashboard/providers health badge current between drift checks. Each run writes a `provider-health-check` row to `sync_logs` (visible in the dashboard activity feed) and updates `health_status` / `health_message` / `last_checked_at`.
  - `false` — the health badge is only refreshed as a side effect of drift checks (every 15 minutes by default) and manual "Test connection" runs. Useful to halve background API traffic to providers.
  - Has no effect while `SCHEDULER_ENABLED=false` (the master switch already disables it).

### `PROVIDER_HEALTH_CHECK_CRON`

- **Description:** Cron expression for the built-in provider health-check schedule.
- **Default:** `*/5 * * * *` (every 5 minutes).
- **Possible values:** Any valid 5-field cron expression.
- **Impact:** Controls health-badge freshness vs. provider API load. The check is lightweight (Cloudflare: one zone lookup; Pi-hole: auth + version read) so a short interval is normally safe. Only consulted while both `SCHEDULER_ENABLED` and `PROVIDER_HEALTH_CHECK_ENABLED` are true.

### `HOOKS_TRIGGER_TOKEN`

- **Description:** Static bearer token guarding the external automation webhooks `POST /api/hooks/drift-check` and `POST /api/hooks/provider-health-check` (no session, no CSRF — built for N8N/cron/CI). Both endpoints queue the same jobs as the scheduler and accept an optional `provider_id` body parameter to target one provider. Enforced by the `AuthenticateTriggerToken` middleware.
- **Default:** unset.
- **Possible values:** Empty/unset, or any sufficiently random secret string.
- **Impact:**
  - **Unset** — both endpoints are disabled and return **404** (they don't reveal their existence).
  - **Set** — requests with header `Authorization: Bearer <token>` are accepted; anything else gets **401**. Comparison is constant-time (`hash_equals`).
  - Anyone holding the token can make the app enumerate and poll all providers on demand — treat it as a secret (Kubernetes: it belongs in the Secret, not the ConfigMap).

---

## 3. Authentication (OIDC)

Sign-in is OIDC-only; there is no local registration. Users are auto-provisioned on first login (matched by OIDC subject, then email).

### `OIDC_BASE_URL`

- **Description:** Issuer base URL of the identity provider — the part before `/.well-known/openid-configuration` (e.g. `https://sso.example.com/realms/homelab` for Keycloak).
- **Default:** unset.
- **Possible values:** Any issuer URL.
- **Impact:** Required for login. Unset/wrong ⇒ nobody can sign in; the whole UI is unreachable (all app routes sit behind auth).

### `OIDC_CLIENT_ID` / `OIDC_CLIENT_SECRET`

- **Description:** Credentials of the OAuth client registered with the identity provider.
- **Default:** unset.
- **Possible values:** Strings issued by the IdP.
- **Impact:** Required for the OIDC code exchange. Wrong values surface as an IdP error during the redirect/callback. The secret belongs in the Kubernetes Secret.

### `OIDC_REDIRECT_URI`

- **Description:** The callback URL the IdP redirects back to after login.
- **Default:** `"${APP_URL}/auth/callback"` (derived in `.env.example`).
- **Possible values:** Absolute URL; must be registered verbatim with the IdP.
- **Impact:** Mismatch between this value, the IdP's registered redirect URIs, and the real public URL is the classic OIDC failure mode (`redirect_uri_mismatch`). Behind TLS-terminating proxies make sure it is the **https** URL.

### `OIDC_PROVIDER_LABEL`

- **Description:** Text on the sign-in button: "Sign in with `<label>`".
- **Default:** `SSO`.
- **Possible values:** Any short string (e.g. `Authentik`, `Keycloak`).
- **Impact:** Cosmetic only (login page prop `providerLabel`).

### `OIDC_VERIFY_JWT`

- **Description:** Whether the OIDC socialite provider verifies the ID token's JWT signature.
- **Default:** `false`.
- **Possible values:** `true` / `false`.
- **Impact:** `false` relies on the token endpoint's TLS-protected response (standard for confidential code-flow clients). `true` adds signature verification against the IdP's JWKS — stricter, but requires the IdP's JWKS endpoint to be reachable from the pod.

### `AUTH_GUARD` / `AUTH_PASSWORD_BROKER` / `AUTH_MODEL` / `AUTH_PASSWORD_RESET_TOKEN_TABLE` / `AUTH_PASSWORD_TIMEOUT`

- **Description:** Framework auth plumbing: default guard (`web`), password broker (`users`), user model class, reset-token table name, and password-confirmation timeout in seconds (`10800`).
- **Default:** As listed; none appear in `.env.example`.
- **Possible values:** Framework-specific identifiers.
- **Impact:** None worth customizing in this app — auth is session + OIDC and there are no password-reset flows. Changing `AUTH_MODEL` or the guard would break the OIDC controller. Leave untouched.

---

## 4. Database

PostgreSQL is the system of record — DNS entries, providers (with encrypted credentials), sync states, sync logs, users, sessions. **Data types in migrations are Postgres-oriented; only Postgres 16 is supported in production** (tests run sqlite `:memory:`).

### `DB_CONNECTION`

- **Description:** Which connection block in `config/database.php` to use.
- **Default:** `sqlite` (config default) · `.env.example` and all deployment manifests ship `pgsql`.
- **Possible values:** `pgsql`, `sqlite`, `mysql`, `mariadb`, `sqlsrv`.
- **Impact:** Use `pgsql`. `sqlite` works for quick experiments and is what the test suite uses (in memory), but SQL portability is only guaranteed for the code paths the tests cover. `mysql`/`mariadb`/`sqlsrv` blocks exist in the stock config but are unsupported/untested for this app.

### `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD`

- **Description:** PostgreSQL connection coordinates.
- **Defaults:** `127.0.0.1` / `5432` (pgsql block) / `laravel` / `root` / empty. `.env.example` ships `127.0.0.1` / `5432` / `dns_manager` / `dns_manager` / `secret`; the k8s ConfigMap points at an in-cluster service.
- **Possible values:** Hostname/IP, port, identifiers, secret.
- **Impact:** Wrong values ⇒ the app cannot boot usefully: migrations, sessions (database driver), and every page fail. On Kubernetes, username/password live in the Secret; host/port/database in the ConfigMap.

### `DB_URL`

- **Description:** Single-string DSN alternative (`postgresql://user:pass@host:5432/db`), overriding the individual `DB_*` parts.
- **Default:** unset.
- **Possible values:** A valid database URL.
- **Impact:** Convenience for platforms that hand out one URL. If set, the individual vars are ignored.

### `DB_CHARSET`

- **Description:** Client charset for the connection.
- **Default:** `utf8` (pgsql block).
- **Possible values:** Any Postgres-supported charset.
- **Impact:** Leave at default; DNS names and comments are ASCII/UTF-8.

---

## 5. Redis

Redis backs the **queue** (and the cache in the recommended production setup). All pushes/deletes/drift/health checks flow through Redis-queued jobs.

### `REDIS_CLIENT`

- **Description:** PHP Redis client implementation.
- **Default:** `phpredis` (config default) · `.env.example` and production ship **`predis`**.
- **Possible values:** `predis`, `phpredis`.
- **Impact:** `predis` is a pure-PHP client and is what this project standardizes on (no PHP extension needed in the image). `phpredis` requires the compiled extension — only use it if you rebuild the image with the extension installed.

### `REDIS_HOST` / `REDIS_PORT` / `REDIS_USERNAME` / `REDIS_PASSWORD`

- **Description:** Redis connection coordinates.
- **Defaults:** `127.0.0.1` / `6379` / unset / unset (`.env.example` writes `null` — i.e. no auth).
- **Possible values:** Hostname/IP, port, ACL username (Redis 6+), secret.
- **Impact:** If Redis is unreachable: queued jobs are never processed (entries stay "Pending" forever), and with `CACHE_STORE=redis` the scheduler's `withoutOverlapping` locks fail too. Password belongs in the k8s Secret.

### `REDIS_URL`

- **Description:** Single-DSN alternative (`redis://user:pass@host:6379/0`), overrides the individual parts.
- **Default:** unset.
- **Impact:** Same as `DB_URL` but for Redis.

### `REDIS_DB` / `REDIS_CACHE_DB`

- **Description:** Redis database index used by the `default` connection (queue) and the `cache` connection respectively.
- **Defaults:** `0` and `1`.
- **Possible values:** Integer database index.
- **Impact:** Keeps queue and cache keys in separate logical DBs. Point them at different indexes if you share one Redis instance across apps and want clean `FLUSHDB` boundaries; the user's homelab shares an existing Redis pod, so the `REDIS_PREFIX` below also matters.

### `REDIS_PREFIX`

- **Description:** Key prefix for all Redis keys.
- **Default:** `<slug(APP_NAME)>_database_` → `dns_manager_database_`.
- **Possible values:** Any string.
- **Impact:** Prevents key collisions on a shared Redis instance. Changing `APP_NAME` implicitly changes this unless pinned.

### `REDIS_CLUSTER` / `REDIS_PERSISTENT`

- **Description:** Cluster mode key (`redis` = client-side clustering) and persistent-connection toggle.
- **Defaults:** `redis` / `false`.
- **Impact:** Irrelevant for a single Redis pod. `REDIS_PERSISTENT=true` can reduce connection churn under phpredis; with predis it is generally a no-op concern.

---

## 6. Queue

### `QUEUE_CONNECTION`

- **Description:** Which queue backend processes the app's jobs (`SyncEntryToProvider`, `DeleteEntryFromProvider`, `CheckProviderDrift`, `CheckProviderHealth`).
- **Default:** `database` (config default) · `.env.example` and production ship **`redis`**.
- **Possible values:** `redis`, `database`, `sync`, `beanstalkd`, `sqs`, `null`.
- **Impact:**
  - `redis` — recommended; jobs are processed by `php artisan queue:work redis` (the supervisor's worker role).
  - `database` — works without Redis; jobs go to the `jobs` table. Slower polling, more DB load.
  - `sync` — jobs run **inline in the web request**. Saving an entry blocks until every provider push finishes; acceptable only for tiny experiments.
  - `null` — jobs are discarded silently: **nothing is ever pushed to any provider.** Never use.
  - `beanstalkd` / `sqs` — stock config exists but is unprovisioned/untested here.

### `REDIS_QUEUE_CONNECTION` / `REDIS_QUEUE` / `REDIS_QUEUE_RETRY_AFTER`

- **Description:** Redis connection name (`default`), queue name (`default`), and the seconds after which a reserved-but-unfinished job is assumed dead and retried (`90`).
- **Possible values:** Connection/queue names; positive integer seconds.
- **Impact:** `RETRY_AFTER` matters if a provider API hangs: a job stuck longer than this is released and retried, so it must exceed your slowest realistic provider call. The drift/health jobs set `$tries = 1`, so they are not re-attempted on failure — only on timeout-release.

### `DB_QUEUE_CONNECTION` / `DB_QUEUE_TABLE` / `DB_QUEUE` / `DB_QUEUE_RETRY_AFTER`

- **Description:** Equivalents for the `database` queue driver (table `jobs`, queue `default`, retry `90`).
- **Impact:** Only consulted when `QUEUE_CONNECTION=database`.

### `QUEUE_FAILED_DRIVER`

- **Description:** Where permanently failed jobs are recorded.
- **Default:** `database-uuids` (the `failed_jobs` table).
- **Possible values:** `database-uuids`, `database`, `null`.
- **Impact:** Keep the default — `null` silently discards failed jobs, losing the forensic trail (`php artisan queue:failed` / `queue:retry`). Sync failures also land in `sync_logs` either way.

---

## 7. Cache

### `CACHE_STORE`

- **Description:** Default cache backend. Beyond ordinary caching, this backs the **scheduler's `withoutOverlapping()` and `onOneServer()` locks** for the drift/health check schedules.
- **Default:** `database` (config default and `.env.example`) · production ships **`redis`**.
- **Possible values:** `redis`, `database`, `file`, `array`, `memcached`, `dynamodb`, `null`.
- **Impact:**
  - `redis` — recommended in production; fast, shared across processes.
  - `database` — uses the `cache` / `cache_locks` tables; fine, slightly more DB traffic.
  - `file` — per-container filesystem; **breaks `onOneServer()`** semantics if you ever run more than one scheduler container (locks aren't shared).
  - `array` — in-memory per request; effectively no cache. Used in tests.
  - `null` — disables caching *and* the overlap locks; scheduled runs could stack up if one hangs.

### `CACHE_PREFIX`

- **Description:** Prefix for all cache keys.
- **Default:** `<slug(APP_NAME)>_cache_` → `dns_manager_cache_` · `.env.example` ships empty (= use derived default).
- **Impact:** Same collision-avoidance role as `REDIS_PREFIX` on shared infrastructure.

### `DB_CACHE_CONNECTION` / `DB_CACHE_TABLE` / `DB_CACHE_LOCK_CONNECTION` / `DB_CACHE_LOCK_TABLE`

- **Description:** Overrides for the `database` cache store: which DB connection/table hold cache entries and locks.
- **Defaults:** app default connection / `cache` / same / (`cache_locks`).
- **Impact:** Only relevant with `CACHE_STORE=database`; defaults match the shipped migrations — leave alone.

### `REDIS_CACHE_CONNECTION` / `REDIS_CACHE_LOCK_CONNECTION`

- **Description:** Which Redis connections the redis cache store uses for values (`cache` connection → `REDIS_CACHE_DB`) and locks (`default`).
- **Impact:** Only relevant with `CACHE_STORE=redis`; defaults are correct.

---

## 8. Session

Sessions are server-side; the browser holds only the session cookie. All app pages require an authenticated session (OIDC).

### `SESSION_DRIVER`

- **Description:** Where session payloads are stored.
- **Default:** `database` (config default, `.env.example`, and production).
- **Possible values:** `database`, `redis`, `file`, `cookie`, `array`.
- **Impact:**
  - `database` — the `sessions` table; survives container restarts, works multi-replica. Recommended (and what the k8s ConfigMap sets).
  - `redis` — also fine; sessions then live in Redis and vanish if Redis is flushed.
  - `file` — per-container; logins don't survive container replacement and don't share across replicas.
  - `cookie` — entire session in the (encrypted) cookie; size-limited.
  - `array` — sessions dropped after each request (tests only); nobody can stay logged in.

### `SESSION_LIFETIME`

- **Description:** Idle lifetime of a session in minutes.
- **Default:** `120`.
- **Possible values:** Positive integer minutes.
- **Impact:** How long users stay signed in without activity before being bounced back to the OIDC login. Increase for convenience, decrease for stricter security.

### `SESSION_EXPIRE_ON_CLOSE`

- **Description:** Expire the session when the browser closes.
- **Default:** `false`.
- **Impact:** `true` makes the cookie a browser-session cookie regardless of `SESSION_LIFETIME`.

### `SESSION_ENCRYPT`

- **Description:** Encrypt session payloads at rest (in the `sessions` table) with `APP_KEY`.
- **Default:** `false`.
- **Impact:** `true` adds defense-in-depth if DB dumps might leak; slight CPU overhead. Session payloads here contain user identity, not provider secrets.

### `SESSION_PATH` / `SESSION_DOMAIN`

- **Description:** Cookie path and domain attributes.
- **Defaults:** `/` and unset (current host only).
- **Impact:** Only change when serving under a subpath or sharing the cookie across subdomains. A wrong `SESSION_DOMAIN` silently breaks login (cookie never returned).

### `SESSION_SECURE_COOKIE`

- **Description:** Marks the session cookie `Secure` (HTTPS-only).
- **Default:** unset (Laravel decides from the request scheme).
- **Possible values:** `true` / `false` / unset.
- **Impact:** Behind a TLS-terminating proxy with `FORCE_HTTPS=true`, setting `true` explicitly is a sensible hardening step; `true` on a plain-HTTP dev setup means the browser never sends the cookie ⇒ can't log in.

### `SESSION_HTTP_ONLY`

- **Description:** Marks the cookie `HttpOnly` (invisible to JavaScript).
- **Default:** `true`.
- **Impact:** Keep `true`; `false` exposes the session to XSS exfiltration for no benefit (Inertia does not need cookie access).

### `SESSION_SAME_SITE`

- **Description:** Cookie `SameSite` attribute.
- **Default:** `lax`.
- **Possible values:** `lax`, `strict`, `none`, `null`.
- **Impact:** `lax` is required for the OIDC redirect flow to carry the cookie on the top-level redirect back from the IdP. `strict` can break the OIDC callback (state mismatch); `none` requires `Secure` and weakens CSRF posture.

### `SESSION_PARTITIONED_COOKIE` / `SESSION_CONNECTION` / `SESSION_TABLE` / `SESSION_STORE` / `SESSION_COOKIE`

- **Description:** CHIPS partitioning (`false`), DB connection override, table name (`sessions`), cache-store override for cache-backed drivers, and cookie name (`<slug(APP_NAME)>_session`).
- **Impact:** Leave at defaults; `SESSION_COOKIE` is only worth pinning if you rename `APP_NAME` and want to keep existing logins.

---

## 9. Logging

### `LOG_CHANNEL`

- **Description:** Default log channel.
- **Default:** `stack` (config default and `.env.example`) · Kubernetes ships **`stderr`**.
- **Possible values:** `stack`, `single`, `daily`, `stderr`, `syslog`, `errorlog`, `slack`, `papertrail`, `null`.
- **Impact:**
  - `stderr` — logs to the container's stderr; correct for Docker/Kubernetes (picked up by `kubectl logs`).
  - `single` / `daily` — file(s) in `storage/logs`; in an ephemeral container these vanish with the pod unless the optional PVC is mounted.
  - `stack` — fans out to the channels in `LOG_STACK`.
  - `null` — discards everything, including provider API failures you will want when debugging sync issues.

### `LOG_LEVEL`

- **Description:** Minimum severity that gets logged.
- **Default:** `debug` (config default and `.env.example`) · Kubernetes ships `info`.
- **Possible values:** `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency`.
- **Impact:** `debug` is verbose (includes query-level noise in dev); `info` is the sane production floor; `error` or above will hide the connector warnings that explain *why* a provider went unhealthy — note the health badge message and `sync_logs` still capture the error message regardless of log level.

### `LOG_STACK`

- **Description:** Comma-separated channels the `stack` channel writes to.
- **Default:** `single`.
- **Impact:** Only used when `LOG_CHANNEL=stack`. E.g. `single,stderr` writes both a file and container output.

### `LOG_DEPRECATIONS_CHANNEL` / `LOG_DEPRECATIONS_TRACE`

- **Description:** Where PHP/Laravel deprecation notices go and whether to include traces.
- **Defaults:** `null` (discard) / `false`.
- **Impact:** Enable temporarily when preparing framework upgrades; otherwise noise.

### `LOG_DAILY_DAYS`

- **Description:** Retention (days) for the `daily` channel.
- **Default:** `14`.
- **Impact:** Only with `LOG_CHANNEL=daily`; disk-usage control.

### `LOG_SLACK_WEBHOOK_URL` / `LOG_SLACK_USERNAME` / `LOG_SLACK_EMOJI`

- **Description:** Slack incoming-webhook alerting channel (level defaults to `critical`).
- **Defaults:** unset / `Laravel Log` / `:boom:`.
- **Impact:** Optional: set the webhook and add `slack` to `LOG_STACK` to get pinged on critical failures. Unset = channel inert.

### `LOG_STDERR_FORMATTER` / `LOG_SYSLOG_FACILITY` / `LOG_PAPERTRAIL_HANDLER` / `PAPERTRAIL_URL` / `PAPERTRAIL_PORT`

- **Description:** Formatter class for `stderr` (e.g. a JSON formatter for log aggregators), syslog facility (`LOG_USER`), and Papertrail shipping config.
- **Defaults:** unset / `LOG_USER` / `SyslogUdpHandler` / unset / unset.
- **Impact:** Only relevant if you adopt those channels; unset values leave them inert.

---

## 10. Container / deployment (Docker & Kubernetes)

These are consumed by `docker/entrypoint.sh` and `docker/supervisord.conf`, not by Laravel config. The image runs nginx on **port 8080** as user **82:82 (non-root)**; the entrypoint rebuilds config/view caches at container start (so env changes need a restart, not an image rebuild).

### `AUTO_MIGRATE`

- **Description:** Run `php artisan migrate --force` during container startup.
- **Default:** `false` (entrypoint default) · the k8s ConfigMap sets `"true"`.
- **Possible values:** `true` / `false` (string compare against `true`).
- **Impact:**
  - `true` — the container migrates the database before serving. This is the intended mode for the single-pod architecture (`replicas: 1`, `Recreate` strategy) and single-container Docker setups.
  - `false` — you must run migrations yourself (`php artisan migrate --force`). Required stance if you ever scale to multiple replicas, where concurrent start-time migrations could race.

### `SUPERVISOR_WORKER`

- **Description:** Whether the in-container supervisor also runs the queue worker (`php artisan queue:work redis ...`).
- **Default:** `true`.
- **Possible values:** `true` / `false`.
- **Impact:**
  - `true` — self-contained container: web + worker (+ scheduler). The default single-pod story.
  - `false` — the container serves HTTP only; **you must run a dedicated worker** (separate Deployment with a command override) or nothing is ever pushed to/deleted from providers — entries sit at "Pending" forever.

### `SUPERVISOR_SCHEDULER`

- **Description:** Whether the in-container supervisor runs the schedule worker (`php artisan schedule:work`).
- **Default:** `true`.
- **Possible values:** `true` / `false`.
- **Impact:**
  - `true` — the container evaluates the schedule (which then respects `SCHEDULER_ENABLED` etc.).
  - `false` — no schedule process at all; pair with a dedicated scheduler Deployment or the k8s-native `cronjob.yaml`. Run **at most one** scheduler across the whole installation (the `onOneServer()` cache lock is a safety net, not the primary control).
- **Relationship to `SCHEDULER_ENABLED`:** `SUPERVISOR_SCHEDULER` controls whether the *process* runs; `SCHEDULER_ENABLED` controls whether the process has *anything registered to run*. Either being false stops built-in checks.

---

## 11. Locale, maintenance & framework plumbing

### `APP_TIMEZONE`

- **Description:** PHP/application timezone.
- **Default:** `UTC`.
- **Possible values:** Any PHP timezone identifier (`Europe/Berlin`, …).
- **Impact:** Affects timestamps in logs and how cron expressions for the drift/health schedules are interpreted, plus `last_checked_at` display formatting. UTC is the safe default; if you set a local zone, remember the cron expressions now fire in that zone.

### `APP_LOCALE` / `APP_FALLBACK_LOCALE` / `APP_FAKER_LOCALE`

- **Description:** UI translation locale, its fallback, and the locale used by Faker in database seeding/tests.
- **Defaults:** `en` / `en` / `en_US`.
- **Impact:** The app ships English strings only, so changing the first two has no visible effect; `APP_FAKER_LOCALE` only flavors seeded fake data.

### `APP_MAINTENANCE_DRIVER` / `APP_MAINTENANCE_STORE`

- **Description:** How `php artisan down` maintenance mode is tracked: `file` (per container) or `cache` (shared), and which cache store when using `cache`.
- **Defaults:** `file` / `database`.
- **Impact:** With multiple containers, `file` only puts one container into maintenance; use `cache` to make `artisan down` cluster-wide. Irrelevant in the default single-pod layout.

### `BROADCAST_CONNECTION`

- **Description:** Event broadcasting backend.
- **Default:** `log` (`.env.example`).
- **Impact:** The app does not broadcast events (no websockets); the value is inert. Leave as `log` or `null`.

### `FILESYSTEM_DISK`

- **Description:** Default filesystem disk.
- **Default:** `local` (`storage/app`).
- **Possible values:** `local`, `public`, `s3`.
- **Impact:** The app stores essentially nothing on disk in normal operation (the optional k8s PVC exists for `storage/app` but is excluded by default — the app is stateless). `s3` would additionally require the `AWS_*` variables.

---

## 12. Mail (present but effectively unused)

The application currently sends no mail (no password resets — auth is OIDC; no notifications). These exist because the stock Laravel config ships them; they only matter if you add mail features.

### `MAIL_MAILER`

- **Description:** Mail transport.
- **Default:** `log` (mails are written to the log instead of sent).
- **Possible values:** `log`, `smtp`, `sendmail`, `postmark`, `resend`, `failover`, `roundrobin`, `array`.
- **Impact:** With no mail-sending code, any value is inert today. Keep `log` so future accidental mail ends up visible in logs rather than lost.

### `MAIL_HOST` / `MAIL_PORT` / `MAIL_USERNAME` / `MAIL_PASSWORD` / `MAIL_ENCRYPTION` / `MAIL_URL` / `MAIL_EHLO_DOMAIN`

- **Description:** SMTP coordinates (host `127.0.0.1`, port `2525`, no auth, TLS default `tls`), optional single-DSN override, and EHLO domain (defaults to the `APP_URL` host).
- **Impact:** Only consulted when `MAIL_MAILER=smtp`.

### `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME`

- **Description:** Global from-address/name (`hello@example.com` / `${APP_NAME}`).
- **Impact:** Cosmetic on any future outgoing mail.

### `MAIL_SENDMAIL_PATH` / `MAIL_LOG_CHANNEL` / `POSTMARK_TOKEN` / `POSTMARK_MESSAGE_STREAM_ID` / `RESEND_KEY`

- **Description:** Sendmail binary path, log channel for the `log` mailer, and Postmark/Resend API credentials.
- **Impact:** Inert unless you switch `MAIL_MAILER` to the matching transport.

---

## 13. Optional / vestigial driver variables

Stock Laravel config references these; **none are used by DNS Manager's supported configuration**. They are listed for completeness so an audit of `config/*.php` finds no undocumented variable.

| Variable | Default | Consulted only when… | Impact if set |
| --- | --- | --- | --- |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` | unset | `FILESYSTEM_DISK=s3`, `QUEUE_CONNECTION=sqs`, or `CACHE_STORE=dynamodb` | Credentials for the respective AWS service. |
| `AWS_DEFAULT_REGION` | `us-east-1` | same as above | AWS region selection. |
| `AWS_BUCKET` / `AWS_URL` / `AWS_ENDPOINT` / `AWS_USE_PATH_STYLE_ENDPOINT` (`false`) | unset | `FILESYSTEM_DISK=s3` | S3 bucket/endpoint config; path-style is for MinIO-like endpoints. |
| `SQS_PREFIX` / `SQS_QUEUE` (`default`) / `SQS_SUFFIX` | placeholder / `default` / unset | `QUEUE_CONNECTION=sqs` | SQS queue addressing. |
| `BEANSTALKD_QUEUE_HOST` (`localhost`) / `BEANSTALKD_QUEUE` (`default`) / `BEANSTALKD_QUEUE_RETRY_AFTER` (`90`) | as listed | `QUEUE_CONNECTION=beanstalkd` | Beanstalkd queue addressing/retry. |
| `MEMCACHED_HOST` (`127.0.0.1`) / `MEMCACHED_PORT` (`11211`) / `MEMCACHED_USERNAME` / `MEMCACHED_PASSWORD` / `MEMCACHED_PERSISTENT_ID` | as listed | `CACHE_STORE=memcached` | Memcached connection (extension not in the image). |
| `DYNAMODB_CACHE_TABLE` (`cache`) / `DYNAMODB_ENDPOINT` | as listed | `CACHE_STORE=dynamodb` | DynamoDB cache table/endpoint. |
| `SLACK_BOT_USER_OAUTH_TOKEN` / `SLACK_BOT_USER_DEFAULT_CHANNEL` | unset | Slack notifications were added to the app | Slack bot credentials (distinct from the `slack` *log* channel, which uses `LOG_SLACK_WEBHOOK_URL`). |
| `DB_FOREIGN_KEYS` (`true`) | `true` | `DB_CONNECTION=sqlite` | Disables FK enforcement on sqlite — never do this; tests rely on FKs. |
| `DB_SOCKET` / `DB_COLLATION` / `MYSQL_ATTR_SSL_CA` | unset | `DB_CONNECTION=mysql|mariadb` | MySQL-only connection options (unsupported DB). |

---

## Quick-reference: a sensible production baseline

```dotenv
APP_NAME="DNS Manager"
APP_ENV=production
APP_KEY=base64:...            # guard with your life — encrypts provider credentials
APP_DEBUG=false
APP_URL=https://dns.example.com
FORCE_HTTPS=true              # TLS terminates at the ingress

LOG_CHANNEL=stderr
LOG_LEVEL=info

DB_CONNECTION=pgsql
DB_HOST=postgres.databases.svc.cluster.local
DB_PORT=5432
DB_DATABASE=dns_manager
DB_USERNAME=dns_manager
DB_PASSWORD=...

REDIS_CLIENT=predis
REDIS_HOST=redis.databases.svc.cluster.local
REDIS_PORT=6379
REDIS_PASSWORD=...

QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=database

OIDC_BASE_URL=https://sso.example.com/realms/homelab
OIDC_CLIENT_ID=dns-manager
OIDC_CLIENT_SECRET=...
OIDC_REDIRECT_URI=https://dns.example.com/auth/callback
OIDC_PROVIDER_LABEL=SSO

DOCS_SITE_URL=https://docs.dns.example.com

AUTO_MIGRATE=true             # single-pod architecture migrates at start

SCHEDULER_ENABLED=true
DRIFT_CHECK_CRON="*/15 * * * *"
PROVIDER_HEALTH_CHECK_ENABLED=true
PROVIDER_HEALTH_CHECK_CRON="*/5 * * * *"
HOOKS_TRIGGER_TOKEN=...       # only if external automation should trigger checks
```
