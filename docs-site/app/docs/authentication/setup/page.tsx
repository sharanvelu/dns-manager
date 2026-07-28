import Callout from "@/components/docs/Callout";
import CodeBlock from "@/components/docs/CodeBlock";
import DocArticle, { docMetadata } from "@/components/docs/DocArticle";
import { H2 } from "@/components/docs/Heading";
import { Step, Steps } from "@/components/docs/Steps";

export const metadata = docMetadata("authentication", "setup");

export default function Page() {
  return (
    <DocArticle group="authentication" slug="setup">
      <p>
        Any spec-compliant OpenID Connect provider works. The app discovers
        everything else from your issuer&apos;s{" "}
        <code>/.well-known/openid-configuration</code> endpoint — you supply
        the issuer URL and a client.
      </p>

      <H2>What the app needs from your provider</H2>

      <ul>
        <li>
          A <strong>confidential client</strong> (client ID + client secret).
        </li>
        <li>
          The <strong>redirect URI</strong> registered exactly as configured —
          by default <code>{"${APP_URL}/auth/callback"}</code>.
        </li>
        <li>
          The scopes the app requests: <code>openid</code>, <code>email</code>,{" "}
          <code>profile</code>.
        </li>
        <li>
          An <strong>email claim</strong> in the user info — sign-in is
          rejected without one, since users are matched by OIDC subject and
          then by email.
        </li>
      </ul>

      <H2>Environment variables</H2>

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
              The client credentials registered with your identity provider.
            </td>
          </tr>
          <tr>
            <td>
              <code>OIDC_REDIRECT_URI</code>
            </td>
            <td>
              Callback URL; defaults to{" "}
              <code>{"${APP_URL}/auth/callback"}</code>. Must be registered
              with your identity provider.
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

      <H2>Worked example</H2>

      <Steps>
        <Step title="Create the client at your identity provider">
          <p>
            Create a confidential OAuth2/OIDC client (in Authentik: an
            OAuth2/OpenID <strong>Provider</strong> plus an{" "}
            <strong>Application</strong> bound to it). Note the client ID and
            client secret.
          </p>
        </Step>

        <Step title="Register the redirect URI">
          <p>
            Add the app&apos;s callback URL to the client&apos;s allowed
            redirect URIs:
          </p>
          <CodeBlock lang="sh">{`
            https://dns.example.com/auth/callback
          `}</CodeBlock>
        </Step>

        <Step title="Find your issuer URL">
          <p>
            It&apos;s the base of your provider&apos;s{" "}
            <code>/.well-known/openid-configuration</code> endpoint. With
            Authentik it looks like:
          </p>
          <CodeBlock lang="sh">{`
            https://auth.example.com/application/o/dns-manager
          `}</CodeBlock>
          <p>
            With Keycloak, an issuer takes the shape{" "}
            <code>https://sso.example.com/realms/homelab</code>.
          </p>
        </Step>

        <Step title="Set the environment and restart">
          <CodeBlock lang="sh">{`
            OIDC_BASE_URL=https://auth.example.com/application/o/dns-manager
            OIDC_CLIENT_ID=dns-manager
            OIDC_CLIENT_SECRET=...
            OIDC_REDIRECT_URI=https://dns.example.com/auth/callback
            OIDC_PROVIDER_LABEL=Authentik
          `}</CodeBlock>
          <p>
            Restart the container so the cached configuration picks up the new
            values.
          </p>
        </Step>

        <Step title="Sign in">
          <p>
            Open the app and click <strong>Sign in with Authentik</strong> (or
            whatever <code>OIDC_PROVIDER_LABEL</code> you chose). The first
            user to sign in becomes the Super Admin — see{" "}
            <a href="/docs/authentication/first-login/">First login</a>.
          </p>
        </Step>
      </Steps>

      <H2>Troubleshooting</H2>

      <Callout type="warning">
        <strong>Redirect URI mismatch.</strong> The URI registered at your
        provider must match <code>OIDC_REDIRECT_URI</code> exactly (scheme,
        host, and path — default <code>{"${APP_URL}/auth/callback"}</code>).
        Providers reject the authorization request otherwise. When TLS
        terminates at a proxy, also set <code>FORCE_HTTPS=true</code> so
        generated URLs use https — see{" "}
        <a href="/docs/installation/configuration/#application">
          Configuration
        </a>
        .
      </Callout>

      <Callout type="warning">
        <strong>
          &quot;The identity provider did not return an email address.&quot;
        </strong>{" "}
        The app requires an email claim to provision and match users. Make
        sure your provider releases the <code>email</code> scope/claim for
        this client.
      </Callout>

      <Callout type="note">
        <strong>
          &quot;Your sign-in session expired. Please try again.&quot;
        </strong>{" "}
        The OIDC state for that attempt was no longer valid (for example, an
        old login tab). Just retry the sign-in.
      </Callout>
    </DocArticle>
  );
}
