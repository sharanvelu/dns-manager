<?php

declare(strict_types = 1);

namespace App\Support;

use App\Models\DnsEntry;
use App\Models\ZoneProvider;

/**
 * Shared Inertia presenters for entry listings — the global entries page
 * and the zone-scoped records page emit the exact same shapes.
 */
class EntryPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function entry(DnsEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'zone' => ['id' => $entry->zone->id, 'name' => $entry->zone->name],
            'name' => $entry->name,
            'fqdn' => $entry->fqdn,
            'type' => $entry->type->value,
            'content' => $entry->content,
            'ttl' => $entry->ttl,
            'priority' => $entry->priority,
            'proxied' => $entry->proxied,
            'comment' => $entry->comment,
            'updatedAt' => $entry->updated_at->toIso8601String(),
            'syncStates' => $entry->syncStates->map(fn ($state) => [
                'id' => $state->id,
                'zoneProviderId' => $state->zone_provider_id,
                'providerId' => $state->zoneProvider?->provider?->id,
                'providerName' => $state->zoneProvider?->provider?->name,
                'providerType' => $state->zoneProvider?->provider?->type->value,
                'status' => $state->sync_status->value,
                'lastSyncedAt' => $state->last_synced_at?->toIso8601String(),
                'lastError' => $state->last_error,
                'driftDetails' => $state->drift_details,
            ])->values(),
        ];
    }

    /**
     * Per-zone provider attachments, keyed by zone id — the entry form
     * offers exactly the selected zone's attachments as sync targets.
     * Pass zone ids to limit the map (null = all zones, for unrestricted
     * Super Admin/Viewer callers).
     *
     * @param  array<int>|null  $zoneIds
     * @return array<int|string, list<array<string, mixed>>>
     */
    public static function zoneAttachments(?array $zoneIds = null): array
    {
        return ZoneProvider::query()
            ->when($zoneIds !== null, fn ($query) => $query->whereIn('dns_zone_id', $zoneIds))
            ->with('provider:id,name,type,enabled,managed_record_types')
            ->get()
            ->groupBy('dns_zone_id')
            ->map(fn ($attachments) => $attachments
                ->sortBy(fn (ZoneProvider $attachment) => $attachment->provider->name)
                ->map(fn (ZoneProvider $attachment) => [
                    'id' => $attachment->id,
                    'providerId' => $attachment->provider->id,
                    'providerName' => $attachment->provider->name,
                    'providerType' => $attachment->provider->type->value,
                    'enabled' => $attachment->enabled && $attachment->provider->enabled,
                    'managedRecordTypes' => $attachment->provider->managed_record_types ?? [],
                ])
                ->values()
                ->all())
            ->all();
    }
}
