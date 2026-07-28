import Callout from "@/components/docs/Callout";
import { Card, Cards } from "@/components/docs/Cards";
import DocArticle, { docMetadata } from "@/components/docs/DocArticle";
import { H2 } from "@/components/docs/Heading";
import Screenshot from "@/components/docs/Screenshot";

export const metadata = docMetadata("authentication", "first-login");

export default function Page() {
  return (
    <DocArticle group="authentication" slug="first-login">
      <p>
        Open the app and click the sign-in button. There is no registration
        step: users are{" "}
        <strong>auto-provisioned on their first OIDC sign-in</strong>.
      </p>

      <Screenshot
        src="login"
        alt="The DNS Manager sign-in page"
        caption="One button — sign-in goes through your identity provider. The label comes from OIDC_PROVIDER_LABEL."
      />

      <H2>Who becomes the first admin</H2>

      <ul>
        <li>
          The{" "}
          <strong>first user ever to sign in becomes the Super Admin</strong> —
          that&apos;s you, right after installation.
        </li>
        <li>
          <strong>Everyone after that starts with no access at all</strong> —
          no global roles and no zone grants. They see a &quot;no access
          yet&quot; screen until an administrator assigns global roles or
          grants zone access under <strong>Settings → Users</strong> — see{" "}
          <a href="/docs/users/">Users</a>.
        </li>
      </ul>

      <H2>How accounts are matched</H2>

      <p>
        On each sign-in the app looks the user up by{" "}
        <strong>OIDC subject</strong> first, then by <strong>email</strong>, so
        an existing account is reused rather than duplicated. Name, email, and
        avatar are refreshed from the provider every time; avatars come from
        Gravatar (falling back to initials).
      </p>

      <Callout type="note">
        Deleting a user does not stop them signing in again — they are
        re-provisioned <strong>with no access</strong>. To fully revoke
        access, also remove them at your identity provider.
      </Callout>

      <H2>What to do next</H2>

      <Cards>
        <Card title="Connect a provider" icon="providers" href="/docs/providers/">
          Enter your Cloudflare, Pi-hole, or Technitium credentials once —
          reusable across zones.
        </Card>
        <Card title="Create a zone" icon="zones" href="/docs/zones/creating/">
          Create a zone for your domain and attach the provider to it.
        </Card>
        <Card title="Add DNS entries" icon="dns-entries" href="/docs/dns-entries/">
          Create records with zone-relative names and push them to your
          providers.
        </Card>
        <Card title="Invite your team" icon="users" href="/docs/users/managing/">
          Have them sign in once, then assign global roles or per-zone grants.
        </Card>
      </Cards>
    </DocArticle>
  );
}
