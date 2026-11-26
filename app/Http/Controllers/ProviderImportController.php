<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use Throwable;
use App\Models\DnsZone;
use App\Models\SyncLog;
use App\Models\DnsEntry;
use App\Enums\RecordType;
use App\Enums\SyncStatus;
use App\Models\ZoneProvider;
use Illuminate\Http\Request;
use App\Services\SyncService;
use App\Support\DnsEntryRules;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use App\Connectors\DTOs\RemoteRecord;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProviderImportController extends Controller
{
    /**
     * Excluded records returned for display are capped so a zoneless provider
     * serving many zones cannot blow up the payload; the counts stay exact.
     */
    public const EXCLUDED_LIMIT = 500;

    public function __construct(private SyncService $sync)
    {
    }

    /**
     * Live listing of a zone attachment's remote records, annotated with how
     * each relates to the local database, for the import-selection dialog.
     * Remote names are relativized to the zone. Records outside the zone or
     * of a type this attachment does not manage are not importable: they are
     * returned separately (capped) with the reason, and counted.
     */
    public function records(DnsZone $zone, ZoneProvider $zoneProvider): JsonResponse
    {
        try {
            $remote = $zoneProvider->connector()->listRecords();
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $managedTypes = $zoneProvider->provider->managed_record_types ?? [];

        // Out-of-zone wins over type: a foreign record is noise for this zone
        // whatever its type.
        [$inZone, $outOfZone] = $remote
            ->map(fn ($record) => ['record' => $record, 'name' => $zone->relativize($record->name)])
            ->partition(fn ($item) => $item['name'] !== null);

        [$records, $unmanaged] = $inZone->partition(
            fn ($item) => in_array($item['record']->type, $managedTypes, true),
        );

        $entries = DnsEntry::query()
            ->where('dns_zone_id', $zone->id)
            ->whereIn('name', $records->pluck('name')->unique())
            ->get()
            ->keyBy(fn (DnsEntry $entry) => $this->keyFor($entry->name, $entry->type->value, $entry->content));

        $linkedEntryIds = $zoneProvider->syncStates()->pluck('dns_entry_id')->flip();

        $importable = $records->map(function ($item) use ($entries, $linkedEntryIds) {
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
                'entryId' => $entry?->id,
            ];
        })->values();

        $excluded = $unmanaged
            ->map(fn ($item) => $this->excludedRow($item['record'], $item['name'], 'unmanaged-type'))
            ->merge($outOfZone->map(fn ($item) => $this->excludedRow($item['record'], rtrim($item['record']->name, '.'), 'out-of-zone')))
            ->values();

        return response()->json([
            'records' => $importable,
            'counts' => [
                'new' => $importable->where('status', 'new')->count(),
                'exists' => $importable->where('status', 'exists')->count(),
                'managed' => $importable->where('status', 'managed')->count(),
            ],
            'excluded' => $excluded->take(self::EXCLUDED_LIMIT)->all(),
            'excludedTotal' => $excluded->count(),
            // Paused attachments can import but not untrack (paused invariant).
            'attachmentActive' => $zoneProvider->isActive(),
            'unmanagedTypeCount' => $unmanaged->count(),
            'outOfZoneCount' => $outOfZone->count(),
        ]);
    }

    /**
     * Import the selected remote records into the zone: insert missing
     * entries, update matching ones, and link them to this attachment as
     * already-synced — never duplicating and never pushing to other
     * providers. `untrack` lists entry ids to stop managing from this
     * attachment while keeping their records at the provider.
     */
    public function store(Request $request, DnsZone $zone, ZoneProvider $zoneProvider): RedirectResponse
    {
        $validated = $request->validate([
            'records' => ['required_without:untrack', 'array', 'max:1000'],
            'records.*.externalId' => ['required', 'string'],
            'records.*.type' => ['required', Rule::enum(RecordType::class)],
            'records.*.name' => ['required', 'string', 'max:253'],
            'records.*.content' => ['required', 'string', 'max:2048'],
            'records.*.ttl' => ['nullable', 'integer'],
            'records.*.priority' => ['nullable', 'integer'],
            'records.*.proxied' => ['boolean'],
            'untrack' => ['required_without:records', 'array', 'max:1000'],
            'untrack.*' => ['integer', 'distinct'],
        ]);

        $records = $validated['records'] ?? [];
        $untrackIds = $validated['untrack'] ?? [];

        if ($records === [] && $untrackIds === []) {
            throw ValidationException::withMessages(['records' => 'Select at least one record to import or stop tracking.']);
        }

        $provider = $zoneProvider->provider->name;

        if ($untrackIds !== [] && ! $zoneProvider->isActive()) {
            throw ValidationException::withMessages([
                'untrack' => "{$provider} is paused for this zone — enable it before you stop tracking records from it.",
            ]);
        }

        $imported = 0;
        $updated = 0;
        $failed = 0;

        foreach ($records as $record) {
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

        $untracked = $this->untrack($zone, $zoneProvider, $untrackIds);
        $skipped = count($untrackIds) - $untracked;

        $parts = [];

        if ($records !== []) {
            $parts[] = sprintf(
                'Imported %d new and updated %d existing entr%s from %s',
                $imported,
                $updated,
                $imported + $updated === 1 ? 'y' : 'ies',
                $provider,
            );
        }

        if ($untrackIds !== []) {
            $parts[] = sprintf(
                '%s %d entr%s from %s — the record%s stay at the provider',
                $records === [] ? 'Stopped tracking' : 'stopped tracking',
                $untracked,
                $untracked === 1 ? 'y' : 'ies',
                $provider,
                $untracked === 1 ? '' : 's',
            );
        }

        $notes = array_values(array_filter([
            $failed > 0 ? "{$failed} skipped as invalid" : null,
            $skipped > 0 ? "{$skipped} no longer tracked here" : null,
        ]));

        $message = implode('; ', $parts);

        SyncLog::record(
            $zoneProvider->provider,
            null,
            'import',
            $failed > 0 ? 'error' : 'success',
            $message . ($notes !== [] ? ' (' . implode(', ', $notes) . ')' : '') . '.',
            $zone->id,
        );

        return back()->with('success', $message . ($notes !== [] ? ' — ' . implode(', ', $notes) : '') . '.');
    }

    /**
     * Release the given entries from this attachment through the shared
     * keep-remote path: the sync state goes, the record stays at the
     * provider, and the entry stays in the app (local-only if this was its
     * last provider). Ids outside the zone, not linked to this attachment,
     * or already being deleted there are dropped.
     *
     * @param  list<int>  $entryIds
     */
    private function untrack(DnsZone $zone, ZoneProvider $zoneProvider, array $entryIds): int
    {
        if ($entryIds === []) {
            return 0;
        }

        $entries = DnsEntry::query()
            ->where('dns_zone_id', $zone->id)
            ->whereIn('id', $entryIds)
            ->whereHas('syncStates', fn ($query) => $query
                ->where('zone_provider_id', $zoneProvider->id)
                ->where('sync_status', '!=', SyncStatus::Deleting))
            ->get();

        foreach ($entries as $entry) {
            $kept = $this->sync->detachEntry($entry, [$zoneProvider->id], keepRemote: true);

            activity('entries')
                ->performedOn($entry)
                ->event('providers-changed')
                ->withProperties([
                    'providers' => $entry->syncStates()
                        ->where('sync_status', '!=', SyncStatus::Deleting)
                        ->with('zoneProvider.provider:id,name')
                        ->get()
                        ->pluck('zoneProvider.provider.name')
                        ->filter()
                        ->values()
                        ->all(),
                ] + ($kept !== [] ? ['kept_at_providers' => $kept] : []))
                ->log('providers-changed');
        }

        return $entries->count();
    }

    /**
     * @return array{type: string, name: string, content: string, ttl: int|null, reason: string}
     */
    private function excludedRow(RemoteRecord $record, string $name, string $reason): array
    {
        return [
            'type' => $record->type,
            'name' => $name,
            'content' => mb_strimwidth($record->content, 0, 255, '…'),
            'ttl' => $record->ttl,
            'reason' => $reason,
        ];
    }

    private function keyFor(string $name, string $type, string $content): string
    {
        return strtolower(rtrim($name, '.')) . '|' . $type . '|' . strtolower(rtrim($content, '.'));
    }
}
