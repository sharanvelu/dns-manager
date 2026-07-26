<?php

namespace App\Http\Controllers;

use App\Connectors\ConnectorRegistry;
use App\Enums\SyncStatus;
use App\Http\Requests\ZoneRequest;
use App\Models\DnsEntry;
use App\Models\DnsZone;
use App\Models\Provider;
use App\Models\ZoneProvider;
use App\Services\SyncService;
use App\Services\ZoneAttachmentService;
use App\Support\ActivityQuery;
use App\Support\EntryPresenter;
use App\Support\EntryQuery;
use App\Support\ZonePermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class ZoneController extends Controller
{
    public function __construct(private ConnectorRegistry $registry) {}

    public function index(Request $request): Response
    {
        $accessibleZoneIds = $request->user()->accessibleZoneIds();

        $entriesByZone = DnsEntry::query()
            ->when($accessibleZoneIds !== null, fn ($q) => $q->whereIn('dns_zone_id', $accessibleZoneIds))
            ->withCount([
                'syncStates',
                'syncStates as synced_states_count' => fn ($q) => $q->where('sync_status', SyncStatus::Synced),
                'syncStates as drifted_states_count' => fn ($q) => $q->where('sync_status', SyncStatus::Drifted),
                'syncStates as error_states_count' => fn ($q) => $q->where('sync_status', SyncStatus::Error),
            ])
            ->get()
            ->groupBy('dns_zone_id');

        $zones = DnsZone::query()
            ->when($accessibleZoneIds !== null, fn ($q) => $q->whereIn('id', $accessibleZoneIds))
            ->with(['zoneProviders.provider:id,name,type,enabled'])
            ->orderBy('name')
            ->get()
            ->map(function (DnsZone $zone) use ($entriesByZone) {
                $entries = $entriesByZone->get($zone->id, collect());

                return [
                    'id' => $zone->id,
                    'name' => $zone->name,
                    'description' => $zone->description,
                    'entriesCount' => $entries->count(),
                    'syncedCount' => self::inSyncCount($entries),
                    'driftedCount' => $entries->filter(fn ($e) => $e->drifted_states_count > 0)->count(),
                    'erroredCount' => $entries->filter(fn ($e) => $e->error_states_count > 0)->count(),
                    'providers' => $zone->zoneProviders->map(fn (ZoneProvider $attachment) => [
                        'id' => $attachment->id,
                        'providerId' => $attachment->provider->id,
                        'name' => $attachment->provider->name,
                        'type' => $attachment->provider->type->value,
                        'enabled' => $attachment->enabled,
                    ])->values(),
                    'createdAt' => $zone->created_at?->toIso8601String(),
                ];
            });

        // Provider names are create-dialog fodder — only zone creators
        // (Super Admins) get them; everyone else sees empty arrays.
        $providers = $request->user()->can('create-zones')
            ? Provider::query()
                ->orderBy('name')
                ->get()
                ->map(fn (Provider $provider) => [
                    'id' => $provider->id,
                    'name' => $provider->name,
                    'type' => $provider->type->value,
                    'enabled' => $provider->enabled,
                    'supportsZones' => $this->supportsZones($provider),
                ])
            : collect();

        return Inertia::render('zones/index', [
            'zones' => $zones,
            'providers' => $providers->values(),
            // Enabled zoneless providers auto-attach to every new zone — the
            // create dialog lists them so the behavior is never a surprise.
            'zonelessProviders' => $providers
                ->filter(fn (array $provider) => $provider['enabled'] && ! $provider['supportsZones'])
                ->pluck('name')
                ->values(),
        ]);
    }

    /**
     * The zone has no landing page of its own — every /zones/{id} link
     * (dashboard cards, provider chips, breadcrumbs) lands on Records.
     */
    public function show(DnsZone $zone): RedirectResponse
    {
        return redirect()->route('zones.records', $zone);
    }

    public function providers(Request $request, DnsZone $zone): Response
    {
        $canManageAttachments = $request->user()->can('manageAttachments', $zone);

        $attachments = $zone->zoneProviders()
            ->with('provider')
            ->withCount([
                'syncStates as records_count',
                'syncStates as synced_count' => fn ($q) => $q->where('sync_status', SyncStatus::Synced),
                'syncStates as drifted_count' => fn ($q) => $q->where('sync_status', SyncStatus::Drifted),
                'syncStates as error_count' => fn ($q) => $q->where('sync_status', SyncStatus::Error),
            ])
            ->get()
            ->sortBy(fn (ZoneProvider $attachment) => $attachment->provider->name)
            ->map(fn (ZoneProvider $attachment) => [
                'id' => $attachment->id,
                'providerId' => $attachment->provider->id,
                'providerName' => $attachment->provider->name,
                'providerType' => $attachment->provider->type->value,
                'providerEnabled' => $attachment->provider->enabled,
                'enabled' => $attachment->enabled,
                'healthStatus' => $attachment->provider->health_status->value,
                'healthMessage' => $attachment->provider->health_message,
                'zoneConfig' => $this->publicZoneConfig($attachment),
                'supportsZones' => $this->supportsZones($attachment->provider),
                'recordsCount' => $attachment->records_count,
                'syncedCount' => $attachment->synced_count,
                'driftedCount' => $attachment->drifted_count,
                'errorCount' => $attachment->error_count,
            ])
            ->values();

        $attachedProviderIds = $zone->zoneProviders()->pluck('provider_id');

        return Inertia::render('zones/providers', [
            'zone' => ['id' => $zone->id, 'name' => $zone->name, 'description' => $zone->description],
            'attachments' => $attachments,
            'zoneCan' => ZonePermissions::for($request->user(), $zone),
            // Detached zoneless providers stay listed here — attaching one
            // again is the "opt back in" path. Attach-dialog fodder only:
            // users without attachment rights must not see provider names.
            'availableProviders' => $canManageAttachments
                ? Provider::query()
                    ->where('enabled', true)
                    ->whereNotIn('id', $attachedProviderIds)
                    ->orderBy('name')
                    ->get()
                    ->map(fn (Provider $provider) => [
                        'id' => $provider->id,
                        'name' => $provider->name,
                        'type' => $provider->type->value,
                        'enabled' => $provider->enabled,
                    ])
                : [],
            'connectors' => $canManageAttachments ? $this->registry->descriptors() : [],
        ]);
    }

    public function records(Request $request, DnsZone $zone): Response
    {
        ['entries' => $entries, 'filters' => $filters] = EntryQuery::build($request, $zone);

        return Inertia::render('zones/records', [
            'zone' => ['id' => $zone->id, 'name' => $zone->name, 'description' => $zone->description],
            'stats' => $this->zoneStats($zone),
            'entries' => $entries->through(fn (DnsEntry $entry) => EntryPresenter::entry($entry)),
            'filters' => $filters,
            'zones' => [['id' => $zone->id, 'name' => $zone->name]],
            'zoneAttachments' => EntryPresenter::zoneAttachments([$zone->id]),
            'connectors' => $this->registry->descriptors(),
            'zoneCan' => ZonePermissions::for($request->user(), $zone),
        ]);
    }

    public function activity(Request $request, DnsZone $zone): Response
    {
        $filters = ActivityQuery::validateFilters($request);

        // Pre-scope to the zone: activities on the zone itself plus entry
        // activities stamped with its id. The other filters still apply.
        $filters['zone_id'] = $zone->id;

        return Inertia::render('zones/activity', [
            'zone' => ['id' => $zone->id, 'name' => $zone->name, 'description' => $zone->description],
            'activities' => ActivityQuery::activities($filters),
            'filters' => $filters,
            // Causer options limited to this zone's actors — zone viewers
            // must not be able to enumerate the user table.
            'users' => ActivityQuery::causersForZone($zone->id),
            'events' => ActivityQuery::events(),
            'zoneCan' => ZonePermissions::for($request->user(), $zone),
        ]);
    }

    /**
     * JSON for the zone-scoped ActivityLogDialog — same contract as
     * ActivityLogController::data, force-scoped to this zone.
     */
    public function activityData(Request $request, DnsZone $zone): JsonResponse
    {
        $filters = ActivityQuery::validateFilters($request);

        $filters['zone_id'] = $zone->id;

        return response()->json(ActivityQuery::activities($filters));
    }

    public function store(ZoneRequest $request, ZoneAttachmentService $attachments): RedirectResponse
    {
        $zone = DnsZone::create($request->validated());

        $attachments->attachZonelessProviders($zone);

        return back()->with('success', "Zone {$zone->name} added.");
    }

    public function update(ZoneRequest $request, DnsZone $zone): RedirectResponse
    {
        $zone->update($request->validated());

        return back()->with('success', "Zone {$zone->name} updated.");
    }

    public function destroy(DnsZone $zone): RedirectResponse
    {
        $zone->delete();

        // The zone's pages no longer exist — always land on the list.
        return to_route('zones.index')->with('success', "Zone {$zone->name} removed. DNS records at your providers were NOT deleted.");
    }

    public function syncAll(DnsZone $zone, SyncService $sync): RedirectResponse
    {
        $entries = $zone->entries()->get();

        foreach ($entries as $entry) {
            $sync->syncEntry($entry);
        }

        $count = $entries->count();

        return back()->with('success', "Queued sync for {$count} record".($count === 1 ? '' : 's')." in {$zone->name}.");
    }

    /**
     * The stat-tile row on the zone Records tab: entry count plus the
     * fully-in-sync / drifted / errored rollups across all sync states.
     */
    private function zoneStats(DnsZone $zone): array
    {
        $entries = $zone->entries()
            ->withCount([
                'syncStates',
                'syncStates as synced_states_count' => fn ($q) => $q->where('sync_status', SyncStatus::Synced),
                'syncStates as drifted_states_count' => fn ($q) => $q->where('sync_status', SyncStatus::Drifted),
                'syncStates as error_states_count' => fn ($q) => $q->where('sync_status', SyncStatus::Error),
            ])
            ->get();

        return [
            'entriesCount' => $entries->count(),
            'inSync' => self::inSyncCount($entries),
            'drifted' => $entries->filter(fn ($e) => $e->drifted_states_count > 0)->count(),
            'errored' => $entries->filter(fn ($e) => $e->error_states_count > 0)->count(),
        ];
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

    /**
     * The attachment's per-zone settings limited to its connector's
     * zoneConfigSchema keys, secrets blanked — never expose credentials.
     */
    private function publicZoneConfig(ZoneProvider $attachment): array
    {
        $schema = collect($this->registry->classFor($attachment->provider->type->value)::zoneConfigSchema());

        $secrets = $schema->where('secret', true)->pluck('key')->all();

        return collect($attachment->config ?? [])
            ->only($schema->pluck('key')->all())
            ->map(fn ($value, $key) => in_array($key, $secrets, true) ? '' : $value)
            ->all();
    }

    private function supportsZones(Provider $provider): bool
    {
        return $this->registry->classFor($provider->type->value)::capabilities()->supportsZones;
    }
}
