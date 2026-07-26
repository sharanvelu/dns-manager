---
title: Users & Access
nav_order: 7
description: Manage users with global roles and per-zone access grants.
---

# Users & Access

DNS Manager controls access on two levels:

- **Global roles** grant app-wide capabilities — administration, read-only oversight, or user management.
- **Zone roles** are granted **per user, per zone** and scope everything else: a user sees and touches only the zones they have been granted, with the abilities their zone roles allow.

Permissions are always the **union** of everything a user holds. Both levels are optional — a user can have global roles, zone grants, both, or neither. **Zero global roles is a perfectly normal state**: such users' access comes entirely from their zone grants, and a user with no grants at all sees a "no access yet" screen until an administrator grants them a zone.

Buttons, menus, tabs, and sidebar items you are not allowed to use are hidden, and the server enforces the same rules regardless of what the browser sends.

## Global roles

| Role | What it allows |
| --- | --- |
| **Super Admin** | Everything: all zones and records, creating and deleting zones, provider credentials, users, and the full [Activity Log](activity-log). The only role that can create or delete zones and manage global provider credentials. |
| **Super Viewer** | Read-only access to **everything** — dashboard, all zones and records, providers (secrets stay hidden), users, zone access lists, and the full activity log (global and per zone). Never any write action. |
| **User Admin** | The **Users** section only: edit user details, global roles, and zone access grants for everyone **except their own account**. Cannot grant or revoke Super Admin. Without other roles, a User Admin sees only Settings → Users. |

Roles combine — for example, Super Viewer + User Admin can see everything *and* manage users.

## Zone roles

Zone roles are granted per zone (a **grant** is one user's set of roles on one zone) and are freely combinable:

| Zone role | What it allows on that zone |
| --- | --- |
| **Zone Admin** | Everything in the zone **except deleting it**: edit the zone, manage records, sync, imports, provider attachments, view the zone's activity, and manage the zone's access grants — with two limits: they can never touch grants that involve the Zone Admin role, and never their own grant. |
| **DNS Manager** | Create, edit, sync, import (CSV and from providers), and delete DNS records in the zone. **No access to the zone's activity log.** |
| **Viewer** | Read-only access to the zone, its records, and its **activity**. |
| **Provider Manager** | Manage the zone's provider [attachments](zones#attaching-a-provider) only — attach, detach, test, per-zone settings, and per-zone enable/disable. Never global provider credentials. |

Reserved for **Super Admins** regardless of zone roles: creating zones, deleting zones, and managing the global providers (credentials) on the [Providers](providers) page.

## How users are created

There is no registration form. Users are provisioned automatically on their first OIDC sign-in:

- The **first user ever** becomes a **Super Admin** — that's you, right after installation.
- Everyone after that starts with **no access at all** — no global roles and no zone grants — until an administrator assigns roles or grants zone access.

**Upgrading from an earlier version?** The old role set (DNS Manager, Providers Manager, Viewer as *global* roles) no longer exists. During the migration, users who held Super Admin keep it; **every other existing user becomes a Super Viewer** — they can still see everything but change nothing until you grant them zone roles. Review each user after upgrading and hand out zone grants to match how they actually work.

## The Users section

Open **Settings → Users** in the sidebar. It is visible to Super Admins, Super Viewers, and User Admins; Super Viewers get a read-only view with a banner ("Read-only — you can view users but not change them").

The **list** shows each user's avatar, name, email, global role badges, how many zones they have grants on (or "No zone access"), and when they joined. Click a user to open their detail page, which has three cards:

- **Global roles** — a checkbox per global role with its description. Tick any combination (or none) and **Save roles**. Changes apply on the user's next request; no re-login needed. When no role is ticked, the card notes that access comes only from the zone grants below.
- **Zone access** — one row per grant showing the zone and its role badges. **Add zone access** opens a dialog to pick a zone (only zones without an existing grant are offered) and tick zone roles; the row's kebab menu offers **Edit roles** and **Remove**. Removing a grant takes away all of that user's access to the zone — their global roles are unaffected.
- **Danger zone** — **Delete user** (hidden on your own account).

The same grants can also be managed from the other direction — each zone has an [Access tab](zones#zone-access) listing everyone granted on that zone.

All of it is audited: global role changes show the old → new roles, and every grant change is recorded as a `zone-access-granted`, `zone-access-updated`, or `zone-access-revoked` event naming the user, the zone, and the roles — see [Activity Log](activity-log).

## Safety rails

- **Last Super Admin** — the last remaining Super Admin can neither lose the role nor be deleted; assign Super Admin to someone else first.
- **No self-edit for User Admins** — a User Admin (who is not also a Super Admin) can never change or delete their **own** account; their detail page is read-only to them.
- **No self-deletion** — nobody can delete their own account, whatever their roles.
- **Super Admin escalation is blocked** — only a Super Admin can grant *or revoke* the Super Admin role. A User Admin **cannot** make anyone a Super Admin (or demote one), but **can** create other User Admins and Super Viewers, and can grant any zone role — including **Zone Admin**.

## Deleting a user

Deleting removes the account, its global roles, and all of its zone grants. It does **not** block the person at your identity provider — if they sign in again via SSO they are re-provisioned **with no access**. To fully revoke access, also remove them (or the app grant) in your OIDC provider.
