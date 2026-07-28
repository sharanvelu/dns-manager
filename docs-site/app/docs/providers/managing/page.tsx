import Callout from "@/components/docs/Callout";
import DocArticle, { docMetadata } from "@/components/docs/DocArticle";
import { H2 } from "@/components/docs/Heading";
import Screenshot from "@/components/docs/Screenshot";
import { Step, Steps } from "@/components/docs/Steps";

export const metadata = docMetadata("providers", "managing");

export default function Page() {
  return (
    <DocArticle group="providers" slug="managing">
      <p>
        Only <strong>Super Admins</strong> can add, edit, test, enable/disable, or delete a
        provider; <strong>Super Viewers</strong> get a read-only view of the Providers page.
        Zone <strong>attachments</strong> are managed separately on each zone&apos;s Providers
        tab — see <a href="/docs/zones/providers/">Zone providers</a>.
      </p>

      <H2>Adding a provider</H2>
      <Steps>
        <Step title="Open the Providers page and click Add provider">
          <p>
            Pick a provider type: <a href="/docs/providers/cloudflare/">Cloudflare</a>,{" "}
            <a href="/docs/providers/pihole/">Pi-hole</a>, or{" "}
            <a href="/docs/providers/technitium/">Technitium</a>.
          </p>
        </Step>
        <Step title="Fill in the connection form">
          <p>
            The form is generated from the connector&apos;s own schema, so each type asks only
            for what it needs. Give the provider a display name (max 100 characters, e.g.
            &quot;Home Pi-hole&quot;) and fill in the connection fields.
          </p>
        </Step>
        <Step title="Optionally trim the managed record types">
          <p>
            At least one type must stay selected — see{" "}
            <a href="#managed-record-types">Managed record types</a>.
          </p>
        </Step>
        <Step title="Test connection and save">
          <p>
            The test runs against the values currently in the form, before anything is saved,
            and the result renders inline in the dialog.
          </p>
        </Step>
      </Steps>

      <Screenshot
        src="provider-form"
        alt="The add-provider dialog"
        caption="The connection form is generated from the connector's own schema."
      />

      <p>After saving:</p>
      <ul>
        <li>
          A <strong>Cloudflare</strong> or <strong>Technitium</strong> provider sits idle until
          you <a href="/docs/zones/providers/">attach it to a zone</a>.
        </li>
        <li>
          A <strong>Pi-hole</strong> provider attaches itself to every existing zone
          immediately.
        </li>
      </ul>

      <H2>Test connection</H2>
      <p>
        <strong>Test connection</strong> in the add/edit dialog runs against the values
        currently in the form, before anything is saved (when editing, blank secret fields fall
        back to the stored values):
      </p>
      <table>
        <thead>
          <tr>
            <th>Provider</th>
            <th>What it checks</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <strong>Cloudflare</strong>
            </td>
            <td>
              Verifies the token by listing the zones it can access; on success it reports how
              many zones are accessible. Whether a specific zone&apos;s ID is right is checked
              per attachment with the <strong>Test</strong> action on the zone page.
            </td>
          </tr>
          <tr>
            <td>
              <strong>Pi-hole</strong>
            </td>
            <td>
              Authenticates with the app password and reads the Pi-hole version; on success it
              reports the connected version.
            </td>
          </tr>
          <tr>
            <td>
              <strong>Technitium</strong>
            </td>
            <td>
              Verifies the API token by listing the zones the server hosts; on success it
              reports how many. Whether a specific zone exists is checked per attachment on the
              zone page.
            </td>
          </tr>
        </tbody>
      </table>
      <p>
        The result renders inline in the dialog, including the provider&apos;s error message on
        failure.
      </p>
      <p>
        The same connection test also runs on a schedule (every 5 minutes by default,
        configurable via <code>PROVIDER_HEALTH_CHECK_CRON</code>, disabled with{" "}
        <code>PROVIDER_HEALTH_CHECK_ENABLED=false</code>) against every enabled provider and
        keeps the health badge current between drift checks. It can also be triggered externally
        via <code>POST /api/hooks/provider-health-check</code> — see{" "}
        <a href="/docs/installation/configuration/">Configuration</a> for the webhook setup.
      </p>

      <H2>Check drift</H2>
      <p>
        <strong>Check drift</strong> queues an immediate drift check for the provider, the same
        one the scheduler runs every 15 minutes: the app lists the records at the provider (per
        attached zone for Cloudflare and Technitium; one listing covering all attached zones for
        Pi-hole), compares each assigned entry against its remote counterpart, and marks it{" "}
        <code>synced</code> or <code>drifted</code> per attachment.
      </p>
      <p>
        It only compares fields the provider actually supports (for example, TTL is never
        compared for Pi-hole hosts entries). A successful check sets the health badge to
        Healthy; a failed one sets it to Error with the message — and one zone attachment
        failing doesn&apos;t block the check for the others. Drift checks only run for enabled
        providers. See <a href="/docs/dns-entries/sync/">Sync &amp; drift</a>.
      </p>

      <H2>Managed record types</H2>
      <p>
        Each connector supports a fixed set of record types (see the{" "}
        <a href="/docs/providers/#capability-matrix">capability matrix</a>), and every provider
        instance can narrow that further with the <strong>Managed record types</strong>{" "}
        checkboxes — at least one must stay selected. Only the selected types are synced to this
        provider; entries of other types simply never target it, in any zone. Use this, for
        example, to let a Cloudflare provider manage only TXT records while another system owns
        the rest of the zone.
      </p>
      <Callout type="warning">
        If you remove a type that existing entries already use on this provider, those records
        are deleted from the provider the next time each affected entry is saved or re-synced.
      </Callout>

      <H2>Adopting existing records</H2>
      <p>
        What happens when you create an entry that{" "}
        <strong>already exists at the provider</strong> is controlled per provider by the{" "}
        <strong>Adopt existing records</strong> toggle:
      </p>
      <ul>
        <li>
          <strong>On (default)</strong> — the app adopts the existing remote record and manages
          it from then on. The remote record is aligned to your entry (TTL, proxy status, and —
          for an existing CNAME with a different target — the content too): the app&apos;s
          database wins, consistently with drift handling. This is the way to start managing
          records that predate DNS Manager: create the same entry here and it takes over. (To
          take over many records at once, use{" "}
          <a href="/docs/zones/providers/">Import records</a> instead.)
        </li>
        <li>
          <strong>Off</strong> — creating an entry that already exists at the provider fails,
          and the entry&apos;s status chip shows an error explaining the conflict. Use this if
          you want the app to only ever touch records it created itself.
        </li>
      </ul>
      <p>
        Adoption applies only to unambiguous matches (same name and type). If Cloudflare holds
        several records on the same name (round-robin A records) and none matches your
        entry&apos;s content exactly, the conflict is reported as an error instead of guessing.
      </p>

      <H2>Enable / Disable</H2>
      <p>
        Disabling a provider <strong>pauses it everywhere</strong>:
      </p>
      <ul>
        <li>
          Nothing is pushed to it — it disappears from the provider checkboxes in the entry form
          for every zone, and scheduled drift and health checks skip it. Its zone attachment
          cards show a <strong>Provider disabled</strong> badge.
        </li>
        <li>
          Its records are <strong>protected from deletion</strong>: existing
          entry-to-attachment assignments are kept, and saving entries never triggers remote
          deletes against a disabled provider.
        </li>
      </ul>
      <p>
        Re-enable the provider and use <strong>Sync now</strong> on affected entries (or{" "}
        <strong>Sync all</strong> on affected zones) to bring it back up to date.
      </p>
      <Callout type="tip">
        To pause a provider for just one zone, disable the attachment on the zone page instead —
        see <a href="/docs/zones/providers/">Zone providers</a>.
      </Callout>

      <H2>Editing a provider</H2>
      <p>
        Edit changes the name, connection settings, managed record types, and enabled flag; the
        provider <em>type</em> cannot be changed after creation.
      </p>
      <p>
        Secret fields show a masked placeholder (&quot;•••••••• (unchanged — leave blank to
        keep)&quot;) — <strong>leave a secret blank to keep the stored value</strong>; only type
        a new one to replace it. Zone-specific settings are edited on each attachment, not here.
      </p>

      <H2>Deleting a provider</H2>
      <p>
        Deleting a provider removes it, <strong>detaches it from all of its zones</strong>, and
        drops its sync states and history from the app.
      </p>
      <Callout type="important">
        <strong>Records at the provider are left untouched</strong> — the app just stops
        managing them. The confirmation dialog states exactly that, including how many zones it
        will be detached from. If you want the remote records gone, delete the entries (or
        deselect this provider on them) <em>before</em> deleting the provider.
      </Callout>

      <H2>How credentials are stored</H2>
      <p>
        Provider configuration (API tokens, app passwords, URLs) is encrypted at rest in the
        database with AES-256 using your <code>APP_KEY</code>. Secrets are never included in
        pages sent to the browser — edit forms receive blank secret fields.
      </p>
      <Callout type="caution">
        If you lose <code>APP_KEY</code>, stored credentials cannot be decrypted and must be
        re-entered. See <a href="/docs/installation/configuration/">Configuration</a>.
      </Callout>
    </DocArticle>
  );
}
