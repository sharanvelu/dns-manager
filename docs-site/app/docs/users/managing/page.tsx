import Callout from "@/components/docs/Callout";
import DocArticle, { docMetadata } from "@/components/docs/DocArticle";
import { H2 } from "@/components/docs/Heading";
import Screenshot from "@/components/docs/Screenshot";
import { Step, Steps } from "@/components/docs/Steps";

export const metadata = docMetadata("users", "managing");

export default function Page() {
  return (
    <DocArticle group="users" slug="managing">
      <H2>How users are created</H2>
      <p>
        There is no registration form. Users are provisioned automatically on their{" "}
        <strong>first OIDC sign-in</strong> — see{" "}
        <a href="/docs/authentication/first-login/">First login</a>:
      </p>
      <ul>
        <li>
          The <strong>first user ever</strong> becomes a <strong>Super Admin</strong> —
          that&apos;s you, right after installation.
        </li>
        <li>
          Everyone after that starts with <strong>no access at all</strong> — no global roles and
          no zone grants — until an administrator assigns roles or grants zone access.
        </li>
      </ul>
      <Callout type="note">
        <strong>Upgrading from an earlier version?</strong> The old role set (DNS Manager,
        Providers Manager, Viewer as <em>global</em> roles) no longer exists. During the
        migration, users who held Super Admin keep it;{" "}
        <strong>every other existing user becomes a Super Viewer</strong> — they can still see
        everything but change nothing until you grant them zone roles. Review each user after
        upgrading and hand out zone grants to match how they actually work.
      </Callout>

      <H2>The Users section</H2>
      <p>
        Open <strong>Settings → Users</strong> in the sidebar. It is visible to Super Admins,
        Super Viewers, and User Admins; Super Viewers get a read-only view with a banner
        (&quot;Read-only — you can view users but not change them&quot;) and no mutating buttons
        or menus.
      </p>
      <p>
        The <strong>list</strong> shows each user&apos;s avatar, name, email, global role badges,
        how many zones they have grants on (or &quot;No zone access&quot;), and when they joined.
        Click a user to open their detail page, which has three cards:{" "}
        <strong>Global roles</strong>, <strong>Zone access</strong>, and{" "}
        <strong>Danger zone</strong>.
      </p>

      <Screenshot
        src="user-detail"
        alt="A user's detail page"
        caption="The user detail page: global roles, zone access grants, and the danger zone."
      />

      <H2>Editing global roles</H2>
      <Steps>
        <Step title="Open the user">
          <p>
            Go to <strong>Settings → Users</strong> and click the user.
          </p>
        </Step>
        <Step title="Tick roles in the Global roles card">
          <p>
            Each global role is a checkbox with its description. Tick any combination — or none:
            when no role is ticked, the card notes that access comes only from the zone grants
            below.
          </p>
        </Step>
        <Step title="Save roles">
          <p>Changes apply on the user&apos;s next request; no re-login needed.</p>
        </Step>
      </Steps>

      <H2>Granting zone access</H2>
      <p>
        The <strong>Zone access</strong> card lists one row per grant, showing the zone and its
        role badges. The same grants can also be managed from the other direction — each
        zone&apos;s <a href="/docs/zones/access/">Access tab</a> lists everyone granted on that
        zone.
      </p>
      <Steps>
        <Step title="Add zone access">
          <p>
            Click <strong>Add zone access</strong>. The dialog offers only zones the user has no
            existing grant on.
          </p>
        </Step>
        <Step title="Tick zone roles">
          <p>
            Roles are a checkbox list where each option shows its label plus a description — pick
            any combination of{" "}
            <a href="/docs/users/#zone-roles">the four zone roles</a>.
          </p>
        </Step>
        <Step title="Manage existing grants">
          <p>
            Each grant row has a kebab menu with <strong>Edit roles</strong> and{" "}
            <strong>Remove</strong>. Removing a grant takes away all of that user&apos;s access
            to the zone — their global roles are unaffected.
          </p>
        </Step>
      </Steps>
      <Callout type="note">
        The same grant dialog is used on the zone&apos;s Access tab, where a{" "}
        <strong>Zone Admin</strong> can also grant access — with a limit: the Zone Admin option
        is disabled for them (a tooltip explains only a Super Admin or User Admin can grant it),
        and existing zone-admin grants they cannot touch show a &quot;Managed by Super
        Admins&quot; lock chip instead of a menu.
      </Callout>
      <p>
        All of it is audited: global role changes show the old → new roles, and every grant
        change is recorded as a <code>zone-access-granted</code>,{" "}
        <code>zone-access-updated</code>, or <code>zone-access-revoked</code> event naming the
        user, the zone, and the roles — see the <a href="/docs/activity/">Activity Log</a>.
      </p>

      <H2>Safety rails</H2>
      <ul>
        <li>
          <strong>Last Super Admin</strong> — the last remaining Super Admin can neither lose the
          role nor be deleted; assign Super Admin to someone else first.
        </li>
        <li>
          <strong>No self-edit for User Admins</strong> — a User Admin (who is not also a Super
          Admin) can never change or delete their <strong>own</strong> account; their detail page
          is read-only to them.
        </li>
        <li>
          <strong>No self-deletion</strong> — nobody can delete their own account, whatever their
          roles.
        </li>
        <li>
          <strong>Super Admin escalation is blocked</strong> — only a Super Admin can grant{" "}
          <em>or revoke</em> the Super Admin role. A User Admin <strong>cannot</strong> make
          anyone a Super Admin (or demote one), but <strong>can</strong> create other User Admins
          and Super Viewers, and can grant any zone role — including <strong>Zone Admin</strong>.
        </li>
      </ul>

      <H2>Deleting a user</H2>
      <p>
        The <strong>Danger zone</strong> card holds <strong>Delete user</strong> (hidden on your
        own account). Deleting removes the account, its global roles, and all of its zone grants.
      </p>
      <Callout type="warning">
        Deleting a user does <strong>not</strong> block the person at your identity provider —
        if they sign in again via SSO they are re-provisioned <strong>with no access</strong>. To
        fully revoke access, also remove them (or the app grant) in your OIDC provider.
      </Callout>
    </DocArticle>
  );
}
