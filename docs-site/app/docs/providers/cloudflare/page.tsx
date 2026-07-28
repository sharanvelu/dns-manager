import DocArticle, { docMetadata } from "@/components/docs/DocArticle";
import { H2 } from "@/components/docs/Heading";
import { Step, Steps } from "@/components/docs/Steps";

export const metadata = docMetadata("providers", "cloudflare");

export default function Page() {
  return (
    <DocArticle group="providers" slug="cloudflare">
      <p>
        The Cloudflare connector manages your <strong>public DNS</strong>. One API token
        typically has access to many Cloudflare zones — create <strong>one</strong> Cloudflare
        provider with that token and attach it to each of your zones. No more
        one-provider-per-zone.
      </p>

      <H2>Connection fields</H2>
      <table>
        <thead>
          <tr>
            <th>Field</th>
            <th>Value</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <strong>API Token</strong>
            </td>
            <td>
              A token with <strong>Zone.DNS Edit</strong> and <strong>Zone Read</strong>{" "}
              permissions.
            </td>
          </tr>
          <tr>
            <td>
              <strong>Adopt existing records</strong>
            </td>
            <td>
              On by default — see{" "}
              <a href="/docs/providers/managing/#adopting-existing-records">
                Adopting existing records
              </a>
              .
            </td>
          </tr>
        </tbody>
      </table>
      <p>
        There is deliberately <strong>no Zone ID field</strong> here — the token is the
        credential; Zone IDs live on the <a href="/docs/zones/providers/">zone attachments</a>{" "}
        and are discovered automatically from each zone&apos;s name.
      </p>

      <H2>Creating the API token</H2>
      <Steps>
        <Step title="Open the API Tokens page">
          <p>
            In the Cloudflare dashboard, click your profile icon → <strong>My Profile</strong> →{" "}
            <strong>API Tokens</strong> → <strong>Create Token</strong>.
          </p>
        </Step>
        <Step title='Use the "Edit zone DNS" template'>
          <p>
            It grants <code>Zone.DNS: Edit</code>. Add <code>Zone.Zone: Read</code> under
            permissions.
          </p>
        </Step>
        <Step title="Scope the token">
          <p>
            Under &quot;Zone Resources&quot;, scope the token to the zones you want DNS Manager
            to serve — or all zones, if one token should cover everything.
          </p>
        </Step>
        <Step title="Copy the token immediately">
          <p>
            Cloudflare shows it only once. Paste it into the provider form and use{" "}
            <strong>Test connection</strong> before saving.
          </p>
        </Step>
      </Steps>

      <H2>Zone discovery</H2>
      <p>
        When you attach a Cloudflare provider to a zone, the app looks up the{" "}
        <strong>Zone ID</strong> from the zone&apos;s name automatically — no copying IDs out of
        the Cloudflare dashboard. Discovered values only fill blank fields; manual input always
        wins, and you can override the ID on the attachment. See{" "}
        <a href="/docs/zones/providers/">Zone providers</a>.
      </p>
      <p>
        The attachment&apos;s <strong>Test</strong> action verifies that the Zone ID belongs to
        the zone&apos;s domain.
      </p>

      <H2>Record types and features</H2>
      <ul>
        <li>
          <strong>Record types</strong>: A, AAAA, CNAME, MX, TXT, SRV, NS, CAA, PTR.
        </li>
        <li>
          <strong>Proxied</strong>: available for A, AAAA, and CNAME records only.
        </li>
        <li>
          <strong>TTL</strong>: 60–86400 seconds, or automatic when left empty. Proxied records
          always use automatic TTL.
        </li>
        <li>
          <strong>Priority</strong>: MX and SRV records.
        </li>
      </ul>

      <H2>Quirks worth knowing</H2>
      <ul>
        <li>
          <strong>Test connection</strong> verifies the token by listing the zones it can
          access, and reports how many. Both user-owned and account-owned API tokens work — the
          app deliberately avoids Cloudflare&apos;s <code>/user/tokens/verify</code> endpoint,
          which rejects account-owned tokens even when they are fully valid.
        </li>
        <li>
          <strong>Adoption is unambiguous-only</strong>: if Cloudflare holds several records on
          the same name (round-robin A records) and none matches your entry&apos;s content
          exactly, the conflict is reported as an error instead of guessing. See{" "}
          <a href="/docs/providers/managing/#adopting-existing-records">
            Adopting existing records
          </a>
          .
        </li>
      </ul>
    </DocArticle>
  );
}
