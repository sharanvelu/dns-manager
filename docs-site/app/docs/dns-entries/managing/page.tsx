import Callout from "@/components/docs/Callout";
import DocArticle, { docMetadata } from "@/components/docs/DocArticle";
import { H2 } from "@/components/docs/Heading";
import Screenshot from "@/components/docs/Screenshot";
import { Step, Steps } from "@/components/docs/Steps";

export const metadata = docMetadata("dns-entries", "managing");

export default function Page() {
  return (
    <DocArticle group="dns-entries" slug="managing">
      <p>
        Creating, editing, and deleting records requires the <strong>Zone Admin</strong> or{" "}
        <strong>DNS Manager</strong> role on the entry&apos;s zone (Super Admins can do
        everything) — see <a href="/docs/zones/access/">Zone access</a>.
      </p>

      <H2>Create an entry</H2>

      <Steps>
        <Step title="Click Add entry">
          <p>
            The button (like <strong>Import CSV</strong>) appears only when you can manage
            records in at least one zone, and is disabled until a{" "}
            <a href="/docs/zones/">zone</a> exists, since entries live in zones.
          </p>
        </Step>
        <Step title="Pick the zone first">
          <p>
            The zone determines the FQDN and which providers are offered; the select offers
            only the zones you can manage. On a zone&apos;s Records tab the zone is pre-set.
          </p>
        </Step>
        <Step title="Fill the form">
          <p>
            The form adapts to the record type you pick: <strong>Priority</strong> appears only
            for MX and SRV (where it is required), and <strong>Proxied</strong> appears only
            when at least one targeted provider supports proxying (Cloudflare). See the{" "}
            <a href="/docs/dns-entries/#anatomy-of-an-entry">field reference</a> and{" "}
            <a href="/docs/dns-entries/#zone-relative-names">zone-relative name rules</a>.
          </p>
        </Step>
        <Step title="Choose the providers">
          <p>
            The <strong>Sync to providers</strong> panel at the bottom controls where the entry
            syncs — see <a href="#per-entry-provider-targeting">below</a>.
          </p>
        </Step>
        <Step title="Save">
          <p>
            Saving creates the entry and immediately queues a push to every selected provider —
            see <a href="/docs/dns-entries/sync/">Sync &amp; drift</a>.
          </p>
        </Step>
      </Steps>

      <Screenshot
        src="entry-form"
        alt="The Add entry dialog"
        caption="The form adapts to the record type — priority for MX/SRV, proxied only when a proxy-capable provider is targeted."
      />

      <H2>Per-entry provider targeting</H2>

      <p>
        At the bottom of the form, a <strong>Sync to providers</strong> panel lists the chosen
        zone&apos;s <em>enabled</em> provider{" "}
        <a href="/docs/zones/providers/">attachments</a> that manage the chosen record type, as
        checkboxes:
      </p>
      <ul>
        <li>
          <strong>All compatible providers are checked by default</strong> when creating an
          entry.
        </li>
        <li>
          <strong>Changing the record type — or the zone — resets the selection</strong> back
          to the default (all compatible attachments).
        </li>
        <li>
          <strong>Unchecking every provider is allowed</strong> — the entry is stored locally
          only. An amber warning tells you so: &quot;No providers selected — the entry will
          only be stored locally&quot; (and, when editing, &quot;...and removed from providers
          it currently syncs to&quot;).
        </li>
        <li>
          If <strong>no enabled attachment manages the chosen type at all</strong>, the panel
          itself turns amber: &quot;No enabled provider attached to this zone manages{" "}
          <code>TYPE</code> records — this entry will not sync anywhere.&quot; You can still
          save the entry.
        </li>
      </ul>
      <p>
        Disabled attachments, disabled providers, and providers whose managed record types
        exclude the chosen type never appear in the list. Providers attached to{" "}
        <em>other</em> zones are never offered —{" "}
        <a href="/docs/zones/providers/">attach the provider to this zone</a> first.
      </p>

      <H2>Edit an entry</H2>

      <p>Editing works like creating, with three differences:</p>
      <ul>
        <li>
          The <strong>zone is fixed</strong> — an entry cannot move between zones.
        </li>
        <li>
          The provider selection reflects the entry&apos;s <strong>current assignment</strong>,
          not the default.
        </li>
        <li>
          <strong>Deselecting a provider deletes the record from that provider</strong> when
          you save — the app pushes the change to the still-selected attachments and queues a
          remote delete on the deselected ones. Exception: attachments that are currently{" "}
          <em>paused</em> (attachment disabled for the zone, or provider disabled globally) are
          left untouched — their records stay in place and their assignment is kept until
          re-enabled.
        </li>
      </ul>

      <Callout type="warning">
        Changing the record type in the edit form also resets the provider selection to all
        compatible attachments, so re-check it before saving.
      </Callout>

      <H2>Bulk actions</H2>

      <p>
        Tick the checkbox on one or more rows (the header checkbox selects the whole page;
        selections accumulate across pages) and a <strong>bulk actions bar</strong> appears
        above the table showing the count, a <strong>Clear</strong> button, and four actions
        applied to every selected entry. Selection is limited to entries in zones you can
        manage — read-only rows have no checkbox, and the server drops any entry you cannot
        manage from a bulk request rather than failing it.
      </p>
      <table>
        <thead>
          <tr>
            <th>Action</th>
            <th>What it does</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <strong>Sync now</strong>
            </td>
            <td>
              Re-queues a push for each entry to its currently assigned attachments, exactly
              like the per-row action.
            </td>
          </tr>
          <tr>
            <td>
              <strong>Providers</strong>
            </td>
            <td>Changes each entry&apos;s provider assignment — see below.</td>
          </tr>
          <tr>
            <td>
              <strong>Edit</strong>
            </td>
            <td>
              Bulk-edits the <strong>type</strong>, <strong>value</strong>,{" "}
              <strong>TTL</strong>, and/or <strong>comment</strong> (works across zones).
            </td>
          </tr>
          <tr>
            <td>
              <strong>Delete</strong>
            </td>
            <td>
              After a confirmation dialog, removes each entry from every provider it is
              assigned to and then from the list, with the same semantics as{" "}
              <a href="#deleting-entries">single delete</a>.
            </td>
          </tr>
        </tbody>
      </table>
      <p>
        <strong>Providers</strong> works in one of three modes picked at the top of the dialog:
      </p>
      <ul>
        <li>
          <strong>Replace</strong> (the default) makes your selection the entire assignment:
          records sync to the ticked attachments and are{" "}
          <strong>removed from unticked ones</strong>. It starts with everything ticked;
          ticking nothing turns the entries local-only.
        </li>
        <li>
          <strong>Attach</strong> adds the ticked attachments to each entry&apos;s assignment
          and pushes the records to them — existing assignments are kept as they are (nothing
          is re-pushed or removed). The go-to move after attaching a new provider to a zone
          whose entries should also sync there.
        </li>
        <li>
          <strong>Detach</strong> removes exactly the ticked attachments from each entry&apos;s
          assignment and deletes the records from those providers — every other assignment is
          untouched.
        </li>
      </ul>
      <p>
        Because targeting is defined per zone, this action requires the selection to stay{" "}
        <strong>within a single zone</strong> — with entries from several zones selected, the
        button is disabled with the hint &quot;Select entries from a single zone to change
        providers.&quot; Attachments that don&apos;t manage an entry&apos;s record type are
        skipped for that entry (nothing is force-pushed somewhere incompatible), attach/detach
        require at least one ticked provider, and — as always — paused attachments are left
        untouched, not purged.
      </p>
      <p>
        For <strong>Edit</strong>: tick only the fields you want to change; unticked fields
        keep each entry&apos;s current value. An empty TTL means automatic, and an empty
        comment clears it. Every entry is re-validated with the change merged in — entries that
        would become invalid (say, changing the type to A when the value isn&apos;t an IPv4
        address) or that would duplicate another entry in their zone are{" "}
        <strong>skipped, never half-applied</strong>, and the result message reports how many
        were updated and how many skipped and why. Changing the type re-targets attachments
        automatically: attachments that don&apos;t manage the new type get a remote delete, and
        priority is cleared when the new type doesn&apos;t carry one. Successful edits push to
        each entry&apos;s assigned attachments.
      </p>
      <p>
        Entries deleted elsewhere while selected are silently dropped from the action rather
        than failing it.
      </p>

      <H2>Import entries from CSV</H2>

      <p>
        The <strong>Import CSV</strong> button in the toolbar opens a modal for bulk-creating
        entries. The import is <strong>zone-scoped</strong>: pick the target zone first
        (pre-set on a zone&apos;s Records tab).
      </p>
      <ul>
        <li>
          <strong>Format</strong>: a header row followed by data rows. Columns:{" "}
          <code>name, type, content, ttl, priority, proxied, comment</code> — only{" "}
          <code>name</code>, <code>type</code>, and <code>content</code> are required; the rest
          may be omitted or left empty. <strong>Names are zone-relative</strong> (
          <code>www</code>, <code>@</code> for the apex, <code>*.app</code>); full hostnames
          under the zone are relativized automatically. <code>proxied</code> accepts{" "}
          <code>true</code>/<code>false</code> (blank = false). Download the{" "}
          <strong>sample file</strong> from the modal to start from a working template.
        </li>
        <li>
          <strong>Validation</strong>: every row is validated with the same rules as the entry
          form. Invalid rows are rejected individually — the modal reports each one with its
          line number and reason, while valid rows still import.
        </li>
        <li>
          <strong>Duplicates</strong>: rows matching an existing entry in the zone (same name,
          type, and content) are skipped and counted in the result summary.
        </li>
        <li>
          <strong>Provider targeting</strong>: imported entries sync to every compatible
          enabled attachment of the zone (the default targeting). To narrow an imported entry
          to specific providers, edit it afterwards.
        </li>
        <li>
          <strong>Limits</strong>: up to 1000 rows per file, 1 MB max.
        </li>
      </ul>
      <p>
        To pull existing records <em>from a provider</em> instead, use{" "}
        <strong>Import records</strong> on the zone&apos;s attachment card — see{" "}
        <a href="/docs/zones/providers/#import-records-from-a-provider">
          Import records from a provider
        </a>
        .
      </p>

      <H2>Deleting entries</H2>

      <p>
        <strong>Delete</strong> (kebab menu, with a confirmation dialog) removes the record
        from every provider it is assigned to first; the local entry row disappears once the
        last provider confirms the deletion. Until then the entry remains visible with its
        chips in the <strong>Deleting</strong> state. If the entry was never pushed anywhere
        (local-only), it is removed immediately.
      </p>

      <Callout type="caution">
        Deleting an entry <strong>deletes its records at the providers</strong> it is assigned
        to. Note the asymmetry with{" "}
        <a href="/docs/zones/creating/#delete-a-zone">zone deletion</a>: deleting an{" "}
        <em>entry</em> deletes its remote records; deleting a <em>zone</em> (or detaching a
        provider) never does.
      </Callout>

      <p>
        Records held by <em>paused</em> attachments (attachment disabled for the zone, or
        provider disabled globally) are <strong>left in place at the provider</strong> —
        deletes are never sent through a disabled provider, so those records become unmanaged
        once the entry is gone.
      </p>
    </DocArticle>
  );
}
