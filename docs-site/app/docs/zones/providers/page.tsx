import Callout from "@/components/docs/Callout";
import DocArticle, { docMetadata } from "@/components/docs/DocArticle";
import { H2, H3 } from "@/components/docs/Heading";
import Screenshot from "@/components/docs/Screenshot";
import { Step, Steps } from "@/components/docs/Steps";

export const metadata = docMetadata("zones", "providers");

export default function Page() {
  return (
    <DocArticle group="zones" slug="providers">
      <p>
        Providers hold credentials and are configured <strong>once</strong> on the{" "}
        <a href="/docs/providers/">Providers</a> page (Super Admins); a zone uses a provider
        through an <strong>attachment</strong>, which carries only the zone-specific settings.
        Managing a zone&apos;s attachments requires the <strong>Zone Admin</strong> or{" "}
        <strong>Provider Manager</strong> role on that zone.
      </p>

      <Screenshot
        src="zone-providers"
        alt="A zone's Providers tab"
        caption="Attached providers as cards with their controls, and not-yet-attached providers under Available."
      />

      <H2>Attach a provider</H2>

      <Steps>
        <Step title="Open the zone's Providers tab">
          <p>
            Attached providers appear as cards; every not-yet-attached enabled provider is
            listed under <strong>Available</strong>.
          </p>
        </Step>
        <Step title="Click Attach">
          <p>
            Use the <strong>Attach</strong> button next to an available provider, the{" "}
            <strong>Attach provider</strong> button, or <strong>Attach to zone</strong> on the
            provider&apos;s own card on the Providers page.
          </p>
        </Step>
        <Step title="Fill the zone-specific settings">
          <p>
            The attach dialog reminds you: <em>the provider&apos;s credentials are reused —
            only zone-specific settings live on the attachment.</em> For connectors with zone
            settings (Cloudflare), discovery runs automatically — see below.
          </p>
        </Step>
      </Steps>

      <H3>Zone discovery (Cloudflare)</H3>

      <p>
        For connectors with zone-specific settings, the dialog <strong>auto-discovers</strong>{" "}
        them the moment the provider/zone pair is chosen. For Cloudflare it looks up the{" "}
        <strong>Zone ID</strong> from the zone name via the Cloudflare API:
      </p>
      <ul>
        <li>
          On success: <em>&quot;Matched Cloudflare zone example.com (023e105f…)&quot;</em> and
          the Zone ID field is filled in.
        </li>
        <li>
          On failure: <em>&quot;No Cloudflare zone matches example.com — check the
          credential&apos;s zone access.&quot;</em> — typically the API token is scoped to
          other zones.
        </li>
      </ul>
      <p>
        Discovered values only fill <strong>blank</strong> fields — anything you typed always
        wins. Use <strong>Discover again</strong> to re-run the lookup, or simply type the Zone
        ID manually. If you submit with the field blank, the server tries discovery once more
        and refuses the attachment with an explicit message if it still can&apos;t find it.
      </p>

      <H2>The attachment card</H2>

      <p>
        Each attachment card shows the provider&apos;s health badge, per-attachment record
        counts (<em>N records · N in sync</em>, plus drifted/error counts), and the
        zone-specific settings as chips (e.g. the Cloudflare zone ID). Badges call out special
        states: <strong>All zones</strong> (zoneless provider), <strong>Paused</strong>{" "}
        (attachment disabled for this zone), <strong>Provider disabled</strong> (the provider
        is disabled globally).
      </p>
      <p>Its action buttons (shown only to those whose roles allow each action):</p>
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
              <strong>Test</strong>
            </td>
            <td>
              Validates this specific attachment, with the result shown inline on the card. For
              Cloudflare it checks that the configured Zone ID actually belongs to this domain:
              success reports <em>&quot;Connected to zone example.com&quot;</em>; a mismatched
              ID reports <em>&quot;Zone ID belongs to other.com — expected
              example.com&quot;</em>.
            </td>
          </tr>
          <tr>
            <td>
              <strong>Import records</strong>
            </td>
            <td>
              See <a href="#import-records-from-a-provider">below</a>. Requires record
              management on the zone (<strong>Zone Admin</strong> or{" "}
              <strong>DNS Manager</strong> zone role).
            </td>
          </tr>
          <tr>
            <td>
              <strong>Disable / Enable</strong>
            </td>
            <td>
              A per-zone pause. Disabled attachments receive no pushes and their remote records
              are never deleted; entries keep their assignment so re-enabling picks up where it
              left off. (Disabling the <em>provider</em> on the Providers page pauses it for
              every zone the same way.)
            </td>
          </tr>
          <tr>
            <td>
              <strong>Edit config</strong>
            </td>
            <td>
              Change the attachment&apos;s connector settings (e.g. override the Cloudflare
              zone ID). Credentials stay on the provider and are never edited here. Only shown
              for connectors that have zone settings.
            </td>
          </tr>
          <tr>
            <td>
              <strong>Detach</strong> (or <strong>Opt out</strong> for zoneless providers)
            </td>
            <td>
              Removes the attachment. The zone&apos;s records stop syncing to that provider.
            </td>
          </tr>
        </tbody>
      </table>

      <Callout type="note">
        Detaching never deletes anything remotely:{" "}
        <strong>records already at the provider are NOT deleted</strong> — the app just stops
        managing them.
      </Callout>

      <H2>Zoneless providers and opting out</H2>

      <p>
        Some connectors have no zone concept of their own — <strong>Pi-hole</strong> answers
        for any name you give it. These providers are{" "}
        <strong>attached to every zone automatically</strong>: to all existing zones when the
        provider is created, and to every new zone at creation (the{" "}
        <a href="/docs/zones/creating/">create-zone dialog</a> spells this out).
      </p>
      <p>
        The escape hatch is per zone: <strong>Opt out</strong> on the attachment card detaches
        it, and nothing re-attaches an opted-out pair automatically. Opted-out zones appear{" "}
        <strong>struck through</strong> on the provider&apos;s card on the{" "}
        <a href="/docs/providers/">Providers</a> page (hover: <em>&quot;Opted out — re-attach
        from the zone&apos;s page.&quot;</em>). To opt back in, attach the provider again from
        the zone&apos;s <strong>Available</strong> list.
      </p>

      <H2>Import records from a provider</H2>

      <p>
        Importing pulls a provider&apos;s live records into the zone — the way to take over
        records that predate DNS Manager, or to seed a fresh install. It needs permission to
        manage the zone&apos;s records.
      </p>

      <Steps>
        <Step title="Open the import">
          <p>
            Use <strong>Import records</strong> on an attachment card, or{" "}
            <strong>Import from provider</strong> in the records toolbar — on the zone&apos;s
            Records tab it asks which of the zone&apos;s providers to read (skipped when there is
            only one); on the <a href="/docs/dns-entries/managing/">DNS Entries</a> page it asks
            for the zone first, offering only zones whose records you can manage.
          </p>
        </Step>
        <Step title="Review the remote records">
          <p>
            The dialog lists the remote records that fall inside this zone, each marked{" "}
            <strong>New</strong> (no matching entry), <strong>Will update</strong> (a matching
            entry exists and will be updated and linked), or <strong>Managed</strong> (already
            linked to this attachment — shown ticked and locked). Remote names are shown
            zone-relative.
          </p>
          <p>
            Narrow long lists with the <strong>search</strong> box (matches name or content),
            the <strong>type</strong> picker, and the status chips —{" "}
            <strong>All / New / Matches existing / Managed</strong> — each showing a live count
            for the current search and type. Large zones render the first 200 matches; use{" "}
            <strong>Show more</strong> or refine the search.
          </p>
        </Step>
        <Step title="Check what can't be imported">
          <p>
            A collapsed <strong>&quot;N records can&apos;t be imported&quot;</strong> section at the
            bottom lists the remote records that were left out, each with its reason:{" "}
            <strong>Outside <em>zone</em></strong> (expected for Pi-hole, which holds records for
            many zones in one place) or <strong>Type not managed by <em>provider</em></strong> — enable
            the type in the provider&apos;s <a href="/docs/providers/">managed record types</a>{" "}
            to import it. These rows are never selectable; search and type filters apply to them
            too. Up to 500 are listed (the count is always exact).
          </p>
        </Step>
        <Step title="Select and import">
          <p>
            Tick what you want — everything not already managed is preselected. The{" "}
            <strong>Select all</strong> checkbox applies to the rows matching the current
            filters only; selections hidden by a filter are kept and counted. Press{" "}
            <strong>Import</strong> (up to 1000 records at a time). Selected records are
            upserted: new entries are created, matching entries (same name, type, and content)
            are updated in place, and each is linked to <strong>this attachment only</strong>,
            already in sync — nothing is pushed anywhere else and nothing is duplicated.
          </p>
        </Step>
      </Steps>

      <p>
        To sync an imported entry to more of the zone&apos;s providers, edit it afterwards. The
        result (<em>&quot;Imported N new and updated M existing entries from …&quot;</em>) is
        also recorded as an <strong>import</strong> event in the sync activity feed.
      </p>
    </DocArticle>
  );
}
