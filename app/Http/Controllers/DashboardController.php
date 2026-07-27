<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use App\Models\DnsZone;
use App\Models\SyncLog;
use App\Models\DnsEntry;
use App\Models\Provider;
use App\Enums\SyncStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        // Null means unrestricted (Super Admin/Viewer) — those users keep
        // the exact pre-ZBAC dashboard shape.
        $accessibleZoneIds = $user->accessibleZoneIds();

        $entriesWithStates = DnsEntry::query()
            ->when($accessibleZoneIds !== null, fn ($q) => $q->whereIn('dns_zone_id', $accessibleZoneIds))
            ->withCount([
                'syncStates',
                'syncStates as synced_states_count' => fn ($q) => $q->where('sync_status', SyncStatus::Synced),
                'syncStates as drifted_states_count' => fn ($q) => $q->where('sync_status', SyncStatus::Drifted),
                'syncStates as error_states_count' => fn ($q) => $q->where('sync_status', SyncStatus::Error),
            ])
            ->get();

        // Provider health is a global concern — zone-scoped users get an
        // empty list and zeroed provider stats instead.
        $providers = $accessibleZoneIds === null
            ? Provider::query()
                ->withCount([
                    'syncStates as records_count',
                    'syncStates as synced_count' => fn ($q) => $q->where('sync_status', SyncStatus::Synced),
                    'syncStates as drifted_count' => fn ($q) => $q->where('sync_status', SyncStatus::Drifted),
                    'syncStates as error_count' => fn ($q) => $q->where('sync_status', SyncStatus::Error),
                ])
                ->orderBy('name')
                ->get()
                ->map(fn (Provider $provider) => [
                    'id' => $provider->id,
                    'name' => $provider->name,
                    'type' => $provider->type->value,
                    'typeLabel' => $provider->type->label(),
                    'enabled' => $provider->enabled,
                    'healthStatus' => $provider->health_status->value,
                    'healthMessage' => $provider->health_message,
                    'lastCheckedAt' => $provider->last_checked_at?->toIso8601String(),
                    'recordsCount' => $provider->records_count,
                    'syncedCount' => $provider->synced_count,
                    'driftedCount' => $provider->drifted_count,
                    'errorCount' => $provider->error_count,
                ])
            : collect();

        $entriesByZone = $entriesWithStates->groupBy('dns_zone_id');

        $zones = DnsZone::query()
            ->when($accessibleZoneIds !== null, fn ($q) => $q->whereIn('id', $accessibleZoneIds))
            ->with('zoneProviders.provider:id,type')
            ->orderBy('name')
            ->get()
            ->map(function (DnsZone $zone) use ($entriesByZone) {
                $entries = $entriesByZone->get($zone->id, collect());

                return [
                    'id' => $zone->id,
                    'name' => $zone->name,
                    'entriesCount' => $entries->count(),
                    'syncedCount' => self::inSyncCount($entries),
                    'driftedCount' => $entries->filter(fn ($e) => $e->drifted_states_count > 0)->count(),
                    'erroredCount' => $entries->filter(fn ($e) => $e->error_states_count > 0)->count(),
                    'providerTypes' => $zone->zoneProviders
                        ->map(fn ($attachment) => $attachment->provider?->type->value)
                        ->filter()
                        ->unique()
                        ->values(),
                ];
            });

        $activity = SyncLog::query()
            ->when($accessibleZoneIds !== null, fn ($q) => $q->whereIn('dns_zone_id', $accessibleZoneIds))
            ->with(['provider:id,name,type', 'entry:id,name,type', 'zone:id,name'])
            ->latest()
            ->latest('id')
            ->limit(15)
            ->get()
            ->map(fn (SyncLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'status' => $log->status,
                'message' => $log->message,
                'provider' => $log->provider?->only(['id', 'name']),
                'entry' => $log->entry?->only(['id', 'name']),
                'zone' => $log->zone?->only(['id', 'name']),
                'createdAt' => $log->created_at->toIso8601String(),
            ]);

        return Inertia::render('dashboard', [
            'stats' => [
                'totalEntries' => $entriesWithStates->count(),
                'inSync' => self::inSyncCount($entriesWithStates),
                'drifted' => $entriesWithStates->filter(fn ($e) => $e->drifted_states_count > 0)->count(),
                'errored' => $entriesWithStates->filter(fn ($e) => $e->error_states_count > 0)->count(),
                'providersTotal' => $providers->count(),
                'providersHealthy' => $providers->where('healthStatus', 'ok')->count(),
            ],
            'providers' => $providers->values(),
            'zones' => $zones,
            'activity' => $activity,
            // Zone-scoped user with no grants at all — the frontend renders
            // an empty state (pointing User Admins at user management).
            'noAccess' => $accessibleZoneIds === [],
            'isUserAdmin' => $user->isUserAdmin(),
        ]);
    }

    /**
     * Entries that are fully in sync: at least one state and all synced.
     */
    private static function inSyncCount(Collection $entries): int
    {
        return $entries
            ->filter(fn ($e) => $e->sync_states_count > 0 && $e->synced_states_count === $e->sync_states_count)
            ->count();
    }
}
