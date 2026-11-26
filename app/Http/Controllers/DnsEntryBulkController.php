<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Enums\ZoneRole;
use App\Models\DnsEntry;
use App\Enums\RecordType;
use App\Enums\SyncStatus;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use App\Models\ZoneProvider;
use Illuminate\Http\Request;
use App\Services\SyncService;
use App\Support\DnsEntryRules;
use Illuminate\Validation\Rule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\Collection;

class DnsEntryBulkController extends Controller
{
    public function __construct(private SyncService $sync)
    {
    }

    /**
     * Re-queue a push for each selected entry to its assigned providers.
     */
    public function sync(Request $request): RedirectResponse
    {
        $entries = $this->selectedEntries($request);

        $entries->each(fn (DnsEntry $entry) => $this->sync->syncEntry($entry));

        return back()->with('success', sprintf(
            'Re-syncing %d %s to their assigned providers.',
            $entries->count(),
            Str::plural('entry', $entries->count()),
        ));
    }

    /**
     * Change the provider targeting of each selected entry. Three modes:
     * `replace` (default) makes the given zone attachments the entire
     * assignment — deselected attachments get remote deletes; `attach` adds
     * them to each entry's assignment, leaving the rest untouched; `detach`
     * removes exactly them, leaving the rest untouched. Ids belonging to a
     * different zone are silently dropped per entry (mirroring the
     * missing-ids policy); attachments that do not manage an entry's record
     * type are skipped by the sync engine; paused attachments always keep
     * their records. `keep_remote` (replace/detach) releases the removed
     * attachments instead of deleting their records: the app stops
     * managing them and the records stay at the provider.
     */
    public function providers(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'mode' => ['sometimes', Rule::in(['replace', 'attach', 'detach'])],
            // Attaching or detaching nothing is a no-op the UI should never
            // send; only a replace may be empty (= make local-only).
            'zone_providers' => ['present', 'array', ...in_array($request->input('mode'), ['attach', 'detach'], true) ? ['min:1'] : []],
            'zone_providers.*' => ['integer', 'exists:zone_providers,id'],
            'keep_remote' => ['sometimes', 'boolean'],
        ]);

        $mode = $validated['mode'] ?? 'replace';
        $zoneProviderIds = $validated['zone_providers'];
        $keepRemote = $request->boolean('keep_remote');

        $entries = $this->selectedEntries($request);

        $attachmentsByZone = ZoneProvider::query()
            ->whereIn('dns_zone_id', $entries->pluck('dns_zone_id')->unique())
            ->get(['id', 'dns_zone_id'])
            ->groupBy('dns_zone_id')
            ->map(fn ($group) => $group->pluck('id')->all());

        $keptCount = 0;

        $entries->each(function (DnsEntry $entry) use ($mode, $zoneProviderIds, $attachmentsByZone, $keepRemote, &$keptCount) {
            $ownIds = array_values(array_intersect($zoneProviderIds, $attachmentsByZone->get($entry->dns_zone_id, [])));

            $kept = [];

            match ($mode) {
                'attach' => $this->sync->attachEntry($entry, $ownIds),
                'detach' => $kept = $this->sync->detachEntry($entry, $ownIds, $keepRemote),
                default => $kept = $this->sync->syncEntry($entry, $ownIds, $keepRemote),
            };

            $keptCount += count($kept);

            $assigned = $entry->syncStates()
                ->where('sync_status', '!=', SyncStatus::Deleting)
                ->with('zoneProvider.provider:id,name')
                ->get()
                ->map(fn ($state) => $state->zoneProvider?->provider?->name)
                ->filter()
                ->values()
                ->all();

            activity('entries')
                ->performedOn($entry)
                ->event('providers-changed')
                ->withProperties(['providers' => $assigned] + ($kept !== [] ? ['kept_at_providers' => $kept] : []))
                ->log('providers-changed');
        });

        $count = $entries->count();
        $noun = Str::plural('entry', $count);

        $message = match (true) {
            $mode === 'attach' => "Attaching {$count} {$noun} to the selected providers — records are being pushed.",
            $mode === 'detach' && $keepRemote => "Detached {$count} {$noun} from the selected providers without deleting — their records were kept at the provider and are no longer managed by the app.",
            $mode === 'detach' => "Detaching {$count} {$noun} from the selected providers — their records are being deleted at the provider.",
            $keepRemote => "Retargeting {$count} {$noun} — records sync to the selected providers; records at deselected providers were kept there and are no longer managed by the app.",
            default => "Retargeting {$count} {$noun} — records sync to the selected providers and are deleted from deselected ones.",
        };

        if ($keepRemote && $mode !== 'attach') {
            $message .= sprintf(' (%d %s kept.)', $keptCount, Str::plural('record', $keptCount));
        }

        return back()->with('success', $message);
    }

    /**
     * Apply the given field changes to every selected entry. Each entry is
     * re-validated with the change merged in; entries that would become
     * invalid or duplicate another entry are skipped, never half-applied.
     * Turning the proxy on skips entries whose (resulting) type cannot be
     * proxied; a type change to such a type clears the proxy flag.
     */
    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'set' => ['required', 'array:type,content,ttl,proxied,comment'],
            'set.type' => ['sometimes', Rule::enum(RecordType::class)],
            'set.content' => ['sometimes', 'string', 'max:2048'],
            'set.ttl' => ['sometimes', 'nullable', 'integer', 'min:60', 'max:86400'],
            'set.proxied' => ['sometimes', 'boolean'],
            'set.comment' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $set = $request->input('set', []);

        if (array_key_exists('proxied', $set)) {
            $set['proxied'] = filter_var($set['proxied'], FILTER_VALIDATE_BOOLEAN);
        }

        if ($set === []) {
            return back()->withErrors(['set' => 'Pick at least one field to change.']);
        }

        $entries = $this->selectedEntries($request);

        $updated = 0;
        $invalid = 0;
        $duplicates = 0;
        $unproxiable = 0;

        foreach ($entries as $entry) {
            $payload = array_merge([
                'name' => $entry->name,
                'type' => $entry->type->value,
                'content' => $entry->content,
                'ttl' => $entry->ttl,
                'priority' => $entry->priority,
                'proxied' => (bool) $entry->proxied,
                'comment' => $entry->comment,
            ], $set);

            $type = RecordType::tryFrom((string) $payload['type']);

            $validator = Validator::make($payload, DnsEntryRules::rules($type, $entry->zone));

            if ($validator->fails()) {
                $invalid++;

                continue;
            }

            $data = $validator->validated();

            if (($set['proxied'] ?? false) === true && ! $type->isProxiable()) {
                $unproxiable++;

                continue;
            }

            // A type change can leave fields behind that no longer apply.
            if (! $type->hasPriority()) {
                $data['priority'] = null;
            }

            if (! $type->isProxiable()) {
                $data['proxied'] = false;
            }

            $collision = DnsEntry::query()
                ->whereKeyNot($entry->id)
                ->where('dns_zone_id', $entry->dns_zone_id)
                ->where('name', $data['name'])
                ->where('type', $data['type'])
                ->where('content', $data['content'])
                ->exists();

            if ($collision) {
                $duplicates++;

                continue;
            }

            $entry->update(Arr::only($data, ['type', 'content', 'ttl', 'priority', 'proxied', 'comment']));

            // Re-push to the entry's assignment; a type change drops providers
            // that do not manage the new type (their records are deleted).
            $this->sync->syncEntry($entry);

            $updated++;
        }

        $message = sprintf('Updated %d %s — syncing changes to providers.', $updated, Str::plural('entry', $updated));

        $skippedParts = array_filter([
            $invalid > 0 ? "{$invalid} skipped as invalid after the change" : null,
            $duplicates > 0 ? "{$duplicates} skipped to avoid duplicates" : null,
            $unproxiable > 0 ? "{$unproxiable} skipped — only A, AAAA, and CNAME records can be proxied" : null,
        ]);

        if ($skippedParts !== []) {
            $message .= ' (' . implode(', ', $skippedParts) . '.)';
        }

        return back()->with('success', $message);
    }

    /**
     * Delete each selected entry from all of its providers.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $entries = $this->selectedEntries($request);

        $entries->each(function (DnsEntry $entry) {
            $this->sync->deleteEntry($entry);

            // Attribute deferred deletions (see DnsEntryController@destroy);
            // inline deletes are already logged by the model trait.
            if ($entry->exists) {
                activity('entries')->performedOn($entry)->event('delete-requested')->log('delete-requested');
            }
        });

        return back()->with('success', sprintf(
            'Deleting %d %s — records are being removed from their providers.',
            $entries->count(),
            Str::plural('entry', $entries->count()),
        ));
    }

    /**
     * Ids that no longer exist (deleted by another user or a finished remote
     * delete) are silently dropped rather than failing the whole action —
     * and so are ids in zones where the user cannot manage records (null
     * from accessibleZoneIds means unrestricted).
     *
     * @return Collection<int, DnsEntry>
     */
    private function selectedEntries(Request $request): Collection
    {
        $ids = $request->validate([
            'ids' => ['required', 'array', 'max:1000'],
            'ids.*' => ['integer'],
        ])['ids'];

        $user = $request->user();

        // Super Viewer is read-only by construction — accessibleZoneIds()
        // treats them as unrestricted, so pin everyone but Super Admin to
        // the zones where they hold an actual record-managing grant.
        $manageableZoneIds = $user->isSuperAdmin()
            ? null
            : $user->zoneRolesMap()
                ->filter(fn (array $held) => array_intersect($held, [ZoneRole::ZoneAdmin->value, ZoneRole::ZoneDnsManager->value]) !== [])
                ->keys()
                ->all();

        return DnsEntry::query()
            ->whereIn('id', $ids)
            ->when($manageableZoneIds !== null, fn ($q) => $q->whereIn('dns_zone_id', $manageableZoneIds))
            ->get();
    }
}
