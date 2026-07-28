import Callout from "@/components/docs/Callout";
import CodeBlock from "@/components/docs/CodeBlock";
import DocArticle, { docMetadata } from "@/components/docs/DocArticle";
import { H2 } from "@/components/docs/Heading";
import { Step, Steps } from "@/components/docs/Steps";

export const metadata = docMetadata("installation", "kubernetes");

export default function Page() {
  return (
    <DocArticle group="installation" slug="kubernetes">
      <p>
        The repository ships ready-made manifests under <code>k8s/</code>.{" "}
        <strong>One pod = the whole app</strong>: the single container runs
        nginx + php-fpm + queue worker + scheduler via supervisord, exactly
        like the standalone{" "}
        <a href="/docs/installation/docker/">Docker container</a>. PostgreSQL
        16 and Redis are expected to already exist (in-cluster or reachable
        externally).
      </p>

      <H2>The manifests</H2>

      <table>
        <thead>
          <tr>
            <th>Manifest</th>
            <th>Purpose</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <code>configmap.yaml</code>
            </td>
            <td>
              Non-secret environment (<code>APP_URL</code>, DB/Redis hosts,
              queue settings, <code>AUTO_MIGRATE=true</code>)
            </td>
          </tr>
          <tr>
            <td>
              <code>secret.example.yaml</code>
            </td>
            <td>
              Template for secrets (<code>APP_KEY</code>, DB password, OIDC
              client secret) — copy to <code>secret.yaml</code>, never commit
              it
            </td>
          </tr>
          <tr>
            <td>
              <code>deployment.yaml</code>
            </td>
            <td>
              The single <code>dns-manager</code> pod: nginx + php-fpm + queue
              worker + scheduler via supervisord (image default command)
            </td>
          </tr>
          <tr>
            <td>
              <code>service.yaml</code>
            </td>
            <td>ClusterIP, port 80 → pod 8080</td>
          </tr>
          <tr>
            <td>
              <code>ingress.yaml</code>
            </td>
            <td>TLS ingress (nginx + cert-manager annotations)</td>
          </tr>
          <tr>
            <td>
              <code>volume.yaml</code>
            </td>
            <td>
              <em>Optional</em> PersistentVolumeClaim for{" "}
              <code>storage/app</code> — excluded by default (the app is
              stateless; Postgres and Redis are external)
            </td>
          </tr>
          <tr>
            <td>
              <code>cronjob.yaml</code>
            </td>
            <td>
              <em>Optional</em> Kubernetes-native scheduler alternative — runs{" "}
              <code>php artisan dns:check-drift</code> every 15 minutes; set{" "}
              <code>SCHEDULER_ENABLED=false</code> if you use it
            </td>
          </tr>
          <tr>
            <td>
              <code>kustomization.yaml</code>
            </td>
            <td>
              Ties it all together for <code>kubectl apply -k</code> (secret,
              volume, and cronjob are commented out by default)
            </td>
          </tr>
        </tbody>
      </table>

      <H2>Deploy</H2>

      <Steps>
        <Step title="Replace the placeholders">
          <ul>
            <li>
              <code>OWNER</code> in <code>deployment.yaml</code> (and{" "}
              <code>cronjob.yaml</code> if used) — your GitHub user/org.
            </li>
            <li>
              <code>dns.example.com</code> in <code>configmap.yaml</code> (
              <code>APP_URL</code>) and <code>ingress.yaml</code>.
            </li>
            <li>
              <code>DB_HOST</code> / <code>REDIS_HOST</code> in{" "}
              <code>configmap.yaml</code>, pointing at your services.
            </li>
          </ul>
          <p>
            If your GHCR packages are private, add an image pull secret and
            reference it in the deployment (<code>imagePullSecrets</code>).
          </p>
        </Step>

        <Step title="Create the namespace">
          <p>
            Kustomize&apos;s <code>namespace:</code> field only labels
            resources; it does not create the namespace:
          </p>
          <CodeBlock lang="sh">{`
            kubectl create namespace dns-manager
          `}</CodeBlock>
        </Step>

        <Step title="Create the secret">
          <CodeBlock lang="sh">{`
            cp k8s/secret.example.yaml k8s/secret.yaml   # edit values; do NOT commit
            # APP_KEY: php artisan key:generate --show
          `}</CodeBlock>
          <p>
            Then uncomment <code>- secret.yaml</code> in{" "}
            <code>kustomization.yaml</code> — or create the secret imperatively
            (see the header of <code>secret.example.yaml</code>).
          </p>
        </Step>

        <Step title="Apply everything">
          <CodeBlock lang="sh">{`
            kubectl apply -k k8s/
          `}</CodeBlock>
        </Step>

        <Step title="Verify">
          <p>
            Health probes hit Laravel&apos;s built-in <code>/up</code> endpoint
            on port 8080. The readiness probe allows generous startup time
            because migrations run before the web server accepts traffic. Then
            open the app and{" "}
            <a href="/docs/authentication/first-login/">sign in</a>.
          </p>
        </Step>
      </Steps>

      <Callout type="important">
        Keep <code>replicas: 1</code>. The deployment uses the{" "}
        <code>Recreate</code> strategy, and the in-pod scheduler and start-time
        migrations are not safe to run in parallel — the scheduler must run at
        most once per installation. <code>AUTO_MIGRATE=true</code> in the
        ConfigMap runs migrations at pod start, so there is no separate migrate
        Job.
      </Callout>

      <H2>Optional: persistent storage</H2>

      <p>
        The app is stateless by default — all state lives in Postgres/Redis.
        To make <code>storage/app</code> survive pod restarts, add{" "}
        <code>- volume.yaml</code> to <code>kustomization.yaml</code> and
        uncomment the <code>volumeMounts</code>/<code>volumes</code> blocks in{" "}
        <code>deployment.yaml</code>.
      </p>

      <H2>Optional: Kubernetes-native scheduler</H2>

      <p>To use a CronJob instead of the in-pod scheduler:</p>

      <ol>
        <li>
          Set <code>SCHEDULER_ENABLED: &quot;false&quot;</code> (or{" "}
          <code>SUPERVISOR_SCHEDULER: &quot;false&quot;</code>) in{" "}
          <code>configmap.yaml</code>.
        </li>
        <li>
          Add <code>- cronjob.yaml</code> to <code>kustomization.yaml</code>{" "}
          (schedule <code>*/15 * * * *</code>,{" "}
          <code>concurrencyPolicy: Forbid</code>). Duplicate the manifest with{" "}
          <code>dns:check-provider-health</code> if you also want scheduled
          health checks.
        </li>
      </ol>

      <H2>Scaling out (advanced)</H2>

      <p>If you outgrow one pod:</p>

      <ol>
        <li>
          Set <code>SUPERVISOR_WORKER: &quot;false&quot;</code> and{" "}
          <code>SUPERVISOR_SCHEDULER: &quot;false&quot;</code> in the ConfigMap
          so web pods only serve HTTP.
        </li>
        <li>
          Add dedicated Deployments with command overrides (
          <code>queue:work redis</code>, and <code>schedule:work</code> at
          exactly 1 replica).
        </li>
        <li>
          Set <code>AUTO_MIGRATE: &quot;false&quot;</code> and run migrations
          via a one-shot Job (or{" "}
          <code>kubectl exec ... php artisan migrate --force</code>) so
          replicas don&apos;t race migrations.
        </li>
        <li>
          Then the web Deployment can use <code>RollingUpdate</code> and{" "}
          <code>replicas &gt; 1</code>.
        </li>
      </ol>

      <p>
        See <code>k8s/README.md</code> in the repository for the full command
        sequence, and the{" "}
        <a href="/docs/installation/configuration/">configuration reference</a>{" "}
        for every variable.
      </p>
    </DocArticle>
  );
}
