import Callout from "@/components/docs/Callout";
import CodeBlock from "@/components/docs/CodeBlock";
import DocArticle, { docMetadata } from "@/components/docs/DocArticle";
import { H2 } from "@/components/docs/Heading";
import { Step, Steps } from "@/components/docs/Steps";

export const metadata = docMetadata("authentication", "passwords");

export default function Page() {
  return (
    <DocArticle group="authentication" slug="passwords">
      <p>
        Besides single sign-on, DNS Manager supports classic{" "}
        <strong>email + password</strong> sign-in. Both methods are
        controlled by environment variables, so you can run SSO only,
        password only, or both side by side.
      </p>

      <H2>Choosing sign-in methods</H2>

      <table>
        <thead>
          <tr>
            <th>Variable</th>
            <th>Default</th>
            <th>Effect</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <code>SSO_ENABLED</code>
            </td>
            <td>
              <code>true</code> when <code>OIDC_BASE_URL</code> is set
            </td>
            <td>
              Turns the OIDC sign-in button and routes on or off completely.
            </td>
          </tr>
          <tr>
            <td>
              <code>SSO_AUTO_PROVISION</code>
            </td>
            <td>
              <code>true</code>
            </td>
            <td>
              Create an account on first SSO sign-in. Only applies while SSO
              is on. When <code>false</code>, SSO sign-ins with an unknown
              email are rejected and admins add users by hand (they then sign
              in with SSO using that email). The very first user is always
              created as Super Admin, regardless. With auto-provisioning on
              and <code>BASIC_AUTH_ENABLED=false</code>, the{" "}
              <strong>Add user</strong> button is hidden.
            </td>
          </tr>
          <tr>
            <td>
              <code>BASIC_AUTH_ENABLED</code>
            </td>
            <td>
              <code>true</code>
            </td>
            <td>
              Turns password sign-in, forgot-password, invite links and the
              profile password section on or off.{" "}
              <strong>Only honored while SSO is enabled</strong> — with SSO
              off, password sign-in is always on so nobody is locked out.
            </td>
          </tr>
          <tr>
            <td>
              <code>PASSWORD_MIN_LENGTH</code>
            </td>
            <td>
              <code>8</code>
            </td>
            <td>Minimum length for new passwords.</td>
          </tr>
          <tr>
            <td>
              <code>PASSWORD_RESET_EXPIRE_MINUTES</code>
            </td>
            <td>
              <code>60</code>
            </td>
            <td>How long a forgot-password link stays valid.</td>
          </tr>
          <tr>
            <td>
              <code>INVITE_EXPIRE_HOURS</code>
            </td>
            <td>
              <code>72</code>
            </td>
            <td>
              How long the &quot;create your password&quot; link sent to new
              users stays valid.
            </td>
          </tr>
          <tr>
            <td>
              <code>LOGIN_MAX_ATTEMPTS</code>
            </td>
            <td>
              <code>5</code>
            </td>
            <td>
              Failed sign-ins per email + IP before a one-minute lockout.
            </td>
          </tr>
        </tbody>
      </table>

      <Callout type="note">
        Password emails need working mail settings (<code>MAIL_*</code>) and a
        running queue worker — see{" "}
        <a href="/docs/installation/configuration/">Configuration</a>.
      </Callout>

      <H2>Creating the first admin without SSO</H2>

      <p>
        With SSO disabled nobody can sign in yet, so create the first Super
        Admin from the command line (you are prompted for the password):
      </p>
      <CodeBlock lang="sh">{`
        php artisan dns:create-user --name="Admin" --email=admin@example.com --super-admin
      `}</CodeBlock>

      <H2>Forgot password</H2>

      <p>
        <strong>Forgot password?</strong> on the sign-in page emails a reset
        link. The response is identical whether or not the address exists, so
        accounts cannot be discovered. Resetting signs the user out of every
        other session.
      </p>

      <H2>Forcing a password reset</H2>

      <p>
        A Super Admin or User Admin can force a reset from a user&apos;s page
        (<strong>Danger zone → Force password reset</strong>). The user is
        signed out everywhere, emailed a reset link, and cannot sign in with
        their current password until they set a new one. If they try first,
        they see &quot;An administrator requires you to reset your
        password&quot; and a fresh link is emailed. Their stored password is
        kept until then, and the flag clears once they complete the reset or
        change their password. A reset email sent less than a minute earlier
        is not repeated — the user is still flagged and signed out.
      </p>

      <Callout type="note">
        The button is hidden when password sign-in is disabled, when the user
        has no password (a pending invite — use <strong>Resend invite</strong>
        ), and for SSO-only users while SSO is on. You cannot force a reset on
        your own account; change your password from your profile. Single
        sign-on is never affected by the flag.
      </Callout>

      <H2>Users created by an administrator</H2>

      <p>
        When an admin adds a user (<strong>Settings → Users → Add user</strong>
        ) the new user receives an email with a link to create their
        password. After setting it they land on the sign-in page. Until then
        the user shows an <strong>Invite pending</strong> badge and an admin
        can resend the email.
      </p>

      <H2>Can an SSO user also use a password?</H2>

      <p>
        Yes. There is one account per email address; SSO and a password are
        two ways to prove you own it. An SSO user can open{" "}
        <strong>Profile → Set a password</strong> to add one, after which
        either method works. If an admin pre-creates a user and that person
        later signs in via SSO with the same email, they are linked to the
        same account. Changing a password signs out the user&apos;s other
        sessions.
      </p>

      <H2>Requiring SSO for a user</H2>

      <p>
        When SSO is enabled, an administrator can tick{" "}
        <strong>Single sign-on only</strong> on a user&apos;s page (or while
        creating the user). That account can then no longer use email and
        password: the sign-in form rejects it exactly like a wrong password,
        forgot-password sends nothing, and any outstanding reset or invite
        link stops working. Their existing password is kept, so unticking the
        box restores password sign-in. Active sessions are not signed out.
      </p>

      <Callout type="note">
        The flag has no effect while <code>SSO_ENABLED</code> is off, so you
        can never lock everyone out by disabling SSO. Make sure the person has
        signed in with SSO before, or that their identity provider email
        matches their address here.
      </Callout>

      <H2>Two-factor authentication</H2>

      <p>
        Password accounts can add an optional second step: a 6-digit code from
        an authenticator app (1Password, Authy, Google Authenticator, …). It
        applies to email + password sign-in only — single sign-on is governed
        by your identity provider. Two-factor is never enforced.
      </p>

      <Steps>
        <Step title="Open your Profile">
          Scroll to <strong>Two-factor authentication</strong> and click{" "}
          <strong>Set up two-factor</strong>. You need a password on the
          account.
        </Step>
        <Step title="Scan the QR code">
          Scan it with your authenticator app, or type the manual key shown
          under it.
        </Step>
        <Step title="Confirm with a code">
          Enter the current 6-digit code and click{" "}
          <strong>Confirm and enable</strong>. Two-factor stays off until this
          succeeds.
        </Step>
        <Step title="Save your recovery codes">
          Eight single-use recovery codes are shown once. Store them
          somewhere safe — each one works in place of an authenticator code.
        </Step>
      </Steps>

      <p>
        After that, signing in with your password takes you to a second page
        where you enter a code (or switch to a recovery code). You are not
        signed in until it passes; the pending sign-in expires after 10
        minutes, and the code page allows 10 attempts per minute. A code can
        only be used once. From your profile you can regenerate recovery
        codes or turn two-factor off — both ask for your current password.
      </p>

      <Callout type="warning">
        If you lose your device <em>and</em> your recovery codes, an
        administrator must reset your two-factor authentication (see{" "}
        <a href="/docs/users/managing/">Managing users</a>). Resetting also
        signs you out everywhere; you can then set it up again.
      </Callout>

      <Callout type="note">
        Sign-ins, failed sign-ins, resets, forced resets, password changes,
        invites, SSO-only changes and two-factor events are recorded in the{" "}
        <a href="/docs/activity/">activity log</a> as <code>auth</code>{" "}
        events. Passwords, secrets and recovery codes are never logged.
      </Callout>
    </DocArticle>
  );
}
