---
title: Activity Log
nav_order: 7
description: The audit trail — who changed what and when, across entries, providers, users, and sign-ins.
---

# Activity Log

The activity log is DNS Manager's audit trail: a permanent record of **who changed what, and when**. Every change made through the app is attributed to the signed-in user who made it. It is separate from the [Dashboard](dashboard) activity feed, which tracks background sync jobs — see [What is not recorded](#what-is-not-recorded).

## Who can see it

Only **Super Admins**. The viewer lives at **Settings → Activity log**, and every entry point to it — the settings nav item and the quick-access menu items below — is hidden for everyone else. The server enforces the same rule regardless of what the browser sends.

## What is recorded

| Area | Events |
| --- | --- |
| **DNS entries** | Created, updated, and deleted, with field-level old → new diffs for name, type, content, TTL, priority, proxied, and comment. When a deletion is deferred to queued provider cleanup, a **delete-requested** event attributes the request to the user who asked for it (the eventual **deleted** event is recorded by the system once the last provider confirms). Bulk provider reassignment logs a **providers-changed** event listing the providers each entry now syncs to. |
| **Providers** | Created, updated, and deleted, covering the name, type, enabled flag, and managed record types. A credential change is recorded as **updated connection settings** — see below. |
| **Users** | Name, email, and role changes. Role changes show the old → new roles and are attributed to the Super Admin who made them. |
| **Sign-ins** | A **login** and **logout** event per user (via your OIDC provider). |

## What is not recorded

- **Provider secrets, ever.** API tokens, app passwords, and other connection settings are never written to the activity log — not even in encrypted form. Changing a provider's credentials produces an *updated connection settings* event that carries only the fact that the configuration changed, never any value.
- **Background sync and health checks.** Pushes, remote deletes, drift checks, and provider health checks are machine activity, not user actions — they appear in the [Dashboard](dashboard) recent-activity feed instead. Provider health status updates never generate audit events.

## The viewer

**Settings → Activity log** lists activities newest-first, paginated. You can filter by:

- **Subject type** — Entries, Providers, or Users.
- **Specific record** — narrow to one entry, provider, or user.
- **Event** — created, updated, deleted, login, and so on.
- **User** — who performed the action.
- **Date range** — from/to.

Filters combine. Expanding a row shows the field-level changes as old → new values.

### History of deleted records

Activities are never removed when their record is deleted — the full history of a deleted entry, provider, or user stays viewable, with the record's name recovered from its last logged snapshot.

## Quick access from a record

On the [DNS Entries](dns-entries) page, each row's kebab menu has an **Activity** item; so does each provider card on the [Providers](providers) page. It opens a dialog with that record's history, plus an **Open full activity log** link that jumps to the viewer pre-filtered to the record. Both menu items appear only for Super Admins.

## Retention

Activities are kept for **365 days** by default (`clean_after_days` in `config/activitylog.php`). Pruning is not automatic — run `php artisan activitylog:clean` (for example from a cron job) to delete records older than the retention window. Setting `ACTIVITYLOG_ENABLED=false` disables audit logging entirely.
