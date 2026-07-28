import Callout from "@/components/docs/Callout";
import { Card, Cards } from "@/components/docs/Cards";
import DocArticle, { docMetadata } from "@/components/docs/DocArticle";
import { H2 } from "@/components/docs/Heading";

export const metadata = docMetadata("installation");

export default function Page() {
  return (
    <DocArticle group="installation">
      <p>
        DNS Manager ships as a <strong>single container image</strong>,{" "}
        <code>ghcr.io/OWNER/dns-manager</code> (replace <code>OWNER</code> with
        the GitHub user or organization hosting your build). One container runs
        all three roles — nginx + php-fpm (web), the queue worker, and the
        scheduler — under its built-in supervisord.
      </p>

      <Callout type="important">
        The worker and scheduler are <strong>not optional</strong>. The web
        role only writes to the database and queues jobs; without the worker
        nothing is ever pushed to a provider. The default image runs all three
        roles for you — see{" "}
        <a href="/docs/installation/docker/#roles-inside-the-container">Docker</a>.
      </Callout>

      <H2>Requirements</H2>

      <Cards>
        <Card title="PostgreSQL 16" icon="terminal">
          The application database — the source of truth for all DNS entries.
        </Card>
        <Card title="Redis" icon="sync">
          Backs the job queue that performs all provider pushes and drift
          checks.
        </Card>
        <Card
          title="An OIDC provider"
          icon="authentication"
          href="/docs/authentication/"
        >
          No local accounts — sign-in is OpenID Connect only. Any
          spec-compliant provider works (Authentik, Keycloak, Auth0, ...).
        </Card>
        <Card title="Somewhere to run containers" icon="globe">
          Kubernetes (manifests included) or plain Docker.
        </Card>
      </Cards>

      <H2>Choose your path</H2>

      <Cards>
        <Card title="Docker" icon="terminal" href="/docs/installation/docker/">
          Quick start: generate a key, run one self-contained container, sign
          in.
        </Card>
        <Card
          title="Kubernetes"
          icon="globe"
          href="/docs/installation/kubernetes/"
        >
          Ready-made manifests under <code>k8s/</code> — one pod covers all
          roles, <code>kubectl apply -k</code> and go.
        </Card>
        <Card
          title="Configuration"
          icon="shield"
          href="/docs/installation/configuration/"
        >
          The full environment-variable reference: app, database, OIDC,
          scheduler, automation hooks.
        </Card>
        <Card title="Upgrading" icon="sync" href="/docs/installation/upgrading/">
          Pull the new tag, restart, done — migrations run automatically.
        </Card>
      </Cards>

      <H2>After installing</H2>

      <p>
        Sign in once to become the Super Admin (
        <a href="/docs/authentication/first-login/">first login</a>), connect
        your first <a href="/docs/providers/">provider</a>, create a{" "}
        <a href="/docs/zones/">zone</a>, and add{" "}
        <a href="/docs/dns-entries/">DNS entries</a>.
      </p>
    </DocArticle>
  );
}
