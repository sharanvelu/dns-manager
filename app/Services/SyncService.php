<?php

declare(strict_types = 1);

namespace App\Services;

use App\Models\DnsEntry;
use App\Enums\SyncStatus;
use App\Models\ZoneProvider;
use App\Models\EntrySyncState;
use App\Jobs\SyncEntryToProvider;
use App\Jobs\DeleteEntryFromProvider;

class SyncService
{
    /**
     * Push an entry to its zone's provider attachments.
     *
     * $zoneProviderIds is the explicit attachment selection from the entry
     * form. When null (manual re-sync, API create without a selection) the
     * entry's existing attachment assignment is reused — or, for a brand-new
     * entry with no assignment yet, every compatible active attachment of
     * its zone (the default). A manual re-sync of an existing entry that is
     * deliberately assigned nowhere stays a no-op. Attachments that used to
     * hold the entry but no longer apply (type change, deselection,
     * reconfiguration) get a remote delete.
     */
    public function syncEntry(DnsEntry $entry, ?array $zoneProviderIds = null): void
    {
        $entry->loadMissing('zone');

        $candidates = $entry->zone->zoneProviders()
            ->with(['provider', 'zone'])
            ->get()
            ->filter(fn (ZoneProvider $attachment) => $attachment->managesType($entry->type->value));

        if ($zoneProviderIds === null) {
            $assigned = $entry->syncStates()
                ->where('sync_status', '!=', SyncStatus::Deleting)
                ->pluck('zone_provider_id');

            $targets = match (true) {
                $assigned->isNotEmpty() => $candidates->whereIn('id', $assigned),
                $entry->wasRecentlyCreated => $candidates,
                default => $candidates->whereIn('id', []),
            };
        } else {
            $targets = $candidates->whereIn('id', $zoneProviderIds);
        }

        // Remove from attachments that no longer apply. States belonging to
        // inactive attachments (attachment or provider disabled) are left
        // untouched ("paused"), so temporarily disabling never deletes the
        // remote records.
        $entry->syncStates()
            ->whereNotIn('zone_provider_id', $targets->pluck('id'))
            ->with('zoneProvider.provider')
            ->get()
            ->each(fn (EntrySyncState $state) => $this->removeState($state));

        foreach ($targets as $attachment) {
            $entry->syncStates()->updateOrCreate(
                ['zone_provider_id' => $attachment->id],
                ['sync_status' => SyncStatus::Pending, 'last_error' => null, 'drift_details' => null],
            );

            SyncEntryToProvider::dispatch($entry->id, $attachment->id);
        }
    }

    /**
     * Add attachments to an entry's assignment without touching the rest of
     * it. Only attachments of the entry's own zone that manage its record
     * type apply; already-assigned ones are simply re-pushed.
     */
    public function attachEntry(DnsEntry $entry, array $zoneProviderIds): void
    {
        $entry->loadMissing('zone');

        $targets = $entry->zone->zoneProviders()
            ->with(['provider', 'zone'])
            ->whereIn('id', $zoneProviderIds)
            ->get()
            ->filter(fn (ZoneProvider $attachment) => $attachment->managesType($entry->type->value));

        foreach ($targets as $attachment) {
            $entry->syncStates()->updateOrCreate(
                ['zone_provider_id' => $attachment->id],
                ['sync_status' => SyncStatus::Pending, 'last_error' => null, 'drift_details' => null],
            );

            SyncEntryToProvider::dispatch($entry->id, $attachment->id);
        }
    }

    /**
     * Remove specific attachments from an entry's assignment, leaving every
     * other assignment untouched (no re-push, unlike a replace). Paused
     * attachments keep their records AND their assignment — the paused
     * invariant: deletes are never queued against a disabled provider.
     */
    public function detachEntry(DnsEntry $entry, array $zoneProviderIds): void
    {
        $entry->syncStates()
            ->whereIn('zone_provider_id', $zoneProviderIds)
            ->with('zoneProvider.provider')
            ->get()
            ->each(fn (EntrySyncState $state) => $this->removeState($state));
    }

    /**
     * Delete an entry everywhere. Remote deletions happen in queued jobs;
     * the local row disappears once every attachment has confirmed. Records
     * held by inactive attachments (attachment or provider disabled) are
     * left in place — the paused invariant: deletes are never queued
     * against a disabled provider, whose connector may be unreachable.
     */
    public function deleteEntry(DnsEntry $entry): void
    {
        $states = $entry->syncStates()->with('zoneProvider.provider')->get();

        $pendingRemote = $states->filter(
            fn (EntrySyncState $state) => $state->external_id !== null
                && $state->zoneProvider?->isActive(),
        );

        $states->diff($pendingRemote)->each->delete();

        if ($pendingRemote->isEmpty()) {
            $entry->delete();

            return;
        }

        foreach ($pendingRemote as $state) {
            $state->update(['sync_status' => SyncStatus::Deleting]);
            DeleteEntryFromProvider::dispatch($state->id);
        }
    }

    /**
     * Unassign one attachment: queue a remote delete when a record exists
     * there, drop the bare state otherwise — and never touch paused ones.
     */
    private function removeState(EntrySyncState $state): void
    {
        if ($state->zoneProvider && ! $state->zoneProvider->isActive()) {
            return;
        }

        if ($state->external_id) {
            $state->update(['sync_status' => SyncStatus::Deleting]);
            DeleteEntryFromProvider::dispatch($state->id);
        } else {
            $state->delete();
        }
    }
}
