import { Card, Cards } from "@/components/docs/Cards";
import DocArticle, { docMetadata } from "@/components/docs/DocArticle";
import { H2 } from "@/components/docs/Heading";
import Screenshot from "@/components/docs/Screenshot";

export const metadata = docMetadata("providers");

export default function Page() {
  return (
    <DocArticle group="providers">
      <p>
        A <strong>provider is a set of credentials, entered once</strong>. It holds what the app
        needs to talk to a DNS backend — a Cloudflare API token, a Pi-hole address and app
        password, a Technitium address and API token — plus provider-wide settings like the
        managed record types.
      </p>
      <p>
        A provider does nothing by itself: you <strong>attach</strong> it to{" "}
        <a href="/docs/zones/">zones</a>, and the attachment carries the zone-specific settings.
        Records always sync through an attachment, never to a &quot;bare&quot; provider.
      </p>

      <Screenshot
        src="providers-index"
        alt="The Providers page"
        caption="One card per provider: health badge, managed record types, and attached zones."
      />

      <H2>Zoned vs zoneless</H2>
      <p>Connectors come in two shapes:</p>
      <ul>
        <li>
          <strong>Zoned</strong> (Cloudflare, Technitium) — you attach the provider to each zone
          it should serve. One Cloudflare API token typically covers many Cloudflare zones, so
          one provider serves all of them; the per-zone Zone ID lives on the attachment and is{" "}
          <a href="/docs/zones/providers/">auto-discovered</a> from the zone name. A Technitium
          attachment needs no settings at all — the remote zone is addressed by the zone&apos;s
          own name.
        </li>
        <li>
          <strong>Zoneless</strong> (Pi-hole) — Pi-hole has no zone concept; it answers for any
          name. A Pi-hole provider attaches itself to <strong>all zones automatically</strong>{" "}
          (current and future), and you opt individual zones out when you don&apos;t want them
          served — see <a href="/docs/zones/providers/">Zone providers</a>.
        </li>
      </ul>

      <Cards>
        <Card title="Cloudflare" icon="cloudflare" href="/docs/providers/cloudflare/">
          Public DNS. One API token, many zones, auto-discovered Zone IDs, optional proxying.
        </Card>
        <Card title="Pi-hole" icon="pihole" href="/docs/providers/pihole/">
          Local DNS for the homelab. Zoneless — auto-attaches to every zone, with per-zone
          opt-out.
        </Card>
        <Card title="Technitium" icon="technitium" href="/docs/providers/technitium/">
          Self-hosted authoritative DNS. Attachments carry no settings — zones are addressed by
          name.
        </Card>
        <Card title="Managing providers" icon="providers" href="/docs/providers/managing/">
          Add, test, edit, disable, and delete providers — and what each action really does.
        </Card>
      </Cards>

      <H2>The provider card</H2>
      <p>The Providers page shows one card per provider:</p>
      <ul>
        <li>
          <strong>Health badge</strong> — <strong>Healthy</strong>, <strong>Error</strong> (hover
          for the message), or <strong>Not checked</strong> — plus when it was last checked.
        </li>
        <li>
          <strong>Managed record types</strong> — the types this provider instance syncs (see{" "}
          <a href="/docs/providers/managing/#managed-record-types">Managed record types</a>).
        </li>
        <li>
          <strong>Zones</strong> — how many records are assigned to it and how many are in sync,
          plus a chip per attached zone (dimmed when the attachment is disabled; click to open
          the zone). Zoneless providers show an <strong>All zones</strong> badge with opted-out
          zones struck through. Zone-supporting providers get an <strong>Attach to zone</strong>{" "}
          button for zones they aren&apos;t attached to yet.
        </li>
        <li>
          <strong>Kebab actions</strong> — <strong>Edit</strong>, <strong>Check drift</strong>,{" "}
          <strong>Enable/Disable</strong>, and <strong>Delete</strong> for Super Admins, plus{" "}
          <strong>Activity</strong> (the provider&apos;s audit history) for Super Admins and
          Super Viewers.
        </li>
      </ul>

      <H2>Provider health</H2>
      <p>
        The health badge is kept current by two schedulers: a connectivity health check (every 5
        minutes by default) and the drift check (every 15 minutes by default). Both are
        configurable — see <a href="/docs/installation/configuration/">Configuration</a> and{" "}
        <a href="/docs/providers/managing/#test-connection">Test connection</a>.
      </p>

      <H2>Who can do what</H2>
      <p>
        The Providers page and its credentials are visible to <strong>Super Admins</strong> and{" "}
        <strong>Super Viewers</strong> only, and only <strong>Super Admins</strong> can add,
        edit, test, enable/disable, or delete a provider — Super Viewers get a read-only view
        (secrets stay hidden either way).
      </p>
      <p>
        A zone&apos;s provider <strong>attachments</strong> are managed separately on the
        zone&apos;s page, by that zone&apos;s <strong>Zone Admins</strong> and{" "}
        <strong>Provider Managers</strong> — see{" "}
        <a href="/docs/zones/providers/">Zone providers</a> and{" "}
        <a href="/docs/users/">Users &amp; Access</a>.
      </p>

      <H2>Capability matrix</H2>
      <table>
        <thead>
          <tr>
            <th>Capability</th>
            <th>Cloudflare</th>
            <th>Pi-hole v6</th>
            <th>Technitium</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>Zones</td>
            <td>Per-zone attachment; Zone ID auto-discovered</td>
            <td>Zoneless — auto-attaches to all zones, per-zone opt-out</td>
            <td>Per-zone attachment; addressed by zone name, no attachment settings</td>
          </tr>
          <tr>
            <td>Record types</td>
            <td>A, AAAA, CNAME, MX, TXT, SRV, NS, CAA, PTR</td>
            <td>A, AAAA, CNAME</td>
            <td>A, AAAA, CNAME, MX, TXT, SRV, NS, CAA, PTR</td>
          </tr>
          <tr>
            <td>Proxied</td>
            <td>A, AAAA, and CNAME records only</td>
            <td>Not supported</td>
            <td>Not supported</td>
          </tr>
          <tr>
            <td>TTL</td>
            <td>60–86400 seconds, or automatic when empty; proxied records always use automatic</td>
            <td>
              Hosts entries (A/AAAA): none; CNAME: optional TTL. TTL is excluded from drift
              comparison
            </td>
            <td>Any value; empty (automatic) pushes 3600, and 3600 reads back as automatic</td>
          </tr>
          <tr>
            <td>Priority</td>
            <td>MX and SRV records</td>
            <td>Not supported</td>
            <td>MX and SRV records</td>
          </tr>
        </tbody>
      </table>
      <p>
        Fields a provider does not support are simply not sent to it and are never counted as
        drift there. See <a href="/docs/dns-entries/">DNS Entries</a> for how these fields
        appear in the entry form.
      </p>
    </DocArticle>
  );
}
