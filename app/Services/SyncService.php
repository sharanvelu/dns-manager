<?php

declare(strict_types = 1);

namespace App\Services;

use App\Models\DnsEntry;
use App\Enums\SyncStatus;
use App\Models\ZoneProvider;
use App\Models\EntrySyncState;
use App\Jobs\SyncEntryToProvider;
use App\Jobs\DeleteEntryFromProvider;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
     * reconfiguration) get a remote delete — or, with $keepRemote, are
     * released: the app stops managing them and the record stays at the
     * provider.
     *
     * @return list<string> provider names whose records were released with $keepRemote
     */
    public function syncEntry(DnsEntry $entry, ?array $zoneProviderIds = null, bool $keepRemote = false): array
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
        $released = $this->removeStates(
            $entry->syncStates()->whereNotIn('zone_provider_id', $targets->pluck('id')),
            $keepRemote,
        );

        foreach ($targets as $attachment) {
            $entry->syncStates()->updateOrCreate(
                ['zone_provider_id' => $attachment->id],
                ['sync_status' => SyncStatus::Pending, 'last_error' => null, 'drift_details' => null],
            );

            SyncEntryToProvider::dispatch($entry->id, $attachment->id);
        }

        return $released;
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
     * With $keepRemote the records stay at the provider (see removeState).
     *
     * @return list<string> provider names whose records were released with $keepRemote
     */
    public function detachEntry(DnsEntry $entry, array $zoneProviderIds, bool $keepRemote = false): array
    {
        return $this->removeStates(
            $entry->syncStates()->whereIn('zone_provider_id', $zoneProviderIds),
            $keepRemote,
        );
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
     * @param  HasMany<EntrySyncState, DnsEntry>  $states
     * @return list<string>
     */
    private function removeStates(HasMany $states, bool $keepRemote): array
    {
        // Releasing never cancels a remote delete that is already in flight.
        return $states
            ->when($keepRemote, fn ($query) => $query->where('sync_status', '!=', SyncStatus::Deleting))
            ->with('zoneProvider.provider')
            ->get()
            ->filter(fn (EntrySyncState $state) => $this->removeState($state, $keepRemote))
            ->pluck('zoneProvider.provider.name')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Unassign one attachment and never touch paused ones. By default a
     * remote delete is queued when a record exists there (the bare state is
     * dropped otherwise). With $keepRemote the state is dropped locally
     * and NO delete is queued — the record stays at the provider, no longer
     * managed by the app.
     *
     * Returns true when a pushed record was released (kept remotely).
     */
    private function removeState(EntrySyncState $state, bool $keepRemote = false): bool
    {
        if ($state->zoneProvider && ! $state->zoneProvider->isActive()) {
            return false;
        }

        if ($keepRemote) {
            $state->delete();

            return $state->external_id !== null;
        }

        if ($state->external_id) {
            $state->update(['sync_status' => SyncStatus::Deleting]);
            DeleteEntryFromProvider::dispatch($state->id);
        } else {
            $state->delete();
        }

        return false;
    }
}
