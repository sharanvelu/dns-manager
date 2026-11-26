<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use Throwable;
use App\Models\DnsZone;
use App\Models\Provider;
use Illuminate\Support\Str;
use App\Models\ZoneProvider;
use Illuminate\Http\Request;
use App\Jobs\DetachZoneProvider;
use Illuminate\Http\JsonResponse;
use App\Connectors\ConnectorRegistry;
use Illuminate\Http\RedirectResponse;
use App\Http\Requests\ZoneProviderRequest;

class ZoneProviderController extends Controller
{
    public function __construct(private ConnectorRegistry $registry)
    {
    }

    public function store(ZoneProviderRequest $request, DnsZone $zone): RedirectResponse
    {
        $provider = Provider::findOrFail($request->validated('provider_id'));
        $class = $this->registry->classFor($provider->type->value);

        $config = collect((array) $request->input('config', []))
            ->reject(fn ($value) => $value === null || $value === '')
            ->all();

        $required = collect($class::zoneConfigSchema())->where('required', true);
        $missing = fn (array $config) => $required->reject(fn ($field) => array_key_exists($field->key, $config));

        // Zone discovery fills in the blanks (e.g. Cloudflare zone_id looked
        // up by zone name); explicit user values always win.
        if ($missing($config)->isNotEmpty() && $class::capabilities()->supportsZones) {
            try {
                $discovered = $provider->connector()->discoverZoneConfig($zone->name) ?? [];
            } catch (Throwable) {
                $discovered = [];
            }

            $config = array_merge($discovered, $config);
        }

        if (($stillMissing = $missing($config))->isNotEmpty()) {
            $labels = $stillMissing->pluck('label')->implode(', ');

            return back()->with('error', "Could not discover {$labels} for {$zone->name} at {$provider->name} — fill it in manually.");
        }

        $zoneProvider = $zone->zoneProviders()->create([
            'provider_id' => $provider->id,
            'config' => $config === [] ? null : $config,
            'enabled' => $request->boolean('enabled', true),
        ]);

        // Never log config values — they may contain credentials.
        activity('zones')
            ->performedOn($zone)
            ->event('provider-attached')
            ->withProperties(['provider' => $provider->name])
            ->log("attached provider {$provider->name}");

        return back()->with('success', "{$provider->name} attached to {$zone->name}.");
    }

    public function update(ZoneProviderRequest $request, DnsZone $zone, ZoneProvider $zoneProvider): RedirectResponse
    {
        $existing = $zoneProvider->config ?? [];
        $config = $existing;

        if ($request->has('config')) {
            $config = (array) $request->input('config', []);

            // Blank secret fields mean "keep the stored value".
            $class = $this->registry->classFor($zoneProvider->provider->type->value);
            $schema = collect($class::zoneConfigSchema());

            foreach ($schema->where('secret', true) as $field) {
                if (($config[$field->key] ?? '') === '') {
                    $config[$field->key] = $existing[$field->key] ?? null;
                }
            }
        }

        $configChanged = $config != $existing;
        $enabledChanged = $request->has('enabled') && $request->boolean('enabled') !== $zoneProvider->enabled;

        $zoneProvider->update([
            'config' => $config === [] ? null : $config,
            'enabled' => $request->boolean('enabled', $zoneProvider->enabled),
        ]);

        if ($configChanged || $enabledChanged) {
            activity('zones')
                ->performedOn($zone)
                ->event('attachment-updated')
                ->withProperties([
                    'provider' => $zoneProvider->provider->name,
                    'config_changed' => $configChanged,
                ])
                ->log("updated {$zoneProvider->provider->name} attachment");
        }

        return back()->with('success', "{$zoneProvider->provider->name} attachment updated.");
    }

    /**
     * Detach a provider from the zone. By default the app just stops
     * managing the zone's records there — nothing is deleted remotely.
     * With `delete_records`, every pushed record is deleted at the provider
     * first: a queued chain of DeleteEntryFromProvider jobs (one per state,
     * which need the state + attachment to still exist) ending in
     * DetachZoneProvider, which removes the attachment only once all
     * deletes succeeded. Paused attachments refuse — deletes are never
     * queued against a disabled provider.
     */
    public function destroy(Request $request, DnsZone $zone, ZoneProvider $zoneProvider): RedirectResponse
    {
        $request->validate(['delete_records' => ['sometimes', 'boolean']]);

        $providerName = $zoneProvider->provider->name;
        $optOut = ! $zoneProvider->connector()::capabilities()->supportsZones;
        $done = $optOut ? "{$zone->name} opted out of {$providerName}" : "{$providerName} detached from {$zone->name}";

        if (! $request->boolean('delete_records')) {
            $zoneProvider->delete();

            $this->logDetached($zone, $providerName, false);

            return back()->with('success', "{$done}. Records at the provider were NOT deleted.");
        }

        if (! $zoneProvider->isActive()) {
            return back()->with('error', "{$providerName} is disabled for {$zone->name}, so its records cannot be deleted. Enable it first, or detach without deleting records.");
        }

        $stateIds = $zoneProvider->syncStates()->whereNotNull('external_id')->pluck('id');

        if ($stateIds->isEmpty()) {
            $zoneProvider->delete();

            $this->logDetached($zone, $providerName, true);

            return back()->with('success', "{$done}. It held no records to delete.");
        }

        DetachZoneProvider::chainFor($zoneProvider, $stateIds->all());

        $count = $stateIds->count();
        $records = $count . ' ' . Str::plural('record', $count);

        activity('zones')
            ->performedOn($zone)
            ->event('provider-detach-requested')
            ->withProperties(['provider' => $providerName, 'records' => $count])
            ->log("requested detaching provider {$providerName} and deleting its records");

        return back()->with('success', sprintf(
            'Deleting %s at %s — %s once they are gone. If a delete fails, %s stays attached so you can retry.',
            $records,
            $providerName,
            $optOut ? "{$zone->name} is then opted out" : "it is then detached from {$zone->name}",
            $providerName,
        ));
    }

    public function test(DnsZone $zone, ZoneProvider $zoneProvider): JsonResponse
    {
        try {
            $result = $zoneProvider->connector()->testZone();
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage(), 'details' => []]);
        }

        return response()->json($result->toArray());
    }

    public function discover(Request $request, DnsZone $zone): JsonResponse
    {
        $request->validate([
            'provider_id' => ['required', 'integer', 'exists:providers,id'],
        ]);

        $provider = Provider::findOrFail($request->input('provider_id'));

        try {
            $config = $provider->connector()->discoverZoneConfig($zone->name);
        } catch (Throwable $e) {
            return response()->json(['found' => false, 'error' => $e->getMessage()]);
        }

        return response()->json(['found' => $config !== null, 'config' => $config]);
    }

    private function logDetached(DnsZone $zone, string $providerName, bool $recordsDeleted): void
    {
        activity('zones')
            ->performedOn($zone)
            ->event('provider-detached')
            ->withProperties(['provider' => $providerName, 'records_deleted' => $recordsDeleted])
            ->log("detached provider {$providerName}");
    }
}
