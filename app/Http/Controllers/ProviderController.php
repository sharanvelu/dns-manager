<?php

namespace App\Http\Controllers;

use App\Connectors\ConnectorRegistry;
use App\Enums\HealthStatus;
use App\Enums\SyncStatus;
use App\Http\Requests\ProviderRequest;
use App\Jobs\CheckProviderDrift;
use App\Models\Provider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ProviderController extends Controller
{
    public function __construct(private ConnectorRegistry $registry) {}

    public function index(): Response
    {
        $providers = Provider::query()
            ->withCount([
                'syncStates as records_count',
                'syncStates as synced_count' => fn ($q) => $q->where('sync_status', SyncStatus::Synced),
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
                'managedRecordTypes' => $provider->managed_record_types ?? [],
                'recordsCount' => $provider->records_count,
                'syncedCount' => $provider->synced_count,
                // Config keys minus secrets, so the edit form can prefill.
                'config' => $this->publicConfig($provider),
            ]);

        return Inertia::render('providers/index', [
            'providers' => $providers,
            'connectors' => $this->registry->descriptors(),
        ]);
    }

    public function store(ProviderRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $provider = Provider::create([
            ...$data,
            'health_status' => HealthStatus::Unchecked,
        ]);

        CheckProviderDrift::dispatch($provider->id);

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

        $provider->update([...$data, 'config' => $config]);

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
