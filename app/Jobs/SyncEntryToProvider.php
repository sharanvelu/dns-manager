<?php

namespace App\Jobs;

use App\Connectors\Exceptions\RecordNotFoundException;
use App\Enums\SyncStatus;
use App\Models\DnsEntry;
use App\Models\EntrySyncState;
use App\Models\SyncLog;
use App\Models\ZoneProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncEntryToProvider implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    public function __construct(
        public int $entryId,
        public int $zoneProviderId,
    ) {}

    public function handle(): void
    {
        $entry = DnsEntry::with('zone')->find($this->entryId);
        $zoneProvider = ZoneProvider::with(['provider', 'zone'])->find($this->zoneProviderId);

        if (! $entry || ! $zoneProvider || ! $zoneProvider->isActive()) {
            return;
        }

        $state = $entry->syncStates()->where('zone_provider_id', $zoneProvider->id)->first();

        if (! $state || $state->sync_status === SyncStatus::Deleting) {
            return;
        }

        $connector = $zoneProvider->connector();

        try {
            $externalId = $state->external_id
                ? $connector->updateRecord($entry, $state->external_id)
                : $connector->createRecord($entry);
        } catch (RecordNotFoundException) {
            // The tracked record was deleted at the provider out-of-band
            // (classic drift) — recreate it instead of failing the push.
            $externalId = $connector->createRecord($entry);
        }

        $state->update([
            'external_id' => $externalId,
            'sync_status' => SyncStatus::Synced,
            'last_synced_at' => now(),
            'last_error' => null,
        ]);

        SyncLog::record($zoneProvider->provider, $entry, 'push', 'success', "{$entry->type->value} {$entry->fqdn} synced to {$zoneProvider->label()}");
    }

    public function failed(?Throwable $exception): void
    {
        $entry = DnsEntry::find($this->entryId);
        $zoneProvider = ZoneProvider::with('provider')->find($this->zoneProviderId);

        EntrySyncState::query()
            ->where('dns_entry_id', $this->entryId)
            ->where('zone_provider_id', $this->zoneProviderId)
            ->update([
                'sync_status' => SyncStatus::Error,
                'last_error' => $exception?->getMessage(),
            ]);

        SyncLog::record($zoneProvider?->provider, $entry, 'push', 'error', $exception?->getMessage());
    }
}
