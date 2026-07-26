---
title: Activity Log
nav_order: 8
description: The audit trail — who changed what and when, across zones, entries, providers, users, and sign-ins.
---

# Activity Log

The activity log is DNS Manager's audit trail: a permanent record of **who changed what, and when**. Every change made through the app is attributed to the signed-in user who made it. It is separate from the [Dashboard](dashboard) activity feed, which tracks background sync jobs — see [What is not recorded](#what-is-not-recorded).

## Who can see it

Visibility follows the [role system](users):

| View | Who sees it |
| --- | --- |
| **Global trail** (sidebar **Settings → Activity** — everything across zones, providers, users, sign-ins) | **Super Admins** and **Super Viewers** only. |
| **Zone Activity tab** (one zone's history) | The zone's **Zone Admins** and **Viewers**, plus Super Admins and Super Viewers. |
| **Per-record Activity dialogs** (kebab menus) | Entry dialogs follow the entry's zone: Zone Admin / Viewer of that zone, plus the supers. Provider dialogs and the zones-list dialog are global views — Super Admins and Super Viewers only. |

A zone **DNS Manager** deliberately has **no activity access at all** — they can change records but not read the audit trail; grant the Viewer role alongside if they should. Every entry point — sidebar item, tabs, menu items — is hidden for anyone not allowed, and the server enforces the same rules regardless of what the browser sends.

## What is recorded

| Area | Events |
| --- | --- |
| **Zones** | Created, updated, and deleted, with old → new diffs for the name and description. Attachment changes are logged on the zone: **provider-attached**, **attachment-updated** (settings or per-zone enable/disable — recorded as the fact that the configuration changed, never any value), and **provider-detached**, each naming the provider involved. |
| **DNS entries** | Created, updated, and deleted, with field-level old → new diffs for name, type, content, TTL, priority, proxied, and comment. Every entry event is **stamped with its zone**, and the stamp survives even after the entry — or the whole zone — is deleted. When a deletion is deferred to queued provider cleanup, a **delete-requested** event attributes the request to the user who asked for it (the eventual **deleted** event is recorded by the system once the last provider confirms). Bulk provider reassignment logs a **providers-changed** event listing the providers each entry now syncs to. |
| **Providers** | Created, updated, and deleted, covering the name, type, enabled flag, and managed record types. A credential change is recorded as **updated connection settings** — see below. |
| **Users** | Name, email, and global role changes. Role changes show the old → new roles and are attributed to the admin who made them. **Zone access grants are audited here too**: granting, changing, or revoking a user's zone roles produces a **zone-access-granted**, **zone-access-updated**, or **zone-access-revoked** event naming the user, the zone, and the granted roles. |
| **Sign-ins** | A **login** and **logout** event per user (via your OIDC provider). |

## What is not recorded

- **Provider secrets, ever.** API tokens, app passwords, and other connection settings — including per-zone attachment settings — are never written to the activity log, not even in encrypted form. Changing credentials or attachment configuration produces an event that carries only the fact that the configuration changed, never any value.
- **Background sync and health checks.** Pushes, remote deletes, imports, drift checks, and provider health checks are machine activity, not user actions — they appear in the [Dashboard](dashboard) recent-activity feed instead. Provider health status updates never generate audit events.

## The viewer

The **Activity** page (sidebar, Settings group — Super Admins and Super Viewers) lists activities newest-first, paginated. You can filter by:

- **Subject type** — Entries, Providers, Users, or Zones.
- **Specific record** — narrow to one entry, provider, user, or zone.
- **Event** — created, updated, deleted, provider-attached, login, and so on.
- **User** — who performed the action.
- **Date range** — from/to.

Filters combine. Expanding a row shows the field-level changes as old → new values.

### Per-zone activity

Each zone has its own pre-filtered view — the **Activity tab** on the [zone's page](zones#the-zone-page), visible to the zone's Zone Admins and Viewers as well as the supers. It shows everything that happened in the zone: events on the zone itself (edits, attachments) plus every entry event stamped with the zone. The same scoping is available in the full viewer via the `zone_id` query parameter. Because the zone stamp is stored on each activity, an entry's history remains attributed to its zone even after the entry is deleted.

### History of deleted records

Activities are never removed when their record is deleted — the full history of a deleted zone, entry, provider, or user stays viewable, with the record's name recovered from its last logged snapshot.

## Quick access from a record

Every record type offers an **Activity** item that opens a dialog with its history: entry rows on the [DNS Entries](dns-entries) page and the zone page header follow zone-activity access (Zone Admin / Viewer of that zone, plus the supers — entry dialogs load their data through the zone-scoped endpoint); provider cards on the [Providers](providers) page and the zones-list kebab appear for Super Admins and Super Viewers. The **Open full activity log** link at the bottom of the dialog jumps to the global viewer pre-filtered to the record — it is shown only to Super Admins and Super Viewers, since the full viewer is the global trail.

## Retention

Activities are kept for **365 days** by default (`clean_after_days` in `config/activitylog.php`). Pruning is not automatic — run `php artisan activitylog:clean` (for example from a cron job) to delete records older than the retention window. Setting `ACTIVITYLOG_ENABLED=false` disables audit logging entirely.

## Flushing the log

To wipe the audit trail on demand, run the app's flush command (it is never scheduled — flushing is always an explicit decision):

```sh
php artisan dns:flush-activities            # everything, asks for confirmation
php artisan dns:flush-activities --days=90  # only records older than 90 days
php artisan dns:flush-activities --force    # skip the confirmation prompt
```

The command reports how many records it deleted. This permanently erases audit history — who changed what is unrecoverable afterwards — and does not touch the [dashboard's sync activity feed](dashboard#recent-activity), which is a separate log of background jobs.
