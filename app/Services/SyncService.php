<?php

namespace App\Services;

use App\Enums\SyncStatus;
use App\Jobs\DeleteEntryFromProvider;
use App\Jobs\SyncEntryToProvider;
use App\Models\DnsEntry;
use App\Models\Provider;

class SyncService
{
    /**
     * Push an entry to its selected providers.
     *
     * $providerIds is the explicit selection from the entry form. When null
     * (manual re-sync, API create without a selection) the entry's existing
     * provider assignment is reused — or, for a brand-new entry with no
     * assignment yet, every compatible enabled provider (the default).
     * Providers that used to hold the entry but no longer apply (type change,
     * deselection, provider reconfiguration) get a remote delete.
     */
    public function syncEntry(DnsEntry $entry, ?array $providerIds = null): void
    {
        $targets = Provider::query()
            ->where('enabled', true)
            ->get()
            ->filter(fn (Provider $provider) => $provider->managesType($entry->type->value));

        if ($providerIds === null) {
            $assigned = $entry->syncStates()
                ->where('sync_status', '!=', SyncStatus::Deleting)
                ->pluck('provider_id');

            if ($assigned->isNotEmpty()) {
                $targets = $targets->whereIn('id', $assigned);
            }
        } else {
            $targets = $targets->whereIn('id', $providerIds);
        }

        // Remove from providers that no longer apply. States belonging to
        // disabled providers are left untouched ("paused"), so temporarily
        // disabling a provider never deletes its remote records.
        $entry->syncStates()
            ->whereNotIn('provider_id', $targets->pluck('id'))
            ->with('provider')
            ->get()
            ->each(function ($state) {
                if ($state->provider && ! $state->provider->enabled) {
                    return;
                }

                if ($state->external_id) {
                    $state->update(['sync_status' => SyncStatus::Deleting]);
                    DeleteEntryFromProvider::dispatch($state->id);
                } else {
                    $state->delete();
                }
            });

        foreach ($targets as $provider) {
            $entry->syncStates()->updateOrCreate(
                ['provider_id' => $provider->id],
                ['sync_status' => SyncStatus::Pending, 'last_error' => null],
            );

            SyncEntryToProvider::dispatch($entry->id, $provider->id);
        }
    }

    /**
     * Delete an entry everywhere. Remote deletions happen in queued jobs;
     * the local row disappears once every provider has confirmed.
     */
    public function deleteEntry(DnsEntry $entry): void
    {
        $states = $entry->syncStates()->get();

        $pendingRemote = $states->filter(fn ($state) => $state->external_id !== null);

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
}
