import Callout from "@/components/docs/Callout";
import { Card, Cards } from "@/components/docs/Cards";
import DocArticle, { docMetadata } from "@/components/docs/DocArticle";
import { H2 } from "@/components/docs/Heading";
import Screenshot from "@/components/docs/Screenshot";

export const metadata = docMetadata("dns-entries");

export default function Page() {
  return (
    <DocArticle group="dns-entries">
      <p>
        The DNS Entries page shows your records across{" "}
        <strong>all zones you can access</strong> in one table; each zone&apos;s{" "}
        <strong>Records tab</strong> shows the same table scoped to that zone (see{" "}
        <a href="/docs/zones/">DNS Zones</a>). Everything in this section applies to both,
        except where noted.
      </p>

      <Screenshot
        src="entries-index"
        alt="The DNS Entries page"
        caption="All records you can access, with per-provider sync chips and filters."
      />

      <p>
        Permissions are <strong>per zone</strong> (<a href="/docs/zones/access/">role
        system</a>): creating, editing, syncing, importing, and deleting records requires the{" "}
        <strong>Zone Admin</strong> or <strong>DNS Manager</strong> role on the entry&apos;s
        zone (Super Admins can do everything). Rows in zones where you hold neither role — for
        example as a zone Viewer, or everywhere as a Super Viewer — are{" "}
        <strong>read-only</strong>: no selection checkbox and no mutating row actions.
      </p>

      <H2>Anatomy of an entry</H2>

      <table>
        <thead>
          <tr>
            <th>Field</th>
            <th>What it is</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <strong>Name</strong>
            </td>
            <td>
              The record&apos;s name, <strong>relative to the zone</strong> — see{" "}
              <a href="#zone-relative-names">below</a>.
            </td>
          </tr>
          <tr>
            <td>
              <strong>Type</strong>
            </td>
            <td>One of A, AAAA, CNAME, MX, TXT, SRV, NS, CAA, PTR.</td>
          </tr>
          <tr>
            <td>
              <strong>Content</strong>
            </td>
            <td>
              The record&apos;s value — label and validation adapt to the type (max 2048
              characters).
            </td>
          </tr>
          <tr>
            <td>
              <strong>TTL</strong>
            </td>
            <td>
              Optional, 60–86400 seconds; empty means automatic (shown as <code>Auto</code>).
            </td>
          </tr>
          <tr>
            <td>
              <strong>Priority</strong>
            </td>
            <td>MX and SRV only, where it is required. 0–65535, lower is preferred.</td>
          </tr>
          <tr>
            <td>
              <strong>Proxied</strong>
            </td>
            <td>
              Routes traffic through the provider&apos;s proxy network — only available when a
              proxy-capable provider (Cloudflare) is targeted.
            </td>
          </tr>
          <tr>
            <td>
              <strong>Comment</strong>
            </td>
            <td>Optional note, max 255 characters.</td>
          </tr>
        </tbody>
      </table>
      <p>
        Entries must be unique on the combination of name, type, and content{" "}
        <strong>within their zone</strong>.
      </p>

      <H2>Zone-relative names</H2>

      <p>
        Record names inside a zone are stored <strong>relative to the zone</strong>:
      </p>
      <table>
        <thead>
          <tr>
            <th>You want</th>
            <th>Name to enter</th>
            <th>
              FQDN in zone <code>example.com</code>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>The zone apex</td>
            <td>
              <code>@</code>
            </td>
            <td>
              <code>example.com</code>
            </td>
          </tr>
          <tr>
            <td>A host</td>
            <td>
              <code>www</code>
            </td>
            <td>
              <code>www.example.com</code>
            </td>
          </tr>
          <tr>
            <td>A wildcard</td>
            <td>
              <code>*.app</code>
            </td>
            <td>
              <code>*.app.example.com</code>
            </td>
          </tr>
          <tr>
            <td>A service/verification label</td>
            <td>
              <code>_dmarc</code>
            </td>
            <td>
              <code>_dmarc.example.com</code>
            </td>
          </tr>
          <tr>
            <td>A deeper name</td>
            <td>
              <code>a.b</code>
            </td>
            <td>
              <code>a.b.example.com</code>
            </td>
          </tr>
        </tbody>
      </table>
      <p>
        Pasting a <strong>full hostname is fine</strong>: <code>www.example.com</code> entered
        in zone <code>example.com</code> is automatically converted to <code>www</code> (and{" "}
        <code>example.com</code> itself becomes <code>@</code>) before validation. Careful with
        names under a <em>different</em> domain though — <code>www.other.com</code> is not
        inside the zone, so it is treated as the relative name <code>www.other.com</code> (FQDN{" "}
        <code>www.other.com.example.com</code>).
      </p>

      <Callout type="tip">
        Everywhere a relative name is shown, hovering it reveals the full FQDN in a tooltip.
      </Callout>

      <H2>Supported record types</H2>

      <table>
        <thead>
          <tr>
            <th>Type</th>
            <th>Content</th>
            <th>Example</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>A</td>
            <td>IPv4 address (validated)</td>
            <td>
              <code>192.168.1.10</code>
            </td>
          </tr>
          <tr>
            <td>AAAA</td>
            <td>IPv6 address (validated)</td>
            <td>
              <code>2001:db8::1</code>
            </td>
          </tr>
          <tr>
            <td>CNAME</td>
            <td>Target hostname</td>
            <td>
              <code>server.home.lan</code>
            </td>
          </tr>
          <tr>
            <td>MX</td>
            <td>Mail server hostname</td>
            <td>
              <code>mail.example.com</code>
            </td>
          </tr>
          <tr>
            <td>TXT</td>
            <td>Text value (quoting for Cloudflare is handled for you)</td>
            <td>
              <code>v=spf1 include:example.com ~all</code>
            </td>
          </tr>
          <tr>
            <td>SRV</td>
            <td>
              <code>weight port target</code>
            </td>
            <td>
              <code>5 5060 sipserver.example.com</code>
            </td>
          </tr>
          <tr>
            <td>NS</td>
            <td>Target hostname</td>
            <td>
              <code>ns1.example.com</code>
            </td>
          </tr>
          <tr>
            <td>CAA</td>
            <td>
              <code>flags tag &quot;value&quot;</code>
            </td>
            <td>
              <code>0 issue &quot;letsencrypt.org&quot;</code>
            </td>
          </tr>
          <tr>
            <td>PTR</td>
            <td>Target hostname</td>
            <td>
              <code>host.example.com</code>
            </td>
          </tr>
        </tbody>
      </table>
      <p>
        Not every provider manages every type — see the connector pages under{" "}
        <a href="/docs/providers/">Providers</a>.
      </p>

      <H2>The entries table</H2>

      <p>
        Columns: <strong>Name</strong> (zone-relative — hover for the full FQDN; an orange
        cloud icon marks proxied records), <strong>Zone</strong> (global page only; links to
        the zone&apos;s records), <strong>Type</strong>, <strong>Content</strong>,{" "}
        <strong>TTL</strong> (<code>Auto</code> when unset), <strong>Providers</strong> (one
        status chip per assigned provider attachment), and <strong>Updated</strong>.
      </p>
      <p>
        Every column except Providers is sortable — click a header to sort by it (server-side,
        across all pages), click again to flip the direction. The default is Name ascending,
        sorting combines with the active filters, and <code>Auto</code> TTLs always sort last.
      </p>
      <p>
        Above the table you can search (matches name and content, case-insensitive) and filter
        by zone (global page only), record type, provider, and sync status. Results are
        paginated 25 per page; filters combine, and a &quot;Clear filters&quot; button appears
        when a filtered view is empty.
      </p>
      <p>
        Row actions live behind the kebab menu at the end of each row: <strong>Edit</strong>,{" "}
        <strong>Sync now</strong>, and <strong>Delete</strong> when you can manage the
        entry&apos;s zone, and <strong>Activity</strong> — the entry&apos;s audit history from
        the <a href="/docs/activity/">Activity Log</a> — when you can view that zone&apos;s
        activity (Zone Admin or Viewer of the zone, plus Super Admins and Super Viewers). Rows
        in zones you can manage also get a selection checkbox for{" "}
        <a href="/docs/dns-entries/managing/#bulk-actions">bulk actions</a>.
      </p>

      <H2>Next steps</H2>

      <Cards>
        <Card title="Manage entries" icon="dns-entries" href="/docs/dns-entries/managing/">
          Create, edit, bulk-edit, and delete records, and target providers per entry.
        </Card>
        <Card title="Sync & drift" icon="sync" href="/docs/dns-entries/sync/">
          How pushes, status chips, drift detection, and Sync now / Sync all work.
        </Card>
      </Cards>
    </DocArticle>
  );
}
