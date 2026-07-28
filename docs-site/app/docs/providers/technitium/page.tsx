import Callout from "@/components/docs/Callout";
import DocArticle, { docMetadata } from "@/components/docs/DocArticle";
import { H2 } from "@/components/docs/Heading";

export const metadata = docMetadata("providers", "technitium");

export default function Page() {
  return (
    <DocArticle group="providers" slug="technitium">
      <p>
        The Technitium connector manages a{" "}
        <strong>self-hosted authoritative DNS server</strong>. Technitium hosts real zones, but
        they are addressed by name — so attaching the provider to a zone whose name matches a
        zone hosted on the server is all it takes for records to flow.
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
              <strong>Base URL</strong>
            </td>
            <td>
              The Technitium web service address, e.g.{" "}
              <code>https://technitium.local:53443</code> — no trailing slash.
            </td>
          </tr>
          <tr>
            <td>
              <strong>API Token</strong>
            </td>
            <td>
              A <strong>permanent</strong> API token. Create one in the Technitium admin panel
              under <strong>Administration → Sessions → Create Token</strong> (or via the{" "}
              <code>user/createToken</code> API). Session tokens from a plain login expire — use
              a permanent token.
            </td>
          </tr>
          <tr>
            <td>
              <strong>Verify TLS certificate</strong>
            </td>
            <td>On by default. Turn it off when the server uses a self-signed certificate.</td>
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

      <H2>Attachments carry no settings</H2>
      <p>
        A Technitium attachment has <strong>no zone settings at all</strong>: the app addresses
        the remote zone by the zone&apos;s own name, so a zone named <code>example.com</code>{" "}
        here syncs to the zone <code>example.com</code> on the server.
      </p>

      <Callout type="note">
        The zone must <strong>already exist</strong> on the Technitium server — the app never
        creates zones. Attaching verifies it does, and the attachment&apos;s{" "}
        <strong>Test</strong> action reports &quot;Zone … does not exist on this Technitium
        server&quot; when it doesn&apos;t.
      </Callout>

      <H2>Record types and features</H2>
      <ul>
        <li>
          <strong>Record types</strong>: A, AAAA, CNAME, MX, TXT, SRV, NS, CAA, PTR.
        </li>
        <li>
          <strong>Priority</strong>: MX and SRV records.
        </li>
        <li>
          <strong>Proxied</strong>: not supported.
        </li>
        <li>
          <strong>TTL</strong>: any value — with the special handling below.
        </li>
      </ul>

      <H2>Behavior to know about</H2>
      <ul>
        <li>
          <strong>No stable record identifiers</strong> — like Pi-hole, Technitium identifies a
          record by its full value tuple rather than an id. The app tracks records the same way,
          so renames and edits are handled as old-value → new-value updates. Nothing to
          configure; it just explains why a record edited <em>at the server</em> shows up as
          drift rather than being followed. The drift check re-points its tracking at the edited
          record, so syncing the drift <strong>updates that record back in place</strong> rather
          than adding the tracked value as a second record beside it.
        </li>
        <li>
          <strong>TTL</strong> — Technitium always stores a TTL. Entries with an empty
          (automatic) TTL are pushed with <code>3600</code>, and records at <code>3600</code>{" "}
          are treated as automatic in drift comparison — so automatic and an explicit{" "}
          <code>3600</code> count as the same TTL and never drift against each other. If you
          want a TTL that is compared exactly, set any explicit value other than 3600.
        </li>
        <li>
          <strong>Test connection</strong> verifies the API token by listing the zones the
          server hosts; on success it reports how many. Whether a specific zone exists is
          checked per attachment with the <strong>Test</strong> action on the zone page.
        </li>
      </ul>
    </DocArticle>
  );
}
