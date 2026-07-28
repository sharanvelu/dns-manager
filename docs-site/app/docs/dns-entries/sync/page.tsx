import Callout from "@/components/docs/Callout";
import DocArticle, { docMetadata } from "@/components/docs/DocArticle";
import { H2 } from "@/components/docs/Heading";
import Screenshot from "@/components/docs/Screenshot";

export const metadata = docMetadata("dns-entries", "sync");

export default function Page() {
  return (
    <DocArticle group="dns-entries" slug="sync">
      <p>
        The app&apos;s database is the <strong>source of truth</strong>: saving an entry
        immediately queues a push to each targeted provider, and a scheduled drift check flags
        anything that was changed or removed behind the app&apos;s back. Pushes run as
        background jobs with automatic retries and backoff, so a briefly unreachable provider
        does not lose your change.
      </p>

      <Screenshot
        src="zone-records"
        alt="A zone's Records tab"
        caption="Stat tiles roll up the zone's sync health; each row shows a status chip per assigned provider."
      />

      <H2>Sync statuses</H2>

      <p>
        Each provider chip on an entry row shows one of five states — sync state is tracked{" "}
        <strong>per attachment</strong>:
      </p>
      <table>
        <thead>
          <tr>
            <th>Status</th>
            <th>Meaning</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <strong>Pending</strong>
            </td>
            <td>
              A push has been queued but has not completed yet. If entries stay pending
              forever, your queue worker is not running — see{" "}
              <a href="/docs/installation/">Installation</a>.
            </td>
          </tr>
          <tr>
            <td>
              <strong>Synced</strong>
            </td>
            <td>The provider&apos;s record matches the entry.</td>
          </tr>
          <tr>
            <td>
              <strong>Drifted</strong>
            </td>
            <td>
              The drift check found the remote record was changed or deleted outside the app.
              Hover the chip: the tooltip says whether the record differs or no longer exists
              at the provider — and when it differs, lists each drifted field with the{" "}
              <strong>tracked</strong> value (what the entry says) and the{" "}
              <strong>actual</strong> value (what the provider currently holds).
            </td>
          </tr>
          <tr>
            <td>
              <strong>Error</strong>
            </td>
            <td>
              The last push or delete failed. Hover the chip for the provider&apos;s error
              message (e.g. an invalid token or an API rejection).
            </td>
          </tr>
          <tr>
            <td>
              <strong>Deleting</strong>
            </td>
            <td>A remote delete is queued or in flight for this provider.</td>
          </tr>
        </tbody>
      </table>
      <p>
        Drift and error chips show the detail in a tooltip on hover; the same events appear in
        the <a href="/docs/dashboard/">Dashboard</a> activity feed.
      </p>

      <H2>The drift check</H2>

      <p>
        The scheduler checks every enabled provider <strong>every 15 minutes</strong> by
        default (configurable — see{" "}
        <a href="/docs/installation/configuration/">Configuration</a>), comparing remote
        records against the database. You can also trigger a check per provider at any time
        from the <a href="/docs/providers/managing/">Providers</a> page.
      </p>
      <p>
        The database wins: drifted records are flagged, never silently accepted — re-push to
        overwrite the remote state.
      </p>

      <Callout type="note">
        Pi-hole hosts entries (A/AAAA) have no TTL, so the drift check knows not to flag your
        local TTL as drift there — see the{" "}
        <a href="/docs/providers/pihole/">Pi-hole connector page</a> for connector-specific
        behavior.
      </Callout>

      <H2>Sync now</H2>

      <p>
        <strong>Sync now</strong> (in the row&apos;s kebab menu, or as a{" "}
        <a href="/docs/dns-entries/managing/#bulk-actions">bulk action</a>) re-queues a push to
        the entry&apos;s currently assigned attachments. Use it to:
      </p>
      <ul>
        <li>
          <strong>Overwrite drift</strong> — re-pushing restores the record at the provider to
          what the entry says. If the record was deleted at the provider entirely, the push
          detects that and recreates it from scratch.
        </li>
        <li>
          <strong>Retry</strong> after fixing the cause of an error.
        </li>
      </ul>
      <p>
        Re-syncing an entry that is assigned to no attachments (deliberately local-only) does
        nothing — it never falls back to pushing everywhere.
      </p>

      <H2>Sync all and the Drifted tile</H2>

      <p>
        <strong>Sync all</strong> in the zone header re-queues a push for{" "}
        <strong>every record in the zone</strong> to its currently assigned attachments — the
        bulk version of a per-entry Sync now. It appears for users who can manage the
        zone&apos;s records (<strong>Zone Admin</strong> or <strong>DNS Manager</strong>). Use
        it after re-enabling an attachment or to stomp widespread drift.
      </p>
      <p>
        For a lighter touch, when something has drifted the <strong>Drifted</strong> stat tile
        on the zone&apos;s Records tab shows a <strong>Sync</strong> button (for the same
        roles): it re-pushes <strong>only the drifted records</strong>, each to only the
        attachment it drifted on — untouched providers of the same entry are not re-pushed.
        Paused attachments keep waiting, as always.
      </p>

      <H2>Watching statuses settle</H2>

      <p>
        The entries toolbar has a <strong>refresh button</strong> that reloads just the list —
        filters, scroll position, and any open dialog stay put; the page itself never reloads.
        Next to it, an <strong>auto-reload dropdown</strong> re-fetches the list on a schedule
        (every 5s, 15s, 30s, 1m, or 5m — or off), with a countdown timer and progress ring
        showing when the next refresh fires; pressing the refresh button restarts it.
      </p>
      <p>
        Auto-reload pauses while the browser tab is in the background, and your chosen interval
        is remembered per browser. This is handy for watching sync statuses settle after a bulk
        change.
      </p>
      <p>
        Every push, delete, import, and drift check is also recorded in the sync activity feed
        on the <a href="/docs/dashboard/">Dashboard</a>.
      </p>
    </DocArticle>
  );
}
