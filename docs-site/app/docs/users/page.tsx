import Callout from "@/components/docs/Callout";
import { Card, Cards } from "@/components/docs/Cards";
import DocArticle, { docMetadata } from "@/components/docs/DocArticle";
import { H2 } from "@/components/docs/Heading";
import Screenshot from "@/components/docs/Screenshot";

export const metadata = docMetadata("users");

export default function Page() {
  return (
    <DocArticle group="users">
      <p>DNS Manager controls access on two levels:</p>
      <ul>
        <li>
          <strong>Global roles</strong> grant app-wide capabilities — administration, read-only
          oversight, or user management.
        </li>
        <li>
          <strong>Zone roles</strong> are granted <strong>per user, per zone</strong>: a user sees
          and touches only the zones they have been granted, with the abilities their zone roles
          allow.
        </li>
      </ul>
      <p>
        Permissions are always the <strong>union</strong> of everything a user holds. Both levels
        are optional — global roles, zone grants, both, or neither.{" "}
        <strong>Zero global roles is a perfectly normal state</strong>: such users&apos; access
        comes entirely from their zone grants, and a user with no grants at all sees a &quot;no
        access yet&quot; screen until an administrator grants them a zone.
      </p>
      <p>
        Buttons, menus, tabs, and sidebar items you are not allowed to use are hidden, and the
        server enforces the same rules regardless of what the browser sends.
      </p>

      <Screenshot
        src="users-index"
        alt="The Users list under Settings"
        caption="Settings → Users: each user's global role badges, zone-grant count, and join date."
      />

      <H2>Global roles</H2>
      <table>
        <thead>
          <tr>
            <th>Role</th>
            <th>What it allows</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <strong>Super Admin</strong>
            </td>
            <td>
              Everything: all zones and records, creating and deleting zones, provider
              credentials, users, and the full <a href="/docs/activity/">Activity Log</a>. The
              only role that can create or delete zones and manage global provider credentials.
            </td>
          </tr>
          <tr>
            <td>
              <strong>Super Viewer</strong>
            </td>
            <td>
              Read-only access to <strong>everything</strong> — dashboard, all zones and records,
              providers (secrets stay hidden), users, zone access lists, and the full activity
              log (global and per zone). Never any write action.
            </td>
          </tr>
          <tr>
            <td>
              <strong>User Admin</strong>
            </td>
            <td>
              The <strong>Users</strong> section only: edit user details, global roles, and zone
              access grants for everyone <strong>except their own account</strong>. Cannot grant
              or revoke Super Admin. Without other roles, a User Admin sees only Settings →
              Users.
            </td>
          </tr>
        </tbody>
      </table>
      <p>
        Roles combine — for example, Super Viewer + User Admin can see everything <em>and</em>{" "}
        manage users.
      </p>

      <H2>Zone roles</H2>
      <p>
        Zone roles are granted per zone (a <strong>grant</strong> is one user&apos;s set of roles
        on one zone) and are freely combinable:
      </p>
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
              manage the zone&apos;s access grants — with two limits: they can never touch grants
              that involve the Zone Admin role, and never their own grant.
            </td>
          </tr>
          <tr>
            <td>
              <strong>DNS Manager</strong>
            </td>
            <td>
              Create, edit, sync, import (CSV and from providers), and delete DNS records in the
              zone. <strong>No access to the zone&apos;s activity log.</strong>
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
              per-zone settings, and per-zone enable/disable. Never global provider credentials.
            </td>
          </tr>
        </tbody>
      </table>

      <Callout type="important">
        Reserved for <strong>Super Admins</strong> regardless of zone roles: creating zones,
        deleting zones, and managing the global provider credentials on the{" "}
        <a href="/docs/providers/">Providers</a> page.
      </Callout>

      <H2>What each persona sees</H2>
      <p>
        The sidebar is built from your permissions — sections you cannot use simply are not
        there:
      </p>
      <table>
        <thead>
          <tr>
            <th>Sidebar item</th>
            <th>Super Admin</th>
            <th>Super Viewer</th>
            <th>User Admin</th>
            <th>Zone-granted user</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>Dashboard / Zones / DNS Entries</td>
            <td>✓</td>
            <td>✓</td>
            <td>—</td>
            <td>✓</td>
          </tr>
          <tr>
            <td>Providers</td>
            <td>✓</td>
            <td>✓</td>
            <td>—</td>
            <td>—</td>
          </tr>
          <tr>
            <td>Settings → Users</td>
            <td>✓</td>
            <td>✓</td>
            <td>✓</td>
            <td>—</td>
          </tr>
          <tr>
            <td>Settings → Activity</td>
            <td>✓</td>
            <td>✓</td>
            <td>—</td>
            <td>—</td>
          </tr>
        </tbody>
      </table>
      <p>
        Read-only personas get the pages, not the buttons: a Super Viewer opening the Users list
        sees a quiet <strong>read-only banner</strong> under the title instead of disabled
        controls, and mutating buttons and menus are simply not rendered.
      </p>

      <H2>Next steps</H2>
      <Cards>
        <Card title="Manage users & grants" icon="users" href="/docs/users/managing/">
          Provisioning, editing global roles, and granting zone access from the user page.
        </Card>
        <Card title="Zone access" icon="zones" href="/docs/zones/access/">
          Manage the same grants from the other direction — each zone&apos;s Access tab.
        </Card>
        <Card title="Authentication" icon="authentication" href="/docs/authentication/">
          The OIDC-only sign-in model that provisions every user.
        </Card>
        <Card title="Activity Log" icon="activity" href="/docs/activity/">
          Every role change and grant change is audited.
        </Card>
      </Cards>
    </DocArticle>
  );
}
