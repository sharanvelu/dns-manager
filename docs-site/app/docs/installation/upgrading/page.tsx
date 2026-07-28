import Callout from "@/components/docs/Callout";
import CodeBlock from "@/components/docs/CodeBlock";
import DocArticle, { docMetadata } from "@/components/docs/DocArticle";
import { Step, Steps } from "@/components/docs/Steps";

export const metadata = docMetadata("installation", "upgrading");

export default function Page() {
  return (
    <DocArticle group="installation" slug="upgrading">
      <Steps>
        <Step title="Pull the new image tag">
          <p>
            Grab the release you want from{" "}
            <code>ghcr.io/OWNER/dns-manager</code>.
          </p>
        </Step>

        <Step title="Restart the container">
          <p>
            On Kubernetes, bump the image tag in <code>deployment.yaml</code>{" "}
            and re-apply (<code>kubectl apply -k k8s/</code>) — or, if you
            track <code>latest</code>:
          </p>
          <CodeBlock lang="sh">{`
            kubectl -n dns-manager rollout restart deploy/dns-manager
          `}</CodeBlock>
        </Step>

        <Step title="That's it">
          <p>
            With <code>AUTO_MIGRATE=true</code> set, database migrations run
            automatically at container start. On Kubernetes the{" "}
            <code>Recreate</code> strategy guarantees the old pod is gone
            before the new one migrates, at the cost of a brief downtime
            window.
          </p>
        </Step>
      </Steps>

      <Callout type="note">
        <strong>Docs match your version.</strong> The documentation is baked
        into the image at build time, so <code>/docs</code> on your own
        instance always describes exactly the version you are running — no
        login required. The hosted site covers the latest release.
      </Callout>

      <Callout type="important">
        <strong>
          Upgrading from an early version with global DNS Manager / Providers
          Manager / Viewer roles?
        </strong>{" "}
        Those global roles were replaced by per-zone roles. During the
        migration, Super Admins keep their role and every other existing user
        becomes a Super Viewer — review each user afterwards and hand out zone
        grants. See <a href="/docs/users/">Users</a>.
      </Callout>
    </DocArticle>
  );
}
