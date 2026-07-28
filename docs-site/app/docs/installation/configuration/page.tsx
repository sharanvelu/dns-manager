import Callout from "@/components/docs/Callout";
import CodeBlock from "@/components/docs/CodeBlock";
import DocArticle, { docMetadata } from "@/components/docs/DocArticle";
import { H2, H3 } from "@/components/docs/Heading";

export const metadata = docMetadata("installation", "configuration");

export default function Page() {
  return (
    <DocArticle group="installation" slug="configuration">
      <p>
        All configuration is environment variables. This page groups them by
        concern; the <a href="/docs/installation/docker/">Docker</a> and{" "}
        <a href="/docs/installation/kubernetes/">Kubernetes</a> pages show
        where each set goes.
      </p>

      <H2>Application</H2>

      <table>
        <thead>
          <tr>
            <th>Variable</th>
            <th>Purpose</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <code>APP_KEY</code>
            </td>
            <td>
              Laravel encryption key. Also encrypts provider credentials at
              rest — if you lose it, you must re-enter every provider&apos;s
              credentials. <strong>Back it up.</strong>
            </td>
          </tr>
          <tr>
            <td>
              <code>APP_URL</code>
            </td>
            <td>Public URL of the app (must match your ingress hostname).</td>
          </tr>
          <tr>
            <td>
              <code>FORCE_HTTPS</code>
            </td>
            <td>
              <code>true</code> generates every URL (assets, redirects) with
              the https scheme. Set it whenever TLS terminates in front of the
              app (Kubernetes ingress, reverse proxy) and the container itself
              is reached over plain http — otherwise browsers block the http
              asset URLs as mixed content. Defaults to <code>false</code>.
            </td>
          </tr>
          <tr>
            <td>
              <code>APP_ENV</code> / <code>APP_DEBUG</code>
            </td>
            <td>
              Set to <code>production</code> / <code>false</code> when
              deployed.
            </td>
          </tr>
        </tbody>
      </table>

      <Callout type="note">
        The app also trusts proxy <code>X-Forwarded-*</code> headers, so
        request-derived URLs (pagination links) come out https when the proxy
        sends <code>X-Forwarded-Proto: https</code> — no extra configuration
        needed.
      </Callout>

      <H2>Database</H2>

      <table>
        <thead>
          <tr>
            <th>Variable</th>
            <th>Purpose</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <code>DB_CONNECTION</code>
            </td>
            <td>
              <code>pgsql</code>
            </td>
          </tr>
          <tr>
            <td>
              <code>DB_HOST</code> / <code>DB_PORT</code> /{" "}
              <code>DB_DATABASE</code> / <code>DB_USERNAME</code> /{" "}
              <code>DB_PASSWORD</code>
            </td>
            <td>PostgreSQL 16 connection.</td>
          </tr>
        </tbody>
      </table>

      <H2>Redis &amp; queue</H2>

      <table>
        <thead>
          <tr>
            <th>Variable</th>
            <th>Purpose</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <code>REDIS_HOST</code> / <code>REDIS_PORT</code> /{" "}
              <code>REDIS_PASSWORD</code>
            </td>
            <td>Redis connection (queue backend).</td>
          </tr>
          <tr>
            <td>
              <code>QUEUE_CONNECTION</code>
            </td>
            <td>
              <code>redis</code> in production. All syncing runs through this
              queue.
            </td>
          </tr>
        </tbody>
      </table>

      <H2>Authentication (OIDC)</H2>

      <p>
        Covered in depth in{" "}
        <a href="/docs/authentication/setup/">
          Authentication → Provider setup
        </a>
        .
      </p>

      <table>
        <thead>
          <tr>
            <th>Variable</th>
            <th>Purpose</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <code>OIDC_BASE_URL</code>
            </td>
            <td>
              Issuer base URL — the part before{" "}
              <code>/.well-known/openid-configuration</code>.
            </td>
          </tr>
          <tr>
            <td>
              <code>OIDC_CLIENT_ID</code> / <code>OIDC_CLIENT_SECRET</code>
            </td>
            <td>
              OIDC client credentials registered with your identity provider.
            </td>
          </tr>
          <tr>
            <td>
              <code>OIDC_REDIRECT_URI</code>
            </td>
            <td>
              Callback URL; defaults to{" "}
              <code>{"${APP_URL}/auth/callback"}</code>. Register this URI with
              your identity provider.
            </td>
          </tr>
          <tr>
            <td>
              <code>OIDC_PROVIDER_LABEL</code>
            </td>
            <td>
              Text on the sign-in button, e.g. <code>Authentik</code> renders
              &quot;Sign in with Authentik&quot;. Defaults to <code>SSO</code>.
            </td>
          </tr>
          <tr>
            <td>
              <code>OIDC_VERIFY_JWT</code>
            </td>
            <td>
              Enables ID-token (JWT) signature verification in the OIDC
              client. Defaults to <code>false</code>.
            </td>
          </tr>
        </tbody>
      </table>

      <H2>Container roles</H2>

      <table>
        <thead>
          <tr>
            <th>Variable</th>
            <th>Purpose</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <code>AUTO_MIGRATE</code>
            </td>
            <td>
              <code>true</code> runs database migrations at container start.
              The Kubernetes ConfigMap sets it to <code>true</code>; do the
              same for single-container Docker setups. Defaults to{" "}
              <code>false</code>.
            </td>
          </tr>
          <tr>
            <td>
              <code>SUPERVISOR_WORKER</code> / <code>SUPERVISOR_SCHEDULER</code>
            </td>
            <td>
              Whether the container&apos;s supervisor also runs the queue
              worker / scheduler. Default <code>true</code> (self-contained
              container); only set <code>false</code> in the{" "}
              <a href="/docs/installation/docker/#scaling-out-advanced">
                advanced setup
              </a>{" "}
              that splits the roles into separate containers/Deployments with
              command overrides.
            </td>
          </tr>
        </tbody>
      </table>

      <H2>Scheduler</H2>

      <p>
        The built-in schedule queues the drift checker (
        <code>php artisan dns:check-drift</code>) and the provider health
        checker (<code>php artisan dns:check-provider-health</code>).
      </p>

      <table>
        <thead>
          <tr>
            <th>Variable</th>
            <th>Purpose</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <code>SCHEDULER_ENABLED</code>
            </td>
            <td>
              <code>false</code> disables the built-in schedules (drift check{" "}
              <strong>and</strong> provider health check) entirely — use when
              an external tool triggers checks via the webhooks instead.
              Defaults to <code>true</code>.
            </td>
          </tr>
          <tr>
            <td>
              <code>DRIFT_CHECK_CRON</code>
            </td>
            <td>
              Cron expression for the built-in drift-check schedule. Defaults
              to <code>*/15 * * * *</code> (every 15 minutes).
            </td>
          </tr>
          <tr>
            <td>
              <code>PROVIDER_HEALTH_CHECK_ENABLED</code>
            </td>
            <td>
              <code>false</code> disables just the built-in provider
              health-check schedule (drift checks keep running). Defaults to{" "}
              <code>true</code>.
            </td>
          </tr>
          <tr>
            <td>
              <code>PROVIDER_HEALTH_CHECK_CRON</code>
            </td>
            <td>
              Cron expression for the built-in provider health-check schedule.
              Defaults to <code>*/5 * * * *</code> (every 5 minutes).
            </td>
          </tr>
        </tbody>
      </table>

      <H2>External automation (webhooks)</H2>

      <table>
        <thead>
          <tr>
            <th>Variable</th>
            <th>Purpose</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <code>HOOKS_TRIGGER_TOKEN</code>
            </td>
            <td>
              Bearer token enabling <code>POST /api/hooks/drift-check</code>{" "}
              and <code>POST /api/hooks/provider-health-check</code> for
              external automation. The endpoints stay disabled (404) while
              unset.
            </td>
          </tr>
        </tbody>
      </table>

      <p>
        If you&apos;d rather drive the checks from an external tool (N8N,
        cron, CI), set a <code>HOOKS_TRIGGER_TOKEN</code> and call the
        webhooks:
      </p>

      <CodeBlock lang="sh">{`
        # drift-check all enabled providers
        curl -X POST https://dns.example.com/api/hooks/drift-check \\
          -H "Authorization: Bearer \$HOOKS_TRIGGER_TOKEN"

        # health-check all enabled providers
        curl -X POST https://dns.example.com/api/hooks/provider-health-check \\
          -H "Authorization: Bearer \$HOOKS_TRIGGER_TOKEN"

        # or target a single provider (works on both endpoints)
        curl -X POST https://dns.example.com/api/hooks/drift-check \\
          -H "Authorization: Bearer \$HOOKS_TRIGGER_TOKEN" \\
          -H "Content-Type: application/json" \\
          -d '{"provider_id": 1}'
      `}</CodeBlock>

      <p>
        The response reports what was queued:{" "}
        <code>
          {'{"queued": 2, "providers": ["Cloudflare — example.com", "Pi-hole — homelab"]}'}
        </code>
        . Requests without the correct token get <code>401</code>; while no
        token is configured the endpoints return <code>404</code>.
      </p>

      <Callout type="tip">
        Set <code>SCHEDULER_ENABLED=false</code> if the external triggers
        fully replace the built-in schedules. On Kubernetes there is a third
        option: the optional <code>cronjob.yaml</code> manifest — see{" "}
        <a href="/docs/installation/kubernetes/#optional-kubernetes-native-scheduler">
          Kubernetes
        </a>
        .
      </Callout>

      <H2>Activity retention</H2>

      <table>
        <thead>
          <tr>
            <th>Variable</th>
            <th>Purpose</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <code>ACTIVITY_LOGS_RETENTION_DAYS</code>
            </td>
            <td>
              When set to a positive number,{" "}
              <a href="/docs/activity/">audit-trail activities</a> older than
              that many days are deleted automatically once a day (registered
              even when <code>SCHEDULER_ENABLED=false</code>). Unset by
              default — activities are kept forever.
            </td>
          </tr>
        </tbody>
      </table>

      <H2>Advanced &amp; framework variables</H2>

      <p>
        Everything below is standard Laravel plumbing with defaults that are
        already correct for the shipped container. You rarely need to touch
        any of it — it is documented here so no variable the app reads goes
        unexplained.
      </p>

      <H3>Application extras</H3>

      <table>
        <thead>
          <tr>
            <th>Variable</th>
            <th>Default</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <code>APP_NAME</code>
            </td>
            <td>
              <code>DNS Manager</code>
            </td>
            <td>
              Browser title — but the session cookie name and cache/Redis key
              prefixes are <em>derived</em> from it. Renaming in production
              logs every user out unless <code>SESSION_COOKIE</code>,{" "}
              <code>CACHE_PREFIX</code> and <code>REDIS_PREFIX</code> are
              pinned explicitly.
            </td>
          </tr>
          <tr>
            <td>
              <code>APP_PREVIOUS_KEYS</code>
            </td>
            <td>unset</td>
            <td>
              Comma-separated previous <code>APP_KEY</code> values for
              graceful key rotation — data encrypted under an old key stays
              readable and is re-encrypted on write. Set it to the old key
              when rotating so provider credentials survive.
            </td>
          </tr>
          <tr>
            <td>
              <code>APP_TIMEZONE</code>
            </td>
            <td>
              <code>UTC</code>
            </td>
            <td>
              Log timestamps and the timezone the scheduler cron expressions
              fire in. If you set a local zone, remember{" "}
              <code>DRIFT_CHECK_CRON</code> now runs in that zone.
            </td>
          </tr>
          <tr>
            <td>
              <code>VITE_APP_NAME</code>
            </td>
            <td>
              <code>{"${APP_NAME}"}</code>
            </td>
            <td>
              Baked into the frontend bundle at <strong>build time</strong> —
              changing it at runtime has no effect.
            </td>
          </tr>
          <tr>
            <td>
              <code>BCRYPT_ROUNDS</code>
            </td>
            <td>
              <code>12</code>
            </td>
            <td>
              Bcrypt cost factor. Barely relevant here — sign-in is OIDC-only
              with no local passwords.
            </td>
          </tr>
          <tr>
            <td>
              <code>PHP_CLI_SERVER_WORKERS</code>
            </td>
            <td>
              <code>4</code>
            </td>
            <td>
              Workers for <code>php artisan serve</code> during local dev
              only; irrelevant in the production image (nginx + php-fpm).
            </td>
          </tr>
        </tbody>
      </table>

      <H3>Database &amp; Redis extras</H3>

      <table>
        <thead>
          <tr>
            <th>Variable</th>
            <th>Default</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <code>DB_URL</code> / <code>REDIS_URL</code>
            </td>
            <td>unset</td>
            <td>
              Single-DSN alternatives (
              <code>postgresql://user:pass@host:5432/db</code>) that override
              the individual <code>DB_*</code> / <code>REDIS_*</code> parts —
              convenient on platforms that hand out one URL.
            </td>
          </tr>
          <tr>
            <td>
              <code>REDIS_CLIENT</code>
            </td>
            <td>
              <code>predis</code>
            </td>
            <td>
              Pure-PHP Redis client the image standardizes on.{" "}
              <code>phpredis</code> needs the compiled extension — only use it
              if you rebuild the image with it installed.
            </td>
          </tr>
          <tr>
            <td>
              <code>REDIS_DB</code> / <code>REDIS_CACHE_DB</code>
            </td>
            <td>
              <code>0</code> / <code>1</code>
            </td>
            <td>
              Logical Redis databases for the queue and the cache — separate
              indexes keep <code>FLUSHDB</code> boundaries clean on a shared
              instance.
            </td>
          </tr>
          <tr>
            <td>
              <code>REDIS_PREFIX</code> / <code>CACHE_PREFIX</code>
            </td>
            <td>
              <code>dns_manager_database_</code> /{" "}
              <code>dns_manager_cache_</code>
            </td>
            <td>
              Key prefixes (derived from <code>APP_NAME</code> unless pinned)
              — collision safety when DNS Manager shares a Redis instance
              with other apps.
            </td>
          </tr>
        </tbody>
      </table>

      <H3>Queue behavior</H3>

      <table>
        <thead>
          <tr>
            <th>Variable</th>
            <th>Default</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <code>REDIS_QUEUE_RETRY_AFTER</code>
            </td>
            <td>
              <code>90</code>
            </td>
            <td>
              Seconds before a reserved-but-unfinished job is assumed dead,
              released, and retried. Must exceed your slowest realistic
              provider API call, or a slow push gets double-executed.
            </td>
          </tr>
          <tr>
            <td>
              <code>QUEUE_FAILED_DRIVER</code>
            </td>
            <td>
              <code>database-uuids</code>
            </td>
            <td>
              Where permanently failed jobs are recorded (
              <code>php artisan queue:failed</code> /{" "}
              <code>queue:retry</code>). Never set <code>null</code> — it
              silently discards the forensic trail. Sync failures also land
              in the <a href="/docs/dns-entries/sync/">sync logs</a> either
              way.
            </td>
          </tr>
        </tbody>
      </table>

      <H3>Cache &amp; sessions</H3>

      <table>
        <thead>
          <tr>
            <th>Variable</th>
            <th>Default</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <code>CACHE_STORE</code>
            </td>
            <td>
              <code>redis</code> (production)
            </td>
            <td>
              Beyond ordinary caching this backs the scheduler&apos;s
              overlap/one-server locks. <code>file</code> breaks lock sharing
              if you ever run more than one scheduler container;{" "}
              <code>null</code> disables the locks so a hung check could
              stack up.
            </td>
          </tr>
          <tr>
            <td>
              <code>SESSION_DRIVER</code>
            </td>
            <td>
              <code>database</code>
            </td>
            <td>
              Sessions in the <code>sessions</code> table survive container
              restarts and work multi-replica. <code>file</code> logs
              everyone out on pod replacement.
            </td>
          </tr>
          <tr>
            <td>
              <code>SESSION_LIFETIME</code>
            </td>
            <td>
              <code>120</code>
            </td>
            <td>
              Idle minutes before the user is bounced back to the OIDC
              sign-in.
            </td>
          </tr>
          <tr>
            <td>
              <code>SESSION_SECURE_COOKIE</code>
            </td>
            <td>auto</td>
            <td>
              Explicit <code>true</code> is sensible hardening behind a
              TLS-terminating proxy — but on plain-HTTP dev it means the
              cookie is never sent and you cannot log in.
            </td>
          </tr>
          <tr>
            <td>
              <code>SESSION_SAME_SITE</code>
            </td>
            <td>
              <code>lax</code>
            </td>
            <td>
              Required for the OIDC redirect to carry the cookie back from
              your identity provider — <code>strict</code> breaks the
              callback with a state mismatch.
            </td>
          </tr>
          <tr>
            <td>
              <code>SESSION_ENCRYPT</code>
            </td>
            <td>
              <code>false</code>
            </td>
            <td>
              <code>true</code> encrypts session payloads at rest with{" "}
              <code>APP_KEY</code> (defense-in-depth for DB dumps; payloads
              hold user identity, not provider secrets).
            </td>
          </tr>
        </tbody>
      </table>

      <H3>Logging</H3>

      <table>
        <thead>
          <tr>
            <th>Variable</th>
            <th>Default</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <code>LOG_CHANNEL</code>
            </td>
            <td>
              <code>stack</code> (k8s: <code>stderr</code>)
            </td>
            <td>
              <code>stderr</code> is correct for containers (
              <code>kubectl logs</code> / <code>docker logs</code>).{" "}
              <code>single</code>/<code>daily</code> write files that vanish
              with an ephemeral pod unless the optional volume is mounted.
            </td>
          </tr>
          <tr>
            <td>
              <code>LOG_LEVEL</code>
            </td>
            <td>
              <code>debug</code> (k8s: <code>info</code>)
            </td>
            <td>
              <code>info</code> is the sane production floor. Anything above{" "}
              <code>warning</code> hides the connector messages that explain
              why a provider went unhealthy — though the health badge and
              sync logs capture the error regardless.
            </td>
          </tr>
          <tr>
            <td>
              <code>LOG_STACK</code>
            </td>
            <td>
              <code>single</code>
            </td>
            <td>
              Channels the <code>stack</code> channel fans out to, e.g.{" "}
              <code>single,stderr</code> for both a file and container
              output.
            </td>
          </tr>
          <tr>
            <td>
              <code>LOG_SLACK_WEBHOOK_URL</code>
            </td>
            <td>unset</td>
            <td>
              Set a Slack incoming webhook and add <code>slack</code> to{" "}
              <code>LOG_STACK</code> to get pinged on critical failures.
            </td>
          </tr>
        </tbody>
      </table>

      <H3>Maintenance, mail &amp; unused drivers</H3>

      <table>
        <thead>
          <tr>
            <th>Variable</th>
            <th>Default</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <code>APP_MAINTENANCE_DRIVER</code>
            </td>
            <td>
              <code>file</code>
            </td>
            <td>
              With multiple containers, <code>file</code> puts only one into
              maintenance — use <code>cache</code> to make{" "}
              <code>php artisan down</code> cluster-wide.
            </td>
          </tr>
          <tr>
            <td>
              <code>APP_LOCALE</code> / <code>APP_FALLBACK_LOCALE</code>
            </td>
            <td>
              <code>en</code>
            </td>
            <td>The app ships English strings only — changing has no effect.</td>
          </tr>
          <tr>
            <td>
              <code>BROADCAST_CONNECTION</code>
            </td>
            <td>
              <code>log</code>
            </td>
            <td>No events are broadcast (no websockets) — inert.</td>
          </tr>
          <tr>
            <td>
              <code>FILESYSTEM_DISK</code>
            </td>
            <td>
              <code>local</code>
            </td>
            <td>
              The app is stateless and stores essentially nothing on disk in
              normal operation.
            </td>
          </tr>
          <tr>
            <td>
              <code>MAIL_MAILER</code> (+ <code>MAIL_*</code>)
            </td>
            <td>
              <code>log</code>
            </td>
            <td>
              The app sends no mail (OIDC — no password resets). Keep{" "}
              <code>log</code> so any future accidental mail lands visibly in
              the logs instead of being lost.
            </td>
          </tr>
        </tbody>
      </table>

      <Callout type="note">
        The stock Laravel config also references <code>AWS_*</code>,{" "}
        <code>SQS_*</code>, <code>BEANSTALKD_*</code>,{" "}
        <code>MEMCACHED_*</code> and <code>DYNAMODB_*</code> variables. They
        are consulted only when you select those drivers (
        <code>FILESYSTEM_DISK=s3</code>, <code>QUEUE_CONNECTION=sqs</code>, …)
        — none of which are supported or tested for DNS Manager. Leave them
        unset.
      </Callout>
    </DocArticle>
  );
}
