<?php

declare(strict_types = 1);

namespace App\Support;

use App\Models\DnsZone;
use App\Models\DnsEntry;
use Illuminate\Http\Request;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Shared filter/sort/paginate pipeline for entry listings — the global
 * entries page and (zone mode) a single zone's entries.
 */
class EntryQuery
{
    /** Rows-per-page choices offered by the UI. */
    public const PAGE_SIZES = [10, 25, 50, 100];

    public const DEFAULT_PAGE_SIZE = 25;

    /**
     * Remembers the last chosen page size across visits. Written by the
     * frontend (plain value — excluded from cookie encryption in
     * bootstrap/app.php) and only ever read through the PAGE_SIZES allowlist.
     */
    public const PAGE_SIZE_COOKIE = 'entries_per_page';
    /** Sortable columns: request key => database column. */
    private const SORTABLE = [
        'name' => 'name',
        'type' => 'type',
        'content' => 'content',
        'ttl' => 'ttl',
        'updated' => 'updated_at',
    ];

    /**
     * @return array{entries: LengthAwarePaginator, filters: array<string, mixed>}
     */
    public static function build(Request $request, ?DnsZone $zone = null): array
    {
        $filters = $request->only(['search', 'type', 'provider', 'status']);

        if ($zone === null) {
            $filters['zone'] = $request->query('zone');
        }

        // Datatables-style server-side sorting: unknown values fall back to
        // the defaults instead of erroring.
        $sortable = array_keys(self::SORTABLE);

        if ($zone === null) {
            $sortable[] = 'zone';
        }

        $sort = (string) $request->query('sort', 'name');
        $sort = in_array($sort, $sortable, true) ? $sort : 'name';
        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';
        $filters['sort'] = $sort;
        $filters['direction'] = $direction;
        $filters['per_page'] = self::pageSize($request);

        // Zone access scoping: null means unrestricted (Super Admin/Viewer).
        // Applies to the global entries page and, belt-and-braces, to the
        // zone records page (its route is already policy-guarded).
        $accessibleZoneIds = $request->user()?->accessibleZoneIds();

        $entries = DnsEntry::query()
            ->with(['zone:id,name', 'syncStates.zoneProvider.provider:id,name,type,enabled'])
            ->when($accessibleZoneIds !== null, fn ($q) => $q->whereIn('dns_entries.dns_zone_id', $accessibleZoneIds))
            ->when($zone, fn ($q) => $q->where('dns_zone_id', $zone->id))
            ->when($zone === null && ($filters['zone'] ?? null), fn ($q) => $q->where('dns_zone_id', $filters['zone']))
            ->when($filters['search'] ?? null, function ($q, $search) use ($zone) {
                $term = '%' . mb_strtolower($search) . '%';

                $q->where(fn ($q) => $q
                    ->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(content) LIKE ?', [$term])
                    ->when($zone === null, fn ($q) => $q->orWhereHas(
                        'zone',
                        fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$term]),
                    )));
            })
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['provider'] ?? null, fn ($q, $provider) => $q->whereHas(
                'syncStates.zoneProvider',
                fn ($q) => $q->where('provider_id', $provider),
            ))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->whereHas(
                'syncStates',
                fn ($q) => $q->where('sync_status', $status),
            ))
            ->tap(fn ($q) => self::applySort($q, $sort, $direction))
            ->paginate($filters['per_page'])
            ->withQueryString();

        return ['entries' => $entries, 'filters' => $filters];
    }

    /**
     * `per_page` query param, else the remembered cookie, else the default;
     * anything outside PAGE_SIZES falls back instead of erroring.
     */
    private static function pageSize(Request $request): int
    {
        $requested = $request->query('per_page') ?? $request->cookie(self::PAGE_SIZE_COOKIE);
        $size = filter_var($requested, FILTER_VALIDATE_INT);

        return in_array($size, self::PAGE_SIZES, true) ? $size : self::DEFAULT_PAGE_SIZE;
    }

    private static function applySort($query, string $sort, string $direction): void
    {
        if ($sort === 'zone') {
            // Portable correlated subquery — no join, works on sqlite too.
            $query->orderBy(
                DnsZone::select('name')->whereColumn('dns_zones.id', 'dns_entries.dns_zone_id'),
                $direction,
            );
            $query->orderBy('name');
            $query->orderBy('id');

            return;
        }

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
}
