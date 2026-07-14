<?php

namespace App\Http\Controllers;

use App\Connectors\ConnectorRegistry;
use App\Http\Requests\DnsEntryRequest;
use App\Models\DnsEntry;
use App\Models\Provider;
use App\Services\DnsEntryImporter;
use App\Services\SyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DnsEntryController extends Controller
{
    public function __construct(private SyncService $sync) {}

    /** Sortable columns: request key => database column. */
    private const SORTABLE = [
        'name' => 'name',
        'type' => 'type',
        'content' => 'content',
        'ttl' => 'ttl',
        'updated' => 'updated_at',
    ];

    public function index(Request $request, ConnectorRegistry $registry): Response
    {
        $filters = $request->only(['search', 'type', 'provider', 'status']);

        // Datatables-style server-side sorting: unknown values fall back to
        // the defaults instead of erroring.
        $sort = (string) $request->query('sort', 'name');
        $sort = array_key_exists($sort, self::SORTABLE) ? $sort : 'name';
        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';
        $filters['sort'] = $sort;
        $filters['direction'] = $direction;

        $entries = DnsEntry::query()
            ->with(['syncStates.provider:id,name,type'])
            ->when($filters['search'] ?? null, function ($q, $search) {
                $term = '%'.mb_strtolower($search).'%';

                $q->where(fn ($q) => $q
                    ->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(content) LIKE ?', [$term]));
            })
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['provider'] ?? null, fn ($q, $provider) => $q->whereHas(
                'syncStates', fn ($q) => $q->where('provider_id', $provider),
            ))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->whereHas(
                'syncStates', fn ($q) => $q->where('sync_status', $status),
            ))
            ->tap(fn ($q) => $this->applySort($q, $sort, $direction))
            ->paginate(25)
            ->withQueryString()
            ->through(fn (DnsEntry $entry) => $this->presentEntry($entry));

        return Inertia::render('entries/index', [
            'entries' => $entries,
            'filters' => $filters,
            'providers' => $this->presentProviders(),
            'connectors' => $registry->descriptors(),
        ]);
    }

    private function applySort($query, string $sort, string $direction): void
    {
        $column = self::SORTABLE[$sort];

        // Null TTL means "automatic" — keep those rows last either way
        // (portable across Postgres and the sqlite test DB).
        if ($column === 'ttl') {
            $query->orderByRaw('(ttl IS NULL) ASC');
        }

        $query->orderBy($column, $direction);

        // Stable tiebreaks so pagination never shows duplicates.
        if ($column !== 'name') {
            $query->orderBy('name');
        }

        $query->orderBy('id');
    }

    public function store(DnsEntryRequest $request): RedirectResponse
    {
        $entry = DnsEntry::create($request->safe()->except('providers'));

        $this->sync->syncEntry($entry, $request->validated('providers'));

        return back()->with('success', "Entry {$entry->name} created — syncing to providers.");
    }

    public function update(DnsEntryRequest $request, DnsEntry $entry): RedirectResponse
    {
        $entry->update($request->safe()->except('providers'));

        $this->sync->syncEntry($entry, $request->validated('providers'));

        return back()->with('success', "Entry {$entry->name} updated — syncing to providers.");
    }

    public function destroy(DnsEntry $entry): RedirectResponse
    {
        $this->sync->deleteEntry($entry);

        // Deferred deletions (queued provider jobs remove the row later)
        // would otherwise be logged with no causer — attribute the request
        // here. Inline deletes are already attributed by the model trait.
        if ($entry->exists) {
            activity('entries')->performedOn($entry)->event('delete-requested')->log('delete-requested');
        }

        return back()->with('success', "Entry {$entry->name} is being removed from all providers.");
    }

    public function sync(DnsEntry $entry): RedirectResponse
    {
        $this->sync->syncEntry($entry);

        return back()->with('success', "Re-syncing {$entry->name} to all providers.");
    }

    public function import(Request $request, DnsEntryImporter $importer): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:1024'],
        ]);

        try {
            $result = $importer->import($request->file('file')->get());
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

    private function presentEntry(DnsEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'name' => $entry->name,
            'type' => $entry->type->value,
            'content' => $entry->content,
            'ttl' => $entry->ttl,
            'priority' => $entry->priority,
            'proxied' => $entry->proxied,
            'comment' => $entry->comment,
            'updatedAt' => $entry->updated_at->toIso8601String(),
            'syncStates' => $entry->syncStates->map(fn ($state) => [
                'id' => $state->id,
                'provider' => $state->provider?->only(['id', 'name']) + ['type' => $state->provider?->type->value],
                'status' => $state->sync_status->value,
                'lastSyncedAt' => $state->last_synced_at?->toIso8601String(),
                'lastError' => $state->last_error,
            ])->values(),
        ];
    }

    private function presentProviders(): array
    {
        return Provider::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Provider $provider) => [
                'id' => $provider->id,
                'name' => $provider->name,
                'type' => $provider->type->value,
                'enabled' => $provider->enabled,
                'managedRecordTypes' => $provider->managed_record_types ?? [],
            ])
            ->all();
    }
}
