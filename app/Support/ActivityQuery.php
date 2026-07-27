<?php

declare(strict_types = 1);

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Shared filter/paginate/serialize pipeline for the activity log — used by
 * the settings activity viewer (page + JSON data endpoint) and the
 * zone-scoped activity page. The JSON contract here is fixed: it feeds the
 * activity table AND the per-record activity dialog.
 */
class ActivityQuery
{
    /**
     * @return array{subject_type: ?string, subject_id: ?int, zone_id: ?int, event: ?string, causer_id: ?int, log: ?string, from: ?string, to: ?string, per_page: int, page: int}
     */
    public static function validateFilters(Request $request): array
    {
        $validated = $request->validate([
            'subject_type' => ['nullable', Rule::in(['entry', 'provider', 'user', 'zone'])],
            'subject_id' => ['nullable', 'integer'],
            'zone_id' => ['nullable', 'integer'],
            'event' => ['nullable', 'string', 'max:255'],
            'causer_id' => ['nullable', 'integer'],
            'log' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return [
            'subject_type' => $validated['subject_type'] ?? null,
            'subject_id' => isset($validated['subject_id']) ? (int) $validated['subject_id'] : null,
            'zone_id' => isset($validated['zone_id']) ? (int) $validated['zone_id'] : null,
            'event' => $validated['event'] ?? null,
            'causer_id' => isset($validated['causer_id']) ? (int) $validated['causer_id'] : null,
            'log' => $validated['log'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'per_page' => isset($validated['per_page']) ? (int) $validated['per_page'] : 25,
            'page' => isset($validated['page']) ? (int) $validated['page'] : 1,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: Collection, meta: array{currentPage: int, lastPage: int, perPage: int, total: int}}
     */
    public static function activities(array $filters): array
    {
        $paginator = self::query($filters)->paginate(
            perPage: $filters['per_page'],
            page: $filters['page'],
        );

        return [
            'data' => collect($paginator->items())
                ->map(fn (Activity $activity) => self::serialize($activity))
                ->values(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * The distinct event names present in the log — the event filter options.
     */
    public static function events(): Collection
    {
        return Activity::query()
            ->select('event')
            ->whereNotNull('event')
            ->distinct()
            ->orderBy('event')
            ->pluck('event');
    }

    /**
     * All users, for the causer filter options on the GLOBAL activity page
     * (Super Admin/Viewer only — do not feed this to zone-scoped viewers).
     */
    public static function users(): Collection
    {
        return User::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name]);
    }

    /**
     * Causer filter options for a single zone's activity page: only the users
     * who actually appear in that zone's activities — zone viewers must not
     * be able to enumerate the whole user table.
     */
    public static function causersForZone(int $zoneId): Collection
    {
        $causerIds = self::query([
            'subject_type' => null,
            'subject_id' => null,
            'zone_id' => $zoneId,
            'event' => null,
            'causer_id' => null,
            'log' => null,
            'from' => null,
            'to' => null,
        ])
            ->reorder()
            ->where('causer_type', 'user')
            ->whereNotNull('causer_id')
            ->distinct()
            ->pluck('causer_id');

        return User::query()
            ->whereIn('id', $causerIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name]);
    }

    /**
     * Resolve the "filtered to X" chip when both subject_type and subject_id
     * are given. Falls back to the subject's last logged name snapshot when
     * the record no longer exists.
     *
     * @param  array<string, mixed>  $filters
     * @return array{type: string, id: int, label: ?string}|null
     */
    public static function resolveSubject(array $filters): ?array
    {
        $type = $filters['subject_type'];
        $id = $filters['subject_id'];

        if (! $type || ! $id) {
            return null;
        }

        $class = Relation::getMorphedModel($type);
        $label = $class ? $class::query()->find($id)?->getAttribute('name') : null;

        if ($label === null) {
            $latest = Activity::query()
                ->where('subject_type', $type)
                ->where('subject_id', $id)
                ->latest()
                ->latest('id')
                ->first();

            $label = data_get($latest?->attribute_changes, 'attributes.name')
                ?? data_get($latest?->attribute_changes, 'old.name')
                ?? data_get($latest?->properties, 'attributes.name')
                ?? data_get($latest?->properties, 'old.name');
        }

        return ['type' => $type, 'id' => $id, 'label' => $label];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private static function query(array $filters): Builder
    {
        return Activity::query()
            ->with(['causer', 'subject'])
            ->when($filters['subject_type'], fn (Builder $query, string $type) => $query->where('subject_type', $type))
            ->when($filters['subject_id'], fn (Builder $query, int $id) => $query->where('subject_id', $id))
            // Everything that happened in a zone: activities on the zone
            // itself plus entry activities stamped with the zone id (the
            // stamp survives entry deletion). properties stores the id as an
            // int — bind an int so the sqlite json_extract comparison holds.
            ->when($filters['zone_id'], fn (Builder $query, int $id) => $query->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('subject_type', 'zone')->where('subject_id', $id))
                ->orWhere('properties->dns_zone_id', $id)))
            ->when($filters['event'], fn (Builder $query, string $event) => $query->where('event', $event))
            ->when($filters['causer_id'], fn (Builder $query, int $id) => $query->where('causer_id', $id))
            ->when($filters['log'], fn (Builder $query, string $log) => $query->where('log_name', $log))
            ->when($filters['from'], fn (Builder $query, string $from) => $query->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'], fn (Builder $query, string $to) => $query->where('created_at', '<=', Carbon::parse($to)->endOfDay()))
            ->latest()
            ->latest('id');
    }

    /**
     * Fixed JSON contract — consumed by both the activity page and the
     * per-record activity dialog. The trait-written attribute diff lives in
     * the `attribute_changes` column (`{attributes, old}`); custom
     * `properties` payloads (e.g. `config_changed`, `providers`) are merged
     * INTO `changes.attributes`, with real attribute keys winning on
     * collision, so the shape stays `{attributes?, old?} | null`.
     *
     * @return array<string, mixed>
     */
    private static function serialize(Activity $activity): array
    {
        $diff = $activity->attribute_changes ?? collect();
        $properties = $activity->properties ?? collect();

        $attributes = array_merge(
            $properties->except(['attributes', 'old'])->all(),
            (array) $properties->get('attributes', []),
            (array) $diff->get('attributes', []),
        );
        $old = array_merge(
            (array) $properties->get('old', []),
            (array) $diff->get('old', []),
        );

        $changes = array_filter([
            'attributes' => $attributes ?: null,
            'old' => $old ?: null,
        ]) ?: null;

        return [
            'id' => $activity->id,
            'logName' => $activity->log_name,
            'event' => $activity->event,
            'description' => $activity->description,
            'causer' => $activity->causer instanceof User
                ? ['id' => $activity->causer->id, 'name' => $activity->causer->name]
                : null,
            'subjectType' => $activity->subject_type,
            'subjectId' => $activity->subject_id !== null ? (int) $activity->subject_id : null,
            'subjectLabel' => $activity->subject?->getAttribute('name')
                ?? data_get($diff, 'attributes.name')
                ?? data_get($diff, 'old.name')
                ?? data_get($properties, 'attributes.name')
                ?? data_get($properties, 'old.name'),
            'changes' => $changes,
            'createdAt' => $activity->created_at->toIso8601String(),
        ];
    }
}
