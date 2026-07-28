import Callout from "@/components/docs/Callout";
import DocArticle, { docMetadata } from "@/components/docs/DocArticle";
import { H2 } from "@/components/docs/Heading";

export const metadata = docMetadata("providers", "pihole");

export default function Page() {
  return (
    <DocArticle group="providers" slug="pihole">
      <p>
        The Pi-hole connector manages <strong>local DNS for your homelab</strong> — the hosts
        and CNAME records your Pi-hole answers with on your LAN.
      </p>

      <Callout type="important">
        Requires <strong>Pi-hole v6</strong> (the REST API generation). Earlier versions are not
        supported.
      </Callout>

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
              The Pi-hole address, e.g. <code>https://pihole.local</code> — no trailing slash.
            </td>
          </tr>
          <tr>
            <td>
              <strong>App password</strong>
            </td>
            <td>
              Generate one in Pi-hole under{" "}
              <strong>Settings → Web interface/API → app password</strong>. This is separate
              from your admin login password.
            </td>
          </tr>
          <tr>
            <td>
              <strong>Verify TLS certificate</strong>
            </td>
            <td>On by default. Turn it off when your Pi-hole serves a self-signed certificate.</td>
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

      <H2>Zoneless: auto-attach with opt-out</H2>
      <p>
        Pi-hole has no zone concept — it answers for any name. A Pi-hole provider is therefore{" "}
        <strong>zoneless</strong>:
      </p>
      <ul>
        <li>
          It attaches itself to <strong>every existing zone immediately</strong> when you create
          it, and to every zone you create later.
        </li>
        <li>
          Its card shows an <strong>All zones</strong> badge instead of an attach button, with
          opted-out zones struck through.
        </li>
        <li>
          To keep a zone off the Pi-hole, <strong>opt that zone out</strong> on the zone&apos;s
          Providers tab — see <a href="/docs/zones/providers/">Zone providers</a>. Opting out
          never deletes records at the Pi-hole, and nothing auto-re-attaches an opted-out zone.
        </li>
      </ul>

      <H2>Record types and TTL</H2>
      <ul>
        <li>
          <strong>Record types</strong>: A, AAAA, CNAME only.
        </li>
        <li>
          A/AAAA records become local <strong>hosts entries</strong>, which have no TTL; CNAME
          records support an optional TTL. TTL is excluded from drift comparison.
        </li>
        <li>No proxying, no priority.</li>
      </ul>

      <H2>Behavior to know about</H2>
      <ul>
        <li>
          <strong>CNAME targets must be resolvable by Pi-hole itself</strong> — Pi-hole only
          answers local CNAMEs whose target it can already resolve (another local record, or an
          upstream-resolvable name). An unresolvable target results in a record that does not
          answer, even though it syncs fine.
        </li>
        <li>
          <strong>Edits are delete-then-recreate</strong> — Pi-hole has no in-place update, so
          edits are applied as delete-then-recreate on the Pi-hole side. This is normal and
          momentary.
        </li>
        <li>
          <strong>Session handling</strong> — the app authenticates per operation and releases
          its session immediately afterwards, so it stays well under Pi-hole&apos;s
          concurrent-session cap.
        </li>
        <li>
          <strong>CNAME changes restart the resolver</strong> — Pi-hole restarts its DNS
          resolver after every CNAME change, taking its API offline for a few seconds. The app
          accounts for this: operations against a Pi-hole run one at a time, and after each
          CNAME change the next operation waits a few seconds for the resolver to come back. A
          bulk sync touching many CNAME records completes gradually — entries flip to{" "}
          <strong>Synced</strong> one by one rather than failing while Pi-hole restarts.
        </li>
        <li>
          <strong>Import is per zone</strong> — Pi-hole holds records for <em>all</em> your
          zones in one flat list, so importing from it happens per zone; records outside the
          zone you&apos;re importing into are skipped and counted. See{" "}
          <a href="/docs/zones/providers/">Zone providers</a>.
        </li>
        <li>
          <strong>Test connection</strong> authenticates with the app password and reads the
          Pi-hole version; on success it reports the connected version.
        </li>
      </ul>
    </DocArticle>
  );
}
