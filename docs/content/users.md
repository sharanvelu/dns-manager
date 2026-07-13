---
title: Users & Roles
nav_order: 6
description: Manage users and control access with predefined roles.
---

# Users & Roles

DNS Manager uses role-based access control (RBAC). Every user carries one or more roles, and permissions are the **union** of their roles.

## The roles

| Role | What it allows |
| --- | --- |
| **Super Admin** | Everything, including user and role management under Settings → Users. |
| **DNS Manager** | Create, edit, sync, import, and delete DNS entries. |
| **Providers Manager** | Add, configure, test, enable/disable, and delete providers. |
| **Viewer** | Read-only access to the dashboard, entries, and providers. |

Every authenticated user can *view* all pages; the roles gate the actions. Buttons and menus you are not allowed to use are hidden, and the server enforces the same rules regardless of what the browser sends.

## How users are created

There is no registration form. Users are provisioned automatically on their first OIDC sign-in:

- The **first user ever** becomes a **Super Admin** — that's you, right after installation.
- Everyone after that starts as a **Viewer** until a Super Admin assigns more roles.

Upgrading from a version without RBAC? All existing users are granted Super Admin during the migration (they effectively had full access before), so review and narrow their roles afterwards.

## Managing users (Super Admin only)

Open **Settings → Users**. Each user card shows their avatar, email, and a checkbox per role — tick any combination and **Save roles**. Changes apply on the user's next request; no re-login needed.

Safety rails:

- A user must have **at least one role**.
- The **last Super Admin** can neither lose the role nor be deleted — assign Super Admin to someone else first.
- You cannot delete **your own** account.

## Deleting a user

Deleting removes the account and its role assignments. It does **not** block the person at your identity provider — if they sign in again via SSO they are re-provisioned as a Viewer. To fully revoke access, also remove them (or the app grant) in your OIDC provider.
