import DocArticle, { docMetadata } from "@/components/docs/DocArticle";
import { H2 } from "@/components/docs/Heading";
import Screenshot from "@/components/docs/Screenshot";
import { Step, Steps } from "@/components/docs/Steps";

export const metadata = docMetadata("zones", "access");

export default function Page() {
  return (
    <DocArticle group="zones" slug="access">
      <p>
        Access to a zone is granted <strong>per user, per zone</strong>: a{" "}
        <strong>grant</strong> is one user&apos;s set of zone roles on one zone. The{" "}
        <strong>Access</strong> tab manages who can do what in this zone — the same grants as
        the zone-access card on each <a href="/docs/users/managing/">user&apos;s detail
        page</a>, viewed from the zone&apos;s side.
      </p>

      <H2>Zone roles</H2>

      <p>Zone roles are freely combinable within a grant:</p>
      <table>
        <thead>
          <tr>
            <th>Zone role</th>
            <th>What it allows on that zone</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <strong>Zone Admin</strong>
            </td>
            <td>
              Everything in the zone <strong>except deleting it</strong>: edit the zone, manage
              records, sync, imports, provider attachments, view the zone&apos;s activity, and
              manage the zone&apos;s access grants — with two limits: they can never touch
              grants that involve the Zone Admin role, and never their own grant.
            </td>
          </tr>
          <tr>
            <td>
              <strong>DNS Manager</strong>
            </td>
            <td>
              Create, edit, sync, import (CSV and from providers), and delete DNS records in
              the zone. <strong>No access to the zone&apos;s activity log.</strong>
            </td>
          </tr>
          <tr>
            <td>
              <strong>Viewer</strong>
            </td>
            <td>
              Read-only access to the zone, its records, and its <strong>activity</strong>.
            </td>
          </tr>
          <tr>
            <td>
              <strong>Provider Manager</strong>
            </td>
            <td>
              Manage the zone&apos;s provider{" "}
              <a href="/docs/zones/providers/">attachments</a> only — attach, detach, test,
              per-zone settings, and per-zone enable/disable. Never global provider
              credentials.
            </td>
          </tr>
        </tbody>
      </table>
      <p>
        Reserved for <strong>Super Admins</strong> regardless of zone roles: creating zones,
        deleting zones, and managing the global providers (credentials) on the{" "}
        <a href="/docs/providers/">Providers</a> page. See{" "}
        <a href="/docs/users/">Users &amp; Access</a> for the global roles.
      </p>

      <H2>Who sees the Access tab</H2>

      <ul>
        <li>
          <strong>Super Admins</strong> and the zone&apos;s <strong>Zone Admins</strong> can
          view and manage the grants.
        </li>
        <li>
          <strong>User Admins</strong> can too — even on zones they otherwise cannot open (they
          land on the Access tab without the Records/Providers tabs).
        </li>
        <li>
          <strong>Super Viewers</strong> see it read-only, with a banner (&quot;Read-only — you
          can see who has access to this zone but not change it&quot;).
        </li>
        <li>
          Other zone roles (DNS Manager, Viewer, Provider Manager) don&apos;t see the tab at
          all.
        </li>
      </ul>

      <H2>Grant access</H2>

      <Screenshot
        src="zone-access"
        alt="A zone's Access tab"
        caption="Each grant shows the user, their role badges, and when it was granted."
      />

      <p>
        The tab lists each grant with the user&apos;s avatar, email, role badges, and when it
        was granted. Super Admins are never listed — they always have full access to every
        zone.
      </p>

      <Steps>
        <Step title="Open the zone's Access tab and click Grant access">
          <p>
            Only users without an existing grant on this zone are offered in the user picker.
          </p>
        </Step>
        <Step title="Tick the zone roles">
          <p>
            Each role option shows its description — combine as many as the user needs.
          </p>
        </Step>
        <Step title="Save">
          <p>
            The grant takes effect immediately, and the change is audited — see{" "}
            <a href="/docs/activity/">Activity Log</a>.
          </p>
        </Step>
      </Steps>

      <p>
        A grant&apos;s kebab menu offers <strong>Edit roles</strong> and{" "}
        <strong>Remove access</strong>. Removing a grant takes away all of that user&apos;s
        access to this zone without touching their global roles.
      </p>

      <H2>Zone Admin grants are special</H2>

      <p>
        For actors whose only claim to the tab is their own Zone Admin grant (not Super Admin
        or User Admin):
      </p>
      <ul>
        <li>
          The <strong>Zone Admin checkbox is disabled</strong> in the grant dialog — they
          cannot mint new Zone Admins.
        </li>
        <li>
          Existing grants that include Zone Admin show a <strong>lock icon</strong>{" "}
          (&quot;Managed by Super Admins or User Admins&quot;) instead of the kebab menu — they
          cannot edit or remove them.
        </li>
        <li>
          They can never change or remove <strong>their own</strong> grant.
        </li>
      </ul>
      <p>
        Only Super Admins and User Admins can create, change, or revoke grants involving the
        Zone Admin role.
      </p>
    </DocArticle>
  );
}
