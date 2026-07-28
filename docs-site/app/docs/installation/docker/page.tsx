import Callout from "@/components/docs/Callout";
import CodeBlock from "@/components/docs/CodeBlock";
import DocArticle, { docMetadata } from "@/components/docs/DocArticle";
import { H2 } from "@/components/docs/Heading";
import { Step, Steps } from "@/components/docs/Steps";

export const metadata = docMetadata("installation", "docker");

export default function Page() {
  return (
    <DocArticle group="installation" slug="docker">
      <p>
        A single container is fully self-contained: its supervisor runs nginx +
        PHP, the queue worker, <strong>and</strong> the scheduler. The web role
        listens on port <strong>8080</strong>.
      </p>

      <H2>Quick start</H2>

      <Steps>
        <Step title="Generate an application key">
          <p>Any Laravel-compatible 32-byte key works:</p>
          <CodeBlock lang="sh">{`
            docker run --rm ghcr.io/OWNER/dns-manager php artisan key:generate --show
          `}</CodeBlock>
          <Callout type="warning">
            <code>APP_KEY</code> also encrypts provider credentials at rest —
            if you lose it, you must re-enter every provider&apos;s
            credentials. Back it up.
          </Callout>
        </Step>

        <Step title="Start the container">
          <p>
            <code>AUTO_MIGRATE=true</code> runs database migrations at
            container start, so a fresh database is ready as soon as the
            container is up:
          </p>
          <CodeBlock lang="sh">{`
            docker run -d --name dns-manager -p 8080:8080 \\
              -e APP_KEY="base64:..." \\
              -e AUTO_MIGRATE=true \\
              -e APP_ENV=production -e APP_DEBUG=false \\
              -e APP_URL=https://dns.example.com \\
              -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_PORT=5432 \\
              -e DB_DATABASE=dns_manager -e DB_USERNAME=dns_manager -e DB_PASSWORD=secret \\
              -e REDIS_HOST=redis -e REDIS_PORT=6379 \\
              -e QUEUE_CONNECTION=redis \\
              -e OIDC_BASE_URL=https://auth.example.com/application/o/dns-manager \\
              -e OIDC_CLIENT_ID=... -e OIDC_CLIENT_SECRET=... \\
              ghcr.io/OWNER/dns-manager
          `}</CodeBlock>
          <p>
            The entrypoint caches configuration (and migrates when{" "}
            <code>AUTO_MIGRATE=true</code>) before the web server accepts
            traffic. The full variable list is in the{" "}
            <a href="/docs/installation/configuration/">
              configuration reference
            </a>
            ; the OIDC values are covered in{" "}
            <a href="/docs/authentication/setup/">provider setup</a>.
          </p>
        </Step>

        <Step title="Verify and sign in">
          <CodeBlock lang="sh">{`
            curl -f http://localhost:8080/up
          `}</CodeBlock>
          <p>
            Open the app and sign in — the{" "}
            <strong>first user to sign in becomes the Super Admin</strong>. See{" "}
            <a href="/docs/authentication/first-login/">First login</a>.
          </p>
        </Step>
      </Steps>

      <H2>Roles inside the container</H2>

      <p>
        The web role only writes to the database and queues jobs, so the other
        two roles are <strong>not optional</strong>:
      </p>

      <table>
        <thead>
          <tr>
            <th>Role</th>
            <th>Command</th>
            <th>What breaks without it</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>Web</td>
            <td>image default (nginx + php-fpm via supervisord)</td>
            <td>The UI itself.</td>
          </tr>
          <tr>
            <td>Worker</td>
            <td>
              <code>php artisan queue:work redis</code>
            </td>
            <td>
              Nothing is ever pushed to or deleted from any provider; entries
              sit at &quot;Pending&quot; forever.
            </td>
          </tr>
          <tr>
            <td>Scheduler</td>
            <td>
              <code>php artisan schedule:work</code>
            </td>
            <td>
              No automatic drift or health checks (manual checks still work,
              via the worker).
            </td>
          </tr>
        </tbody>
      </table>

      <p>
        By default the container&apos;s supervisor runs{" "}
        <strong>all three</strong> — controlled by{" "}
        <code>SUPERVISOR_WORKER</code> and <code>SUPERVISOR_SCHEDULER</code>{" "}
        (both default <code>true</code>). Run at most <strong>one</strong>{" "}
        scheduler across your whole installation — the schedule also takes a
        cache lock (<code>onOneServer</code>) as a safety net.
      </p>

      <H2>Scaling out (advanced)</H2>

      <p>
        Not needed for a homelab. To split the roles into separate containers,
        set <code>SUPERVISOR_WORKER=false</code> and{" "}
        <code>SUPERVISOR_SCHEDULER=false</code> on the web container, then run
        two more containers with the same environment, overriding the command
        with <code>php artisan queue:work redis</code> and{" "}
        <code>php artisan schedule:work</code> respectively.
      </p>

      <H2>Next</H2>

      <p>
        Set the remaining environment to taste (
        <a href="/docs/installation/configuration/">configuration reference</a>
        ), or deploy on{" "}
        <a href="/docs/installation/kubernetes/">Kubernetes</a> instead.
      </p>
    </DocArticle>
  );
}
