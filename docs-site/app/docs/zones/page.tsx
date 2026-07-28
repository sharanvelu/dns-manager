import { Card, Cards } from "@/components/docs/Cards";
import DocArticle, { docMetadata } from "@/components/docs/DocArticle";
import { H2 } from "@/components/docs/Heading";
import Screenshot from "@/components/docs/Screenshot";

export const metadata = docMetadata("zones");

export default function Page() {
  return (
    <DocArticle group="zones">
      <p>
        A <strong>zone</strong> is a domain — <code>example.com</code>, <code>home.lan</code> —
        and it is where your DNS records live. Every entry belongs to exactly one zone, record
        names are stored relative to it, and the zone decides which providers its records can
        sync to: you <a href="/docs/zones/providers/">attach providers</a> to the zone, and each
        entry targets a subset of those attachments.
      </p>
      <p>
        Record names inside a zone are <strong>zone-relative</strong>: <code>@</code> for the
        apex, <code>www</code>, <code>*.app</code>. Pasting a full hostname is fine — it is
        relativized automatically. See{" "}
        <a href="/docs/dns-entries/#zone-relative-names">zone-relative names</a> for the full
        rules.
      </p>

      <H2>The Zones page</H2>

      <Screenshot
        src="zones-index"
        alt="The Zones page"
        caption="Every zone you can access, with record counts, sync rollups, and attached-provider marks."
      />

      <p>
        The Zones page lists <strong>the zones you have access to</strong> — all of them for
        Super Admins and Super Viewers, otherwise only the zones you hold a{" "}
        <a href="/docs/zones/access/">zone role</a> on. Each zone card shows:
      </p>
      <ul>
        <li>
          The <strong>record count</strong>.
        </li>
        <li>
          A <strong>status rollup</strong> — emerald <em>All in sync</em> when every record is
          synced, an amber <em>drifted</em> count and a red <em>error</em> count when something
          needs attention. A zone with nothing to report shows no status line at all.
        </li>
        <li>
          A small <strong>icon per attached provider</strong>, dimmed when that attachment is
          disabled.
        </li>
      </ul>
      <p>
        Click a zone to open its Records tab. The kebab menu offers <strong>Open</strong> for
        everyone, <strong>Activity</strong> for Super Admins and Super Viewers, and{" "}
        <strong>Edit</strong>/<strong>Delete</strong> for Super Admins (Zone Admins edit their
        zone from its own page instead). The <strong>Add zone</strong> button is reserved for
        Super Admins — see <a href="/docs/zones/creating/">Creating zones</a>.
      </p>

      <H2>The zone page</H2>

      <p>
        Selecting a zone opens its <strong>Records</strong> tab. Every zone page shares a header
        — the zone name and description, a <strong>Sync all</strong> button (for those who can
        manage the zone&apos;s records — see{" "}
        <a href="/docs/dns-entries/sync/">Sync &amp; drift</a>), and a kebab menu with{" "}
        <strong>Edit zone</strong> (Zone Admins), <strong>Activity</strong> (those with
        zone-activity access), and <strong>Delete zone</strong> (Super Admins only) — above the
        tab pills.
      </p>
      <p>You only see the tabs your roles allow:</p>
      <table>
        <thead>
          <tr>
            <th>Tab</th>
            <th>What it shows</th>
            <th>Who sees it</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <strong>Records</strong>
            </td>
            <td>
              Stat tiles (<strong>Records</strong>, <strong>Fully in sync</strong>,{" "}
              <strong>Drifted</strong>, <strong>Errors</strong> — same meanings as the{" "}
              <a href="/docs/dashboard/">dashboard</a>) above the zone&apos;s entries, in the
              same table as the global <a href="/docs/dns-entries/">DNS Entries</a> page but
              scoped to this zone.
            </td>
            <td>Anyone with a role on the zone, plus Super Admins and Super Viewers.</td>
          </tr>
          <tr>
            <td>
              <strong>Providers</strong>
            </td>
            <td>
              The zone&apos;s attached providers with their management controls, and
              not-yet-attached providers under <strong>Available</strong>.
            </td>
            <td>Same as Records.</td>
          </tr>
          <tr>
            <td>
              <strong>Activity</strong>
            </td>
            <td>
              The zone&apos;s audit trail: changes to the zone itself (including provider
              attach/detach) plus every entry change stamped with this zone — see{" "}
              <a href="/docs/activity/">Activity Log</a>.
            </td>
            <td>
              The zone&apos;s Zone Admins and Viewers, plus Super Admins and Super Viewers. A
              zone <strong>DNS Manager</strong> does <em>not</em> see it.
            </td>
          </tr>
          <tr>
            <td>
              <strong>Access</strong>
            </td>
            <td>Who has been granted roles on this zone.</td>
            <td>
              See <a href="/docs/zones/access/">Zone access</a> — includes User Admins, even on
              zones they otherwise cannot open.
            </td>
          </tr>
        </tbody>
      </table>
      <p>
        When the zone has no providers attached, the Providers tab shows an amber banner —{" "}
        <em>&quot;No providers attached — records in this zone are only stored locally.&quot;</em>{" "}
        — with an <strong>Attach a provider</strong> shortcut.
      </p>

      <H2>Next steps</H2>

      <Cards>
        <Card title="Create a zone" icon="zones" href="/docs/zones/creating/">
          Add a domain, understand what can (and cannot) be changed later, and what deleting a
          zone really does.
        </Card>
        <Card title="Attach providers" icon="providers" href="/docs/zones/providers/">
          Connect the zone to Cloudflare, Pi-hole, or Technitium, and import existing records.
        </Card>
        <Card title="Grant access" icon="users" href="/docs/zones/access/">
          Give users exactly the zone roles they need from the Access tab.
        </Card>
        <Card title="Manage records" icon="dns-entries" href="/docs/dns-entries/">
          Create entries, target providers per entry, and keep everything in sync.
        </Card>
      </Cards>
    </DocArticle>
  );
}
