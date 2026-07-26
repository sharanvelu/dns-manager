<?php

namespace App\Http\Controllers;

use App\Enums\RecordType;
use App\Enums\SyncStatus;
use App\Models\DnsEntry;
use App\Models\DnsZone;
use App\Models\SyncLog;
use App\Models\ZoneProvider;
use App\Support\DnsEntryRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class ProviderImportController extends Controller
{
    /**
     * Live listing of a zone attachment's remote records, annotated with how
     * each relates to the local database, for the import-selection dialog.
     * Remote names are relativized to the zone; records outside it are
     * excluded and counted.
     */
    public function records(DnsZone $zone, ZoneProvider $zoneProvider): JsonResponse
    {
        try {
            $remote = $zoneProvider->connector()->listRecords();
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $managedTypes = $zoneProvider->provider->managed_record_types ?? [];

        [$records, $unmanaged] = $remote->partition(
            fn ($record) => in_array($record->type, $managedTypes, true),
        );

        [$records, $outOfZone] = $records
            ->map(fn ($record) => ['record' => $record, 'name' => $zone->relativize($record->name)])
            ->partition(fn ($item) => $item['name'] !== null);

        $entries = DnsEntry::query()
            ->where('dns_zone_id', $zone->id)
            ->whereIn('name', $records->pluck('name')->unique())
            ->get()
            ->keyBy(fn (DnsEntry $entry) => $this->keyFor($entry->name, $entry->type->value, $entry->content));

        $linkedEntryIds = $zoneProvider->syncStates()->pluck('dns_entry_id')->flip();

        return response()->json([
            'records' => $records->map(function ($item) use ($entries, $linkedEntryIds) {
                $record = $item['record'];
                $entry = $entries->get($this->keyFor($item['name'], $record->type, $record->content));

                return [
                    'externalId' => $record->externalId,
                    'type' => $record->type,
                    'name' => $item['name'],
                    'content' => $record->content,
                    'ttl' => $record->ttl,
                    'priority' => $record->priority,
                    'proxied' => $record->proxied,
                    'status' => match (true) {
                        $entry && $linkedEntryIds->has($entry->id) => 'managed',
                        $entry !== null => 'exists',
                        default => 'new',
                    },
                ];
            })->values(),
            'unmanagedTypeCount' => $unmanaged->count(),
            'outOfZoneCount' => $outOfZone->count(),
        ]);
    }

    /**
     * Import the selected remote records into the zone: insert missing
     * entries, update matching ones, and link them to this attachment as
     * already-synced — never duplicating and never pushing to other
     * providers.
     */
    public function store(Request $request, DnsZone $zone, ZoneProvider $zoneProvider): RedirectResponse
    {
        $validated = $request->validate([
            'records' => ['required', 'array', 'min:1', 'max:1000'],
            'records.*.externalId' => ['required', 'string'],
            'records.*.type' => ['required', Rule::enum(RecordType::class)],
            'records.*.name' => ['required', 'string', 'max:253'],
            'records.*.content' => ['required', 'string', 'max:2048'],
            'records.*.ttl' => ['nullable', 'integer'],
            'records.*.priority' => ['nullable', 'integer'],
            'records.*.proxied' => ['boolean'],
        ]);

        $imported = 0;
        $updated = 0;
        $failed = 0;

        foreach ($validated['records'] as $record) {
            $name = strtolower(rtrim($record['name'], '.'));
            $record['name'] = $zone->relativize($name) ?? $name;

            $data = [
                'name' => $record['name'],
                'type' => $record['type'],
                'content' => $record['content'],
                'ttl' => $record['ttl'] ?? null,
                'priority' => $record['priority'] ?? null,
                'proxied' => (bool) ($record['proxied'] ?? false),
            ];

            if (Validator::make($data, DnsEntryRules::rules(RecordType::from($record['type']), $zone))->fails()) {
                $failed++;

                continue;
            }

            $entry = DnsEntry::query()
                ->where('dns_zone_id', $zone->id)
                ->where('name', $data['name'])
                ->where('type', $data['type'])
                ->where('content', $data['content'])
                ->first();

            if ($entry) {
                $entry->update(['ttl' => $data['ttl'], 'priority' => $data['priority'], 'proxied' => $data['proxied']]);
                $updated++;
            } else {
                $entry = DnsEntry::create([...$data, 'dns_zone_id' => $zone->id]);
                $imported++;
            }

            // Link to the source attachment only — the record already exists
            // there, so no push job is needed and no other provider gains it.
            $entry->syncStates()->updateOrCreate(
                ['zone_provider_id' => $zoneProvider->id],
                [
                    'external_id' => $record['externalId'],
                    'sync_status' => SyncStatus::Synced,
                    'last_synced_at' => now(),
                    'last_error' => null,
                ],
            );
        }

        $message = sprintf(
            'Imported %d new and updated %d existing entr%s from %s',
            $imported,
            $updated,
            $imported + $updated === 1 ? 'y' : 'ies',
            $zoneProvider->provider->name,
        );

        SyncLog::record(
            $zoneProvider->provider,
            null,
            'import',
            $failed > 0 ? 'error' : 'success',
            $message.($failed > 0 ? " ({$failed} skipped as invalid)" : '').'.',
            $zone->id,
        );

        return back()->with('success', $message.($failed > 0 ? " — {$failed} skipped as invalid" : '').'.');
    }

    private function keyFor(string $name, string $type, string $content): string
    {
        return strtolower(rtrim($name, '.')).'|'.$type.'|'.strtolower(rtrim($content, '.'));
    }
}
