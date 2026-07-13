<?php

namespace App\Http\Controllers;

use App\Enums\SyncStatus;
use App\Models\DnsEntry;
use App\Models\Provider;
use App\Models\SyncLog;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $totalEntries = DnsEntry::count();

        $entriesWithStates = DnsEntry::query()
            ->withCount([
                'syncStates',
                'syncStates as synced_states_count' => fn ($q) => $q->where('sync_status', SyncStatus::Synced),
                'syncStates as drifted_states_count' => fn ($q) => $q->where('sync_status', SyncStatus::Drifted),
                'syncStates as error_states_count' => fn ($q) => $q->where('sync_status', SyncStatus::Error),
            ])
            ->get();

        $inSync = $entriesWithStates
            ->filter(fn ($e) => $e->sync_states_count > 0 && $e->synced_states_count === $e->sync_states_count)
            ->count();

        $drifted = $entriesWithStates->filter(fn ($e) => $e->drifted_states_count > 0)->count();
        $errored = $entriesWithStates->filter(fn ($e) => $e->error_states_count > 0)->count();

        $providers = Provider::query()
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
            ]);

        $activity = SyncLog::query()
            ->with(['provider:id,name,type', 'entry:id,name,type'])
            ->latest()
            ->limit(15)
            ->get()
            ->map(fn (SyncLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'status' => $log->status,
                'message' => $log->message,
                'provider' => $log->provider?->only(['id', 'name']),
                'entry' => $log->entry?->only(['id', 'name']),
                'createdAt' => $log->created_at->toIso8601String(),
            ]);

        return Inertia::render('dashboard', [
            'stats' => [
                'totalEntries' => $totalEntries,
                'inSync' => $inSync,
                'drifted' => $drifted,
                'errored' => $errored,
                'providersTotal' => $providers->count(),
                'providersHealthy' => $providers->where('healthStatus', 'ok')->count(),
            ],
            'providers' => $providers,
            'activity' => $activity,
        ]);
    }
}
