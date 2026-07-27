<?php

declare(strict_types = 1);

namespace App\Jobs;

use Throwable;
use App\Models\SyncLog;
use App\Models\Provider;
use App\Enums\SyncStatus;
use App\Enums\HealthStatus;
use App\Models\EntrySyncState;
use Illuminate\Support\Collection;
use App\Connectors\ConnectorRegistry;
use App\Connectors\DTOs\RemoteRecord;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Connectors\DTOs\ConnectorCapabilities;

class CheckProviderDrift implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $providerId)
    {
    }

    public function handle(): void
    {
        $provider = Provider::find($this->providerId);

        if (! $provider || ! $provider->enabled) {
            return;
        }

        $capabilities = $provider->connector()::capabilities();

        $capabilities->supportsZones
            ? $this->checkZonedProvider($provider, $capabilities)
            : $this->checkZonelessProvider($provider, $capabilities);
    }

    /**
     * Zoned connectors (Cloudflare) list records per zone attachment: each
     * enabled attachment is checked with its own zone-scoped connector, and
     * one failing zone never blocks the drift check of the others.
     */
    protected function checkZonedProvider(Provider $provider, ConnectorCapabilities $capabilities): void
    {
        $checked = 0;
        $drifted = 0;
        $failures = [];

        $attachments = $provider->zoneProviders()
            ->where('enabled', true)
            ->with('zone')
            ->get();

        foreach ($attachments as $attachment) {
            try {
                $remote = $attachment->connector()->listRecords();
            } catch (Throwable $e) {
                $failures[$attachment->zone->name] = $e->getMessage();

                SyncLog::record($provider, null, 'drift-check', 'error', "{$attachment->zone->name}: {$e->getMessage()}", zoneId: $attachment->dns_zone_id);

                continue;
            }

            $states = EntrySyncState::query()
                ->where('zone_provider_id', $attachment->id)
                ->whereIn('sync_status', [SyncStatus::Synced, SyncStatus::Drifted])
                ->whereNotNull('external_id')
                ->with('entry.zone')
                ->get();

            $checked += $states->count();
            $drifted += $this->compareStates($states, $remote, $capabilities);
        }

        $this->finish($provider, $checked, $drifted, $failures);
    }

    /**
     * Zoneless connectors (Pi-hole) list the whole instance in one call —
     * no zone context needed. External ids are FQDN-based, so states from
     * every attached zone compare against the single listing unambiguously.
     */
    protected function checkZonelessProvider(Provider $provider, ConnectorCapabilities $capabilities): void
    {
        try {
            $remote = app(ConnectorRegistry::class)
                ->make($provider->type->value, $provider)
                ->listRecords();
        } catch (Throwable $e) {
            $provider->update([
                'health_status' => HealthStatus::Error,
                'health_message' => $e->getMessage(),
                'last_checked_at' => now(),
            ]);

            SyncLog::record($provider, null, 'drift-check', 'error', $e->getMessage());

            return;
        }

        $states = $provider->syncStates()
            ->whereIn('sync_status', [SyncStatus::Synced, SyncStatus::Drifted])
            ->whereNotNull('external_id')
            ->with(['entry.zone', 'zoneProvider'])
            ->get();

        $drifted = $this->compareStates($states, $remote, $capabilities);

        $this->finish($provider, $states->count(), $drifted, []);
    }

    /**
     * @param  Collection<int, EntrySyncState>  $states
     * @param  Collection<int, RemoteRecord>  $remote
     */
    protected function compareStates(Collection $states, Collection $remote, ConnectorCapabilities $capabilities): int
    {
        $drifted = 0;

        // Ids the checked states already track — the same-name fallback below
        // must never grab a sibling record another entry legitimately holds.
        $claimed = $states->pluck('external_id')->filter()->flip();

        foreach ($states as $state) {
            $entry = $state->entry;

            if (! $entry) {
                continue;
            }

            $candidates = $remote->where('externalId', $state->external_id);

            // The tracked record as the provider holds it now. Tuple-encoded
            // external ids (Technitium, Pi-hole) change with the record's
            // data, so a remotely edited record has no id match — fall back
            // to the unclaimed remote record at the same name+type.
            $closest = $candidates->first(fn (RemoteRecord $record) => $record->matches($entry, $capabilities))
                ?? $candidates->first()
                ?? $remote->first(fn (RemoteRecord $record) => $record->type === $entry->type->value
                    && strcasecmp(rtrim($record->name, '.'), rtrim($entry->fqdn, '.')) === 0
                    && ! $claimed->has($record->externalId));

            if ($closest?->matches($entry, $capabilities)) {
                // Adopt the current identity: a record recreated out-of-band
                // with identical data is in sync, just under a new id.
                $state->update([
                    'sync_status' => SyncStatus::Synced,
                    'external_id' => $closest->externalId,
                    'last_error' => null,
                    'drift_details' => null,
                ]);

                continue;
            }

            $state->update([
                'sync_status' => SyncStatus::Drifted,
                // Re-point at the record as it exists now, so a re-push
                // UPDATES it back in place — addressing the stale id would
                // recreate the tracked value alongside the edited record.
                'external_id' => $closest?->externalId ?? $state->external_id,
                'last_error' => $closest === null
                    ? 'Record no longer exists at the provider.'
                    : 'Remote record differs from the managed entry.',
                'drift_details' => $closest?->diff($entry, $capabilities) ?: null,
            ]);

            $drifted++;
        }

        return $drifted;
    }

    /**
     * @param  array<string, string>  $failures  zone name => error message
     */
    protected function finish(Provider $provider, int $checked, int $drifted, array $failures): void
    {
        $provider->update([
            'health_status' => $failures === [] ? HealthStatus::Ok : HealthStatus::Error,
            'health_message' => $failures === []
                ? null
                : collect($failures)->map(fn (string $message, string $zone) => "{$zone}: {$message}")->implode('; '),
            'last_checked_at' => now(),
        ]);

        $summary = sprintf('Checked %d record(s), %d drifted', $checked, $drifted);

        if ($failures !== []) {
            $summary .= sprintf(', %d zone(s) failed', count($failures));
        }

        SyncLog::record($provider, null, 'drift-check', $failures === [] ? 'success' : 'error', $summary);
    }
}
