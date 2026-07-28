import Callout from "@/components/docs/Callout";
import CodeBlock from "@/components/docs/CodeBlock";
import DocArticle, { docMetadata } from "@/components/docs/DocArticle";
import { H2, H3 } from "@/components/docs/Heading";
import Screenshot from "@/components/docs/Screenshot";

export const metadata = docMetadata("activity");

export default function Page() {
  return (
    <DocArticle group="activity">
      <p>
        The activity log is DNS Manager&apos;s audit trail: a permanent record of{" "}
        <strong>who changed what, and when</strong>. Every change made through the app is
        attributed to the signed-in user who made it. It is separate from the{" "}
        <a href="/docs/dashboard/">Dashboard</a> activity feed, which tracks background sync
        jobs — see <a href="#what-is-not-recorded">What is not recorded</a>.
      </p>

      <Screenshot
        src="activity"
        alt="The global activity log"
        caption="Settings → Activity: the global trail, newest first, with combinable filters and expandable old → new diffs."
      />

      <H2>What is recorded</H2>
      <table>
        <thead>
          <tr>
            <th>Area</th>
            <th>Events</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <strong>Zones</strong>
            </td>
            <td>
              Created, updated, and deleted, with old → new diffs for the name and description.
              Attachment changes are logged on the zone: <strong>provider-attached</strong>,{" "}
              <strong>attachment-updated</strong> (settings or per-zone enable/disable — recorded
              as the fact that the configuration changed, never any value), and{" "}
              <strong>provider-detached</strong>, each naming the provider involved.
            </td>
          </tr>
          <tr>
            <td>
              <strong>DNS entries</strong>
            </td>
            <td>
              Created, updated, and deleted, with field-level old → new diffs for name, type,
              content, TTL, priority, proxied, and comment. Every entry event is{" "}
              <strong>stamped with its zone</strong>, and the stamp survives even after the
              entry — or the whole zone — is deleted. When a deletion is deferred to queued
              provider cleanup, a <strong>delete-requested</strong> event attributes the request
              to the user who asked for it (the eventual <strong>deleted</strong> event is
              recorded by the system once the last provider confirms). Bulk provider reassignment
              logs a <strong>providers-changed</strong> event listing the providers each entry
              now syncs to.
            </td>
          </tr>
          <tr>
            <td>
              <strong>Providers</strong>
            </td>
            <td>
              Created, updated, and deleted, covering the name, type, enabled flag, and managed
              record types. A credential change is recorded as{" "}
              <strong>updated connection settings</strong> — see below.
            </td>
          </tr>
          <tr>
            <td>
              <strong>Users</strong>
            </td>
            <td>
              Name, email, and global role changes. Role changes show the old → new roles and
              are attributed to the admin who made them.{" "}
              <strong>Zone access grants are audited here too</strong>: granting, changing, or
              revoking a user&apos;s zone roles produces a <strong>zone-access-granted</strong>,{" "}
              <strong>zone-access-updated</strong>, or <strong>zone-access-revoked</strong> event
              naming the user, the zone, and the granted roles.
            </td>
          </tr>
          <tr>
            <td>
              <strong>Sign-ins</strong>
            </td>
            <td>
              A <strong>login</strong> and <strong>logout</strong> event per user (via your OIDC
              provider).
            </td>
          </tr>
        </tbody>
      </table>

      <H3>What is not recorded</H3>
      <Callout type="important">
        <strong>Provider secrets, ever.</strong> API tokens, app passwords, and other connection
        settings — including per-zone attachment settings — are never written to the activity
        log, not even in encrypted form. Changing credentials or attachment configuration
        produces an event that carries only the fact that the configuration changed, never any
        value.
      </Callout>
      <p>
        <strong>Background sync and health checks</strong> — pushes, remote deletes, imports,
        drift checks, and provider health checks — are machine activity, not user actions. They
        appear in the <a href="/docs/dashboard/">Dashboard</a> recent-activity feed instead, and
        provider health status updates never generate audit events.
      </p>

      <H2>Where to see it</H2>
      <p>
        Visibility follows the <a href="/docs/users/">role system</a>:
      </p>
      <table>
        <thead>
          <tr>
            <th>View</th>
            <th>Who sees it</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <strong>Global trail</strong> (sidebar <strong>Settings → Activity</strong> —
              everything across zones, providers, users, sign-ins)
            </td>
            <td>
              <strong>Super Admins</strong> and <strong>Super Viewers</strong> only.
            </td>
          </tr>
          <tr>
            <td>
              <strong>Zone Activity tab</strong> (one zone&apos;s history)
            </td>
            <td>
              The zone&apos;s <strong>Zone Admins</strong> and <strong>Viewers</strong>, plus
              Super Admins and Super Viewers.
            </td>
          </tr>
          <tr>
            <td>
              <strong>Per-record Activity dialogs</strong> (kebab menus)
            </td>
            <td>
              Entry dialogs follow the entry&apos;s zone: Zone Admin / Viewer of that zone, plus
              the supers. Provider dialogs and the zones-list dialog are global views — Super
              Admins and Super Viewers only.
            </td>
          </tr>
        </tbody>
      </table>
      <Callout type="note">
        A zone <strong>DNS Manager</strong> deliberately has{" "}
        <strong>no activity access at all</strong> — they can change records but not read the
        audit trail; grant the Viewer role alongside if they should. Every entry point — sidebar
        item, tabs, menu items — is hidden for anyone not allowed, and the server enforces the
        same rules regardless of what the browser sends.
      </Callout>

      <H3>The global viewer</H3>
      <p>
        The <strong>Activity</strong> page (sidebar, Settings group) lists activities
        newest-first, paginated. Filters combine, and expanding a row shows the field-level
        changes as old → new values:
      </p>
      <ul>
        <li>
          <strong>Subject type</strong> — Entries, Providers, Users, or Zones.
        </li>
        <li>
          <strong>Specific record</strong> — narrow to one entry, provider, user, or zone.
        </li>
        <li>
          <strong>Event</strong> — created, updated, deleted, provider-attached, login, and so
          on.
        </li>
        <li>
          <strong>User</strong> — who performed the action.
        </li>
        <li>
          <strong>Date range</strong> — from/to.
        </li>
      </ul>

      <H3>Per-zone activity</H3>
      <p>
        Each zone has its own pre-filtered view — the <strong>Activity tab</strong> on the{" "}
        <a href="/docs/zones/">zone&apos;s page</a>, visible to the zone&apos;s Zone Admins and
        Viewers as well as the supers. It shows everything that happened in the zone: events on
        the zone itself (edits, attachments) plus every entry event stamped with the zone. The
        same scoping is available in the full viewer via the <code>zone_id</code> query
        parameter. Because the zone stamp is stored on each activity, an entry&apos;s history
        remains attributed to its zone even after the entry is deleted.
      </p>

      <H3>Quick access from a record</H3>
      <p>
        Every record type offers an <strong>Activity</strong> item that opens a dialog with its
        history: entry rows on the <a href="/docs/dns-entries/">DNS Entries</a> page and the
        zone page header follow zone-activity access (Zone Admin / Viewer of that zone, plus the
        supers); provider cards on the <a href="/docs/providers/">Providers</a> page and the
        zones-list kebab appear for Super Admins and Super Viewers. The{" "}
        <strong>Open full activity log</strong> link at the bottom of the dialog jumps to the
        global viewer pre-filtered to the record — it is shown only to Super Admins and Super
        Viewers, since the full viewer is the global trail.
      </p>
      <p>
        Activities are never removed when their record is deleted — the full history of a
        deleted zone, entry, provider, or user stays viewable, with the record&apos;s name
        recovered from its last logged snapshot.
      </p>

      <H2>Retention &amp; flushing</H2>
      <table>
        <thead>
          <tr>
            <th>Setting</th>
            <th>Effect</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <em>(default)</em>
            </td>
            <td>
              Activities are kept <strong>forever</strong>.
            </td>
          </tr>
          <tr>
            <td>
              <code>ACTIVITY_LOGS_RETENTION_DAYS=365</code>
            </td>
            <td>
              A built-in schedule deletes activities older than that many days{" "}
              <strong>once a day at midnight</strong>. Registered whenever the variable is set,
              independent of <code>SCHEDULER_ENABLED</code> (which only governs the drift/health
              checks).
            </td>
          </tr>
          <tr>
            <td>
              <code>ACTIVITYLOG_ENABLED=false</code>
            </td>
            <td>Disables audit logging entirely.</td>
          </tr>
        </tbody>
      </table>
      <p>
        To wipe the audit trail on demand (rather than on the retention schedule), run the flush
        command manually:
      </p>
      <CodeBlock lang="sh">{`
        php artisan dns:flush-activities            # everything, asks for confirmation
        php artisan dns:flush-activities --days=90  # only records older than 90 days
        php artisan dns:flush-activities --force    # skip the confirmation prompt
      `}</CodeBlock>
      <p>The command reports how many records it deleted.</p>
      <Callout type="warning">
        Flushing permanently erases audit history — who changed what is unrecoverable
        afterwards. It does not touch the{" "}
        <a href="/docs/dashboard/">dashboard&apos;s sync activity feed</a>, which is a separate
        log of background jobs.
      </Callout>
    </DocArticle>
  );
}
