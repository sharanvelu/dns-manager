import Callout from "@/components/docs/Callout";
import CodeBlock from "@/components/docs/CodeBlock";
import DocArticle, { docMetadata } from "@/components/docs/DocArticle";
import { H2 } from "@/components/docs/Heading";

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
    </DocArticle>
  );
}
