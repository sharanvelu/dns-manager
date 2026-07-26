<?php

namespace App\Http\Controllers;

use App\Connectors\ConnectorRegistry;
use App\Http\Requests\DnsEntryRequest;
use App\Models\DnsEntry;
use App\Models\DnsZone;
use App\Services\DnsEntryImporter;
use App\Services\SyncService;
use App\Support\EntryPresenter;
use App\Support\EntryQuery;
use App\Support\ZonePermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DnsEntryController extends Controller
{
    public function __construct(private SyncService $sync) {}

    public function index(Request $request, ConnectorRegistry $registry): Response
    {
        ['entries' => $entries, 'filters' => $filters] = EntryQuery::build($request);

        $user = $request->user();
        $zoneIds = $user->accessibleZoneIds();

        $zones = DnsZone::query()
            ->when($zoneIds !== null, fn ($q) => $q->whereIn('id', $zoneIds))
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('entries/index', [
            'entries' => $entries->through(fn (DnsEntry $entry) => EntryPresenter::entry($entry)),
            'filters' => $filters,
            'zones' => $zones->map(fn (DnsZone $zone) => ['id' => $zone->id, 'name' => $zone->name]),
            'zoneAttachments' => EntryPresenter::zoneAttachments($zoneIds),
            'connectors' => $registry->descriptors(),
            'zoneCan' => ZonePermissions::mapFor($user, $zones),
        ]);
    }

    public function store(DnsEntryRequest $request): RedirectResponse
    {
        $entry = DnsEntry::create($request->safe()->except('zone_providers'));

        $this->sync->syncEntry($entry, $request->validated('zone_providers'));

        return back()->with('success', "Entry {$entry->fqdn} created — syncing to providers.");
    }

    public function update(DnsEntryRequest $request, DnsEntry $entry): RedirectResponse
    {
        $entry->update($request->safe()->except('zone_providers'));

        $this->sync->syncEntry($entry, $request->validated('zone_providers'));

        return back()->with('success', "Entry {$entry->fqdn} updated — syncing to providers.");
    }

    public function destroy(DnsEntry $entry): RedirectResponse
    {
        $this->authorize('manageRecords', $entry->zone);

        $fqdn = $entry->fqdn;

        $this->sync->deleteEntry($entry);

        // Deferred deletions (queued provider jobs remove the row later)
        // would otherwise be logged with no causer — attribute the request
        // here. Inline deletes are already attributed by the model trait.
        if ($entry->exists) {
            activity('entries')->performedOn($entry)->event('delete-requested')->log('delete-requested');
        }

        return back()->with('success', "Entry {$fqdn} is being removed from all providers.");
    }

    public function sync(DnsEntry $entry): RedirectResponse
    {
        $this->authorize('manageRecords', $entry->zone);

        $this->sync->syncEntry($entry);

        return back()->with('success', "Re-syncing {$entry->fqdn} to all providers.");
    }

    public function import(Request $request, DnsEntryImporter $importer): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:1024'],
            'dns_zone_id' => ['required', 'integer', 'exists:dns_zones,id'],
        ]);

        $zone = DnsZone::findOrFail($validated['dns_zone_id']);

        $this->authorize('manageRecords', $zone);

        try {
            $result = $importer->import($request->file('file')->get(), $zone);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return back()->with('importResult', $result);
    }

    public function importSample(): StreamedResponse
    {
        return response()->streamDownload(
            fn () => print DnsEntryImporter::sampleCsv(),
            'dns-entries-sample.csv',
            ['Content-Type' => 'text/csv'],
        );
    }
}
