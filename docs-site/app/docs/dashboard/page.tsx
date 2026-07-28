import Callout from "@/components/docs/Callout";
import { Card, Cards } from "@/components/docs/Cards";
import DocArticle, { docMetadata } from "@/components/docs/DocArticle";
import { H2 } from "@/components/docs/Heading";
import Screenshot from "@/components/docs/Screenshot";

export const metadata = docMetadata("dashboard");

export default function Page() {
  return (
    <DocArticle group="dashboard">
      <p>
        The dashboard is the landing page after sign-in. It answers one question at a glance:{" "}
        <strong>is everything in sync, and if not, where do I look?</strong>
      </p>

      <Screenshot
        src="dashboard"
        alt="The dashboard"
        caption="Stat tiles, zone cards, provider health, and recent sync activity at a glance."
      />

      <H2>What you see depends on your access</H2>
      <p>
        Super Admins and Super Viewers see the full dashboard described below. Users whose
        access comes from <a href="/docs/users/">zone grants</a> get a <strong>scoped</strong>{" "}
        dashboard: the stat tiles, zone cards, and recent-activity feed cover{" "}
        <strong>only their zones</strong>, and the provider cards section is omitted entirely
        (provider health is a global concern — the &quot;Providers healthy&quot; chip reads 0/0
        for them).
      </p>
      <p>
        A user with <strong>no zone access at all</strong> sees a &quot;no access yet&quot;
        placeholder instead — <em>
          &quot;No access yet — ask an administrator to grant you access to a zone.&quot;
        </em>{" "}
        For User Admins the placeholder points at their actual job instead, with a{" "}
        <strong>Go to Users</strong> button.
      </p>

      <H2>Stat tiles</H2>
      <table>
        <thead>
          <tr>
            <th>Tile</th>
            <th>Exact meaning</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <strong>Total managed entries</strong>
            </td>
            <td>
              Number of DNS entries stored in the app across all zones you can access,
              regardless of sync state — including local-only entries that target no provider.
            </td>
          </tr>
          <tr>
            <td>
              <strong>Fully in sync</strong>
            </td>
            <td>
              Entries that are assigned to at least one provider attachment <strong>and</strong>{" "}
              whose every assigned attachment reports <code>synced</code>. A local-only entry
              (no providers) does not count as in sync.
            </td>
          </tr>
          <tr>
            <td>
              <strong>Drifted</strong>
            </td>
            <td>
              Entries with <strong>at least one</strong> attachment in the <code>drifted</code>{" "}
              state — the remote record differs from the entry, or no longer exists at the
              provider. An entry that is fine on Cloudflare but drifted on Pi-hole counts here.
            </td>
          </tr>
          <tr>
            <td>
              <strong>Errors</strong>
            </td>
            <td>
              Entries with at least one attachment in the <code>error</code> state — the last
              push or delete for that provider failed.
            </td>
          </tr>
        </tbody>
      </table>
      <p>
        Tiles carry an accent color only when the state warrants it: the sync tile turns{" "}
        <strong>green</strong> when everything is fully in sync, and the Drifted/Errors tiles
        turn <strong>amber/red only when their count is above zero</strong> — a quiet dashboard
        is a healthy one.
      </p>
      <p>
        Because an entry can be drifted on one provider and errored on another, the Drifted and
        Errors tiles can both count the same entry. Next to the section heading, a{" "}
        <strong>Providers healthy</strong> chip shows <code>X/Y</code>: providers whose most
        recent check (scheduled health check or drift check) succeeded, out of all configured
        providers.
      </p>

      <H2>Zone cards</H2>
      <p>
        Each <a href="/docs/zones/">zone</a> you can access gets a card showing its record
        count, one small icon per attached provider type, and a status rollup. The status line
        renders <strong>only when it says something</strong>: emerald{" "}
        <strong>All in sync</strong> only when the zone has records and every one is fully
        synced, plus <em>N drifted</em> (amber) and <em>N errors</em> (red) counts only when
        something is off — a zone with nothing to report shows no status line at all.
      </p>
      <p>
        Click a card to open the zone&apos;s Records tab. Until you create your first zone, this
        section walks you there instead.
      </p>

      <H2>Provider cards</H2>
      <p>Each configured provider gets a card showing:</p>
      <ul>
        <li>
          <strong>Health badge</strong>
          <ul>
            <li>
              <strong>Healthy</strong> — the last check completed successfully. The badge is
              refreshed by the scheduled connectivity health check (every 5 minutes by default)
              and by every drift check.
            </li>
            <li>
              <strong>Error</strong> — the last check failed; hover the badge to read the exact
              error message (bad credentials, unreachable host, ...).
            </li>
            <li>
              <strong>Not checked</strong> — the provider has never been checked. A check is
              queued automatically when you add a provider, so this state is normally brief.
            </li>
          </ul>
        </li>
        <li>
          <strong>Record counts</strong> — how many records (across all zones) are assigned to
          this provider, how many are in sync, and drifted/errored counts when nonzero.
        </li>
        <li>
          <strong>Disabled</strong> — a muted badge when the provider is disabled. Disabled
          providers are paused: nothing is pushed to them and their records are protected from
          deletion.
        </li>
      </ul>
      <p>
        The timestamp on each card is the time of the last check (health or drift). See{" "}
        <a href="/docs/providers/">Providers</a> for the full card and its actions.
      </p>

      <H2>Recent activity</H2>
      <p>The feed shows the 15 most recent sync-log events. Every background job writes one:</p>
      <table>
        <thead>
          <tr>
            <th>Action</th>
            <th>Meaning</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <code>push</code>
            </td>
            <td>An entry was created or updated at a provider.</td>
          </tr>
          <tr>
            <td>
              <code>delete</code>
            </td>
            <td>An entry was removed from a provider.</td>
          </tr>
          <tr>
            <td>
              <code>import</code>
            </td>
            <td>
              Records were imported from a provider into a zone (result: how many were created
              and updated).
            </td>
          </tr>
          <tr>
            <td>
              <code>drift-check</code>
            </td>
            <td>
              A provider&apos;s records were compared against the database (result: how many
              records were checked and how many drifted).
            </td>
          </tr>
          <tr>
            <td>
              <code>provider-health-check</code>
            </td>
            <td>
              A provider&apos;s connectivity was tested with the connector&apos;s connection
              test (same as <strong>Test connection</strong> in the UI).
            </td>
          </tr>
        </tbody>
      </table>
      <p>
        Each event carries a success or error status and a message, plus the zone, provider, and
        entry involved.
      </p>
      <Callout type="note">
        This feed tracks background sync jobs, not user actions — for the audit trail of who
        changed what, see the <a href="/docs/activity/">Activity Log</a>.
      </Callout>

      <H2>When something is amber or red</H2>
      <ul>
        <li>
          <strong>Drifted entries</strong> — someone or something changed records directly at
          the provider. The zone card tells you which zone; open its Records tab (or{" "}
          <a href="/docs/dns-entries/">DNS Entries</a>), hover the amber chip to see exactly
          which fields drifted (tracked vs actual values), and use <strong>Sync now</strong> to
          re-push the entry (the app&apos;s database wins and overwrites the remote record). If
          the remote change is the one you want, edit the entry to match instead. The{" "}
          <strong>Sync</strong> button on the Records tab&apos;s Drifted tile re-pushes only the
          drifted records to only the providers they drifted on; <strong>Sync all</strong> on
          the zone header stomps widespread drift in one go. See{" "}
          <a href="/docs/dns-entries/sync/">Sync &amp; drift</a>.
        </li>
        <li>
          <strong>Errored entries</strong> — hover the red chip on the entry row for the exact
          error, fix the cause, then <strong>Sync now</strong>. Failed jobs also retry
          automatically with backoff.
        </li>
        <li>
          <strong>Unhealthy provider</strong> — open <a href="/docs/providers/">Providers</a>,
          edit the provider, use <strong>Test connection</strong> to diagnose, and run{" "}
          <strong>Check drift</strong> once it is fixed. See{" "}
          <a href="/docs/providers/managing/">Managing providers</a>.
        </li>
      </ul>

      <H2>Go deeper</H2>
      <Cards>
        <Card title="DNS Zones" icon="zones" href="/docs/zones/">
          Create zones, attach providers, and manage per-zone access.
        </Card>
        <Card title="Sync & drift" icon="sync" href="/docs/dns-entries/sync/">
          Status chips, drift detection, and how to bring things back in sync.
        </Card>
        <Card title="Providers" icon="providers" href="/docs/providers/">
          Provider health, capability matrix, and per-connector guides.
        </Card>
        <Card title="Activity Log" icon="activity" href="/docs/activity/">
          The audit trail of who changed what, with field-level diffs.
        </Card>
      </Cards>
    </DocArticle>
  );
}
