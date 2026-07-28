import Callout from "@/components/docs/Callout";
import DocArticle, { docMetadata } from "@/components/docs/DocArticle";
import { H2 } from "@/components/docs/Heading";
import { Step, Steps } from "@/components/docs/Steps";

export const metadata = docMetadata("zones", "creating");

export default function Page() {
  return (
    <DocArticle group="zones" slug="creating">
      <p>
        Creating and deleting zones is reserved for <strong>Super Admins</strong> — even a Zone
        Admin cannot delete their own zone.
      </p>

      <H2>Create a zone</H2>

      <Steps>
        <Step title="Open the Zones page and click Add zone">
          <p>The button is only shown to Super Admins.</p>
        </Step>
        <Step title="Enter the domain">
          <p>
            A bare domain like <code>example.com</code> — it is lowercased, a trailing dot is
            trimmed, and it must be unique.
          </p>
        </Step>
        <Step title="Add a description (optional)">
          <p>
            An optional note (max 255 characters), shown on the zone list and the zone header.
          </p>
        </Step>
        <Step title="Review where the zone will sync">
          <p>The dialog tells you up front what the new zone will sync to:</p>
          <ul>
            <li>
              If you have <strong>zoneless providers</strong> (Pi-hole), each is listed with a
              note that it <em>serves all zones and will be attached automatically; you can opt
              out later</em> — see{" "}
              <a href="/docs/zones/providers/#zoneless-providers-and-opting-out">
                Zoneless providers
              </a>
              .
            </li>
            <li>
              If you have <strong>no providers at all</strong>, an amber warning says the zone
              won&apos;t sync anywhere until a provider is attached. That&apos;s fine — records
              are stored locally until then.
            </li>
          </ul>
        </Step>
      </Steps>

      <Callout type="important">
        <strong>The domain cannot be changed after creation</strong> — the edit dialog only lets
        you change the description. To rename a zone, create a new one and re-create or{" "}
        <a href="/docs/zones/providers/#import-records-from-a-provider">re-import</a> its
        records.
      </Callout>

      <H2>Edit a zone</H2>

      <p>
        <strong>Edit</strong> on the Zones page (Super Admins) or <strong>Edit zone</strong> in
        the zone header&apos;s kebab menu (Zone Admins) opens the same dialog — only the{" "}
        <strong>description</strong> can be changed.
      </p>

      <H2>Delete a zone</H2>

      <p>
        <strong>Delete zone</strong> (zone header kebab menu, or the Zones page kebab — Super
        Admins only) removes the zone and all of its records{" "}
        <strong>from DNS Manager only</strong>.
      </p>

      <Callout type="caution">
        The confirmation dialog states the exact consequence: the zone&apos;s N records are
        removed from the app, and{" "}
        <strong>records at the attached providers are NOT deleted</strong> — the app just stops
        managing them, and no delete jobs are queued. This cannot be undone (though you can
        re-create the zone and{" "}
        <a href="/docs/zones/providers/#import-records-from-a-provider">re-import</a> the
        records, since they still exist at the providers).
      </Callout>

      <p>
        If you want the remote records gone too, delete the entries first — entry deletion{" "}
        <em>does</em> remove records from providers (see{" "}
        <a href="/docs/dns-entries/managing/#deleting-entries">Deleting entries</a>) — and
        delete the zone afterwards.
      </p>
      <p>
        The zone&apos;s audit history survives deletion — see{" "}
        <a href="/docs/activity/">Activity Log</a>.
      </p>
    </DocArticle>
  );
}
