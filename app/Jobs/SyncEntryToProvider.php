<?php

namespace App\Jobs;

use App\Enums\SyncStatus;
use App\Models\DnsEntry;
use App\Models\Provider;
use App\Models\SyncLog;
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
        public int $providerId,
    ) {}

    public function handle(): void
    {
        $entry = DnsEntry::find($this->entryId);
        $provider = Provider::find($this->providerId);

        if (! $entry || ! $provider || ! $provider->enabled) {
            return;
        }

        $state = $entry->syncStates()->where('provider_id', $provider->id)->first();

        if (! $state || $state->sync_status === SyncStatus::Deleting) {
            return;
        }

        $connector = $provider->connector();

        $externalId = $state->external_id
            ? $connector->updateRecord($entry, $state->external_id)
            : $connector->createRecord($entry);

        $state->update([
            'external_id' => $externalId,
            'sync_status' => SyncStatus::Synced,
            'last_synced_at' => now(),
            'last_error' => null,
        ]);

        SyncLog::record($provider, $entry, 'push', 'success', "{$entry->type->value} {$entry->name} synced");
    }

    public function failed(?Throwable $exception): void
    {
        $entry = DnsEntry::find($this->entryId);
        $provider = Provider::find($this->providerId);

        $entry?->syncStates()->where('provider_id', $this->providerId)->update([
            'sync_status' => SyncStatus::Error,
            'last_error' => $exception?->getMessage(),
        ]);

        SyncLog::record($provider, $entry, 'push', 'error', $exception?->getMessage());
    }
}
