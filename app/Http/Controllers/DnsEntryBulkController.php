<?php

namespace App\Http\Controllers;

use App\Enums\RecordType;
use App\Enums\SyncStatus;
use App\Models\DnsEntry;
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
     * Replace the provider assignment of each selected entry with the given
     * selection. Providers that do not manage an entry's record type are
     * skipped for that entry; deselected providers get remote deletes.
     */
    public function providers(Request $request): RedirectResponse
    {
        $providerIds = $request->validate([
            'providers' => ['present', 'array'],
            'providers.*' => ['integer', 'exists:providers,id'],
        ])['providers'];

        $entries = $this->selectedEntries($request);

        $entries->each(function (DnsEntry $entry) use ($providerIds) {
            $this->sync->syncEntry($entry, $providerIds);

            $assigned = $entry->syncStates()
                ->where('sync_status', '!=', SyncStatus::Deleting)
                ->with('provider:id,name')
                ->get()
                ->map(fn ($state) => $state->provider?->name)
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
            'Retargeting %d %s — records sync to the selected providers and are removed from deselected ones.',
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

            $validator = Validator::make($payload, DnsEntryRules::rules($type));

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
     * delete) are silently dropped rather than failing the whole action.
     *
     * @return Collection<int, DnsEntry>
     */
    private function selectedEntries(Request $request): Collection
    {
        $ids = $request->validate([
            'ids' => ['required', 'array', 'max:1000'],
            'ids.*' => ['integer'],
        ])['ids'];

        return DnsEntry::query()->whereIn('id', $ids)->get();
    }
}
