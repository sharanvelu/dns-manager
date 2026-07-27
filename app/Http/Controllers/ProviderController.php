<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use Throwable;
use Inertia\Inertia;
use Inertia\Response;
use App\Models\DnsZone;
use App\Models\Provider;
use App\Enums\SyncStatus;
use App\Enums\HealthStatus;
use Illuminate\Http\Request;
use App\Jobs\CheckProviderDrift;
use App\Jobs\CheckProviderHealth;
use Illuminate\Http\JsonResponse;
use App\Connectors\ConnectorRegistry;
use Illuminate\Http\RedirectResponse;
use App\Http\Requests\ProviderRequest;
use App\Services\ZoneAttachmentService;

class ProviderController extends Controller
{
    public function __construct(private ConnectorRegistry $registry)
    {
    }

    public function index(): Response
    {
        $providers = Provider::query()
            ->withCount([
                // Sync states across ALL of the provider's zone attachments.
                'syncStates as records_count',
                'syncStates as synced_count' => fn ($q) => $q->where('sync_status', SyncStatus::Synced),
            ])
            ->with(['zoneProviders.zone:id,name'])
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
                'managedRecordTypes' => $provider->managed_record_types ?? [],
                'recordsCount' => $provider->records_count,
                'syncedCount' => $provider->synced_count,
                'zones' => $provider->zoneProviders
                    ->sortBy(fn ($attachment) => $attachment->zone?->name)
                    ->map(fn ($attachment) => [
                        'zoneProviderId' => $attachment->id,
                        'zoneId' => $attachment->dns_zone_id,
                        'zoneName' => $attachment->zone?->name,
                        'enabled' => $attachment->enabled,
                    ])
                    ->values(),
                // Config keys minus secrets, so the edit form can prefill.
                'config' => $this->publicConfig($provider),
            ]);

        return Inertia::render('providers/index', [
            'providers' => $providers,
            'connectors' => $this->registry->descriptors(),
            // Every zone (id + name) so the UI can compute unattached zones
            // for the attach dialog and opted-out zones for zoneless providers.
            'allZones' => DnsZone::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (DnsZone $zone) => ['id' => $zone->id, 'name' => $zone->name]),
        ]);
    }

    public function store(ProviderRequest $request, ZoneAttachmentService $attachments): RedirectResponse
    {
        $data = $request->validated();

        $provider = Provider::create([
            ...$data,
            'health_status' => HealthStatus::Unchecked,
        ]);

        $attachments->attachToAllZones($provider);

        // A fresh provider has no synced records to drift-check — verify
        // connectivity instead.
        CheckProviderHealth::dispatch($provider->id);

        return back()->with('success', "Provider {$provider->name} added.");
    }

    public function update(ProviderRequest $request, Provider $provider): RedirectResponse
    {
        $data = $request->validated();

        // Blank secret fields mean "keep the stored value".
        $schema = collect($provider->connector()::configSchema());
        $config = $data['config'];
        $existing = $provider->config ?? [];

        foreach ($schema->where('secret', true) as $field) {
            if (($config[$field->key] ?? '') === '') {
                $config[$field->key] = $existing[$field->key] ?? null;
            }
        }

        $configChanged = $config != $existing;

        $provider->update([...$data, 'config' => $config]);

        if ($configChanged) {
            // The model trait never logs `config` (secrets) — record only
            // the fact that connection settings changed, never any values.
            activity('providers')
                ->performedOn($provider)
                ->event('updated')
                ->withProperties(['config_changed' => true])
                ->log('updated connection settings');
        }

        CheckProviderDrift::dispatch($provider->id);

        return back()->with('success', "Provider {$provider->name} updated.");
    }

    public function destroy(Provider $provider): RedirectResponse
    {
        $provider->delete();

        return back()->with('success', "Provider {$provider->name} removed. Remote records were left untouched.");
    }

    /**
     * Test a connection using submitted (unsaved) config. For updates,
     * blank secrets fall back to the stored values.
     */
    public function test(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['required', 'string'],
            'config' => ['required', 'array'],
            'provider_id' => ['nullable', 'integer', 'exists:providers,id'],
        ]);

        $config = $request->input('config');

        if ($request->input('provider_id')) {
            $existing = Provider::find($request->input('provider_id'));

            foreach ($existing->config ?? [] as $key => $value) {
                if (($config[$key] ?? '') === '') {
                    $config[$key] = $value;
                }
            }
        }

        $candidate = new Provider([
            'name' => 'test',
            'type' => $request->input('type'),
            'config' => $config,
        ]);

        try {
            $result = $this->registry->for($candidate)->testConnection();
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage(), 'details' => []]);
        }

        return response()->json($result->toArray());
    }

    public function check(Provider $provider): RedirectResponse
    {
        if (! $provider->enabled) {
            return back()->with('error', "{$provider->name} is disabled — enable it to run drift checks.");
        }

        CheckProviderDrift::dispatch($provider->id);

        return back()->with('success', "Drift check queued for {$provider->name}.");
    }

    private function publicConfig(Provider $provider): array
    {
        try {
            $schema = collect($provider->connector()::configSchema());
        } catch (Throwable) {
            return [];
        }

        $secrets = $schema->where('secret', true)->pluck('key')->all();

        return collect($provider->config ?? [])
            ->map(fn ($value, $key) => in_array($key, $secrets, true) ? '' : $value)
            ->all();
    }
}
