import Callout from "@/components/docs/Callout";
import { Card, Cards } from "@/components/docs/Cards";
import DocArticle, { docMetadata } from "@/components/docs/DocArticle";
import { H2 } from "@/components/docs/Heading";

export const metadata = docMetadata("authentication");

export default function Page() {
  return (
    <DocArticle group="authentication">
      <p>
        There is no public registration form. Users sign in with{" "}
        <strong>OpenID Connect single sign-on</strong>, with{" "}
        <strong>email + password</strong>, or with both — each method can be
        switched on or off with environment variables (see{" "}
        <a href="/docs/authentication/passwords/">Password sign-in</a>).
      </p>

      <Cards>
        <Card title="Any spec-compliant provider" icon="shield">
          Authentik, Keycloak, Auth0, ... — the app uses standard OIDC
          discovery via your issuer&apos;s{" "}
          <code>/.well-known/openid-configuration</code>.
        </Card>
        <Card
          title="Optional passwords"
          icon="authentication"
          href="/docs/authentication/passwords/"
        >
          Admin-created users get an email to set a password; forgot-password
          is built in. Turn it off entirely while SSO is on if you prefer SSO
          only.
        </Card>
        <Card
          title="Auto-provisioned users"
          icon="users"
          href="/docs/authentication/first-login/"
        >
          Accounts are created on first sign-in — the first user ever becomes
          the Super Admin; everyone after starts with no access.
        </Card>
        <Card title="Audited sign-ins" icon="activity" href="/docs/activity/">
          Every login and logout is recorded in the built-in activity log.
        </Card>
      </Cards>

      <H2>How sessions work</H2>

      <p>
        After the provider redirects back, the app signs you in with a
        persistent (&quot;remember me&quot;) session and regenerates the
        session ID. Signing out invalidates the session; both events are
        logged as <code>auth</code> activities.
      </p>

      <p>
        Your name, email, and avatar are refreshed from the provider on every
        sign-in. Avatars come from Gravatar, falling back to initials.
      </p>

      <Callout type="note">
        Deleting a user in DNS Manager does <strong>not</strong> block them at
        your identity provider — if they sign in again via SSO they are
        re-provisioned with no access. To fully revoke access, also remove
        them (or the app grant) at your OIDC provider. See{" "}
        <a href="/docs/users/">Users</a>.
      </Callout>

      <H2>Set it up</H2>

      <Cards>
        <Card
          title="Provider setup"
          icon="shield"
          href="/docs/authentication/setup/"
        >
          Register the client, set the redirect URI, and configure the{" "}
          <code>OIDC_*</code> environment variables.
        </Card>
        <Card
          title="Password sign-in"
          icon="authentication"
          href="/docs/authentication/passwords/"
        >
          Enable or disable password and SSO sign-in, and bootstrap the first
          admin without SSO.
        </Card>
        <Card
          title="First login"
          icon="authentication"
          href="/docs/authentication/first-login/"
        >
          How the first Super Admin is bootstrapped, and what to do right
          after.
        </Card>
      </Cards>
    </DocArticle>
  );
}
