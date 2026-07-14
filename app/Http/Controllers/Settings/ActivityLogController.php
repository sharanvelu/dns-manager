<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class ActivityLogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $this->validateFilters($request);

        return Inertia::render('settings/activity', [
            'activities' => $this->activities($filters),
            'filters' => $filters,
            'users' => User::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name]),
            'events' => Activity::query()
                ->select('event')
                ->whereNotNull('event')
                ->distinct()
                ->orderBy('event')
                ->pluck('event'),
            'subject' => $this->resolveSubject($filters),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->activities($this->validateFilters($request)));
    }

    /**
     * @return array{subject_type: ?string, subject_id: ?int, event: ?string, causer_id: ?int, log: ?string, from: ?string, to: ?string, per_page: int, page: int}
     */
    private function validateFilters(Request $request): array
    {
        $validated = $request->validate([
            'subject_type' => ['nullable', Rule::in(['entry', 'provider', 'user'])],
            'subject_id' => ['nullable', 'integer'],
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
    private function activities(array $filters): array
    {
        $paginator = $this->query($filters)->paginate(
            perPage: $filters['per_page'],
            page: $filters['page'],
        );

        return [
            'data' => collect($paginator->items())
                ->map(fn (Activity $activity) => $this->serialize($activity))
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
     * @param  array<string, mixed>  $filters
     */
    private function query(array $filters): Builder
    {
        return Activity::query()
            ->with(['causer', 'subject'])
            ->when($filters['subject_type'], fn (Builder $query, string $type) => $query->where('subject_type', $type))
            ->when($filters['subject_id'], fn (Builder $query, int $id) => $query->where('subject_id', $id))
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
    private function serialize(Activity $activity): array
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

    /**
     * Resolve the "filtered to X" chip when both subject_type and subject_id
     * are given. Falls back to the subject's last logged name snapshot when
     * the record no longer exists.
     *
     * @param  array<string, mixed>  $filters
     * @return array{type: string, id: int, label: ?string}|null
     */
    private function resolveSubject(array $filters): ?array
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
}
