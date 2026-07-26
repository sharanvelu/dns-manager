<?php

namespace App\Http\Controllers;

use App\Connectors\ConnectorRegistry;
use App\Http\Requests\ZoneProviderRequest;
use App\Models\DnsZone;
use App\Models\Provider;
use App\Models\ZoneProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class ZoneProviderController extends Controller
{
    public function __construct(private ConnectorRegistry $registry) {}

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

    public function destroy(DnsZone $zone, ZoneProvider $zoneProvider): RedirectResponse
    {
        $providerName = $zoneProvider->provider->name;

        $zoneProvider->delete();

        activity('zones')
            ->performedOn($zone)
            ->event('provider-detached')
            ->withProperties(['provider' => $providerName])
            ->log("detached provider {$providerName}");

        return back()->with('success', "{$providerName} detached from {$zone->name}. Records at the provider were NOT deleted.");
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
}
