<?php

declare(strict_types = 1);

namespace App\Jobs;

use App\Models\DnsZone;
use App\Models\SyncLog;
use App\Models\Provider;
use App\Models\ZoneProvider;
use Illuminate\Support\Facades\Bus;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The tail of a "detach and delete records" chain: runs only after every
 * DeleteEntryFromProvider job ahead of it in the chain succeeded (a failed
 * delete halts the chain, so the attachment — and its remaining states —
 * stay put for a retry). Removing the row cascades whatever states are left
 * (never-pushed ones), so nothing is orphaned locally. Records pushed while
 * the chain was draining are caught here and get a follow-up chain, so the
 * detach never strands a record the user asked to delete.
 */
class DetachZoneProvider implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $zoneProviderId, public int $deletedRecords = 0)
    {
    }

    /**
     * Queue a remote delete per pushed state, then this finalizer.
     *
     * @param  array<int, int>  $stateIds
     */
    public static function chainFor(ZoneProvider $zoneProvider, array $stateIds, int $alreadyDeleted = 0): void
    {
        Bus::chain([
            ...array_map(fn (int $id) => new DeleteEntryFromProvider($id), $stateIds),
            new self($zoneProvider->id, $alreadyDeleted + count($stateIds)),
        ])->dispatch();
    }

    public function handle(): void
    {
        $zoneProvider = ZoneProvider::with(['provider', 'zone'])->find($this->zoneProviderId);

        if (! $zoneProvider) {
            return;
        }

        /** @var Provider $provider */
        $provider = $zoneProvider->provider;
        /** @var DnsZone $zone */
        $zone = $zoneProvider->zone;

        $late = $zoneProvider->syncStates()->whereNotNull('external_id')->pluck('id');

        if ($late->isNotEmpty()) {
            // Paused mid-detach: never delete against a disabled provider.
            // The attachment stays; the user can retry once it is enabled.
            if (! $zoneProvider->isActive()) {
                SyncLog::record($provider, null, 'detach', 'error', "{$zoneProvider->label()} was disabled while detaching — it stays attached; enable it and retry", zoneId: $zone->id);

                return;
            }

            self::chainFor($zoneProvider, $late->all(), $this->deletedRecords);

            return;
        }

        $label = $zoneProvider->label();

        $zoneProvider->delete();

        SyncLog::record(
            $provider,
            null,
            'detach',
            'success',
            "{$label} detached after deleting {$this->deletedRecords} " . str('record')->plural($this->deletedRecords) . ' at the provider',
            zoneId: $zone->id,
        );

        // System-caused, like the trait's deferred `deleted` event — the
        // request was attributed by `provider-detach-requested`.
        activity('zones')
            ->performedOn($zone)
            ->event('provider-detached')
            ->withProperties(['provider' => $provider->name, 'records_deleted' => true])
            ->log("detached provider {$provider->name}");
    }
}
