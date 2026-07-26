<?php

namespace App\Http\Controllers;

use App\Enums\RecordType;
use App\Enums\SyncStatus;
use App\Enums\ZoneRole;
use App\Models\DnsEntry;
use App\Models\ZoneProvider;
use App\Services\SyncService;
use App\Support\DnsEntryRules;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DnsEntryBulkController extends Controller
{
    public function __construct(private SyncService $sync) {}

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
     * their records.
     */
    public function providers(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'mode' => ['sometimes', Rule::in(['replace', 'attach', 'detach'])],
            // Attaching or detaching nothing is a no-op the UI should never
            // send; only a replace may be empty (= make local-only).
            'zone_providers' => ['present', 'array', ...in_array($request->input('mode'), ['attach', 'detach'], true) ? ['min:1'] : []],
            'zone_providers.*' => ['integer', 'exists:zone_providers,id'],
        ]);

        $mode = $validated['mode'] ?? 'replace';
        $zoneProviderIds = $validated['zone_providers'];

        $entries = $this->selectedEntries($request);

        $attachmentsByZone = ZoneProvider::query()
            ->whereIn('dns_zone_id', $entries->pluck('dns_zone_id')->unique())
            ->get(['id', 'dns_zone_id'])
            ->groupBy('dns_zone_id')
            ->map(fn ($group) => $group->pluck('id')->all());

        $entries->each(function (DnsEntry $entry) use ($mode, $zoneProviderIds, $attachmentsByZone) {
            $ownIds = array_values(array_intersect($zoneProviderIds, $attachmentsByZone->get($entry->dns_zone_id, [])));

            match ($mode) {
                'attach' => $this->sync->attachEntry($entry, $ownIds),
                'detach' => $this->sync->detachEntry($entry, $ownIds),
                default => $this->sync->syncEntry($entry, $ownIds),
            };

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
                ->withProperties(['providers' => $assigned])
                ->log('providers-changed');
        });

        return back()->with('success', sprintf(
            match ($mode) {
                'attach' => 'Attaching %d %s to the selected providers — records are being pushed.',
                'detach' => 'Detaching %d %s from the selected providers — their records are being removed.',
                default => 'Retargeting %d %s — records sync to the selected providers and are removed from deselected ones.',
            },
            $entries->count(),
            Str::plural('entry', $entries->count()),
        ));
    }

    /**
     * Apply the given field changes to every selected entry. Each entry is
     * re-validated with the change merged in; entries that would become
     * invalid or duplicate another entry are skipped, never half-applied.
     */
    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'set' => ['required', 'array:type,content,ttl,comment'],
            'set.type' => ['sometimes', Rule::enum(RecordType::class)],
            'set.content' => ['sometimes', 'string', 'max:2048'],
            'set.ttl' => ['sometimes', 'nullable', 'integer', 'min:60', 'max:86400'],
            'set.comment' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $set = $request->input('set', []);

        if ($set === []) {
            return back()->withErrors(['set' => 'Pick at least one field to change.']);
        }

        $entries = $this->selectedEntries($request);

        $updated = 0;
        $invalid = 0;
        $duplicates = 0;

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

            // A type change can leave fields behind that no longer apply.
            if (! $type->hasPriority()) {
                $data['priority'] = null;
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
        ]);

        if ($skippedParts !== []) {
            $message .= ' ('.implode(', ', $skippedParts).'.)';
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
