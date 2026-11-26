<?php

declare(strict_types = 1);

namespace App\Services;

use Throwable;
use App\Models\SyncLog;
use App\Models\DnsEntry;
use App\Enums\SyncStatus;
use App\Models\EntrySyncState;
use App\Support\DnsEntryRules;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Connectors\DTOs\RemoteRecord;
use App\Exceptions\AcceptRemoteException;
use Illuminate\Support\Facades\Validator;

/**
 * Resolves drift the other way round from "Sync now": adopt the values a
 * provider currently holds into the local entry, then re-push the entry to
 * its other providers so every attachment matches again.
 */
class AcceptRemoteService
{
    /**
     * The entry fields a provider's values may be accepted for. Name and
     * type are identity — a renamed or retyped record is refused instead.
     */
    public const FIELDS = ['content', 'ttl', 'priority', 'proxied'];

    public function __construct(private SyncService $sync)
    {
    }

    /**
     * Human label of an accepted field, for flash messages.
     */
    public static function label(string $field): string
    {
        return $field === 'ttl' ? 'TTL' : $field;
    }

    /**
     * Fetch the attachment's current remote listing — once per attachment,
     * so a bulk accept can share it across entries.
     *
     * @return Collection<int, RemoteRecord>
     */
    public function listRemote(EntrySyncState $state): Collection
    {
        $attachment = $state->zoneProvider;

        try {
            return $attachment->connector()->listRecords();
        } catch (Throwable $e) {
            throw new AcceptRemoteException("Could not read the current records from {$attachment->provider->name}: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * Copy the drifted fields of the state's remote record into its entry,
     * mark the state synced, and queue pushes to the entry's other active
     * attachments. The remote record is read live (never from the stored
     * drift details); pass a pre-fetched listing of the same attachment to
     * skip the fetch. Refusals throw before anything is written.
     *
     * @param  Collection<int, RemoteRecord>|null  $remote
     *
     * @throws AcceptRemoteException
     * @return array{fields: list<string>, repushed: int}
     */
    public function accept(EntrySyncState $state, ?Collection $remote = null): array
    {
        $state->loadMissing(['entry.zone', 'zoneProvider.provider', 'zoneProvider.zone']);

        $entry = $state->entry;
        $attachment = $state->zoneProvider;
        $providerName = $attachment->provider->name;

        if ($state->sync_status !== SyncStatus::Drifted) {
            throw new AcceptRemoteException("{$entry->fqdn} is not drifted on {$providerName} — there is nothing to accept.");
        }

        if (! $attachment->isActive()) {
            throw new AcceptRemoteException("{$providerName} is paused for {$entry->zone->name} — enable it before accepting its values.");
        }

        $remote ??= $this->listRemote($state);
        $capabilities = $attachment->connector()::capabilities();

        $claimed = $attachment->provider->syncStates()
            ->whereKeyNot($state->id)
            ->whereNotNull('external_id')
            ->pluck('external_id')
            ->flip();

        $record = RemoteRecord::closest($remote, $state->external_id, $entry, $capabilities, $claimed);

        if ($record === null) {
            throw new AcceptRemoteException("The record for {$entry->fqdn} no longer exists at {$providerName} — there are no values to accept. Use Sync now to recreate it.");
        }

        $diff = collect($record->diff($entry, $capabilities))->keyBy('field');

        if ($diff->has('name') || $diff->has('type')) {
            throw new AcceptRemoteException("The record for {$entry->fqdn} was renamed or changed type at {$providerName} — edit the entry instead of accepting its values.");
        }

        $fields = $diff->keys()->intersect(self::FIELDS)->values()->all();

        $values = collect([
            'content' => $record->content,
            'ttl' => $record->ttl,
            'priority' => $record->priority,
            'proxied' => $record->proxied,
        ])->only($fields)->all();

        $this->validate($entry, $values, $providerName);

        $old = collect($entry->only($fields))->all();

        DB::transaction(function () use ($entry, $state, $record, $values, $old, $fields, $providerName) {
            if ($values !== []) {
                // One audit record for the whole action (below) instead of
                // the trait's generic `updated` event.
                $entry->disableLogging();
                $entry->update($values);
                $entry->enableLogging();
            }

            $state->update([
                'sync_status' => SyncStatus::Synced,
                'external_id' => $record->externalId,
                'last_synced_at' => now(),
                'last_error' => null,
                'drift_details' => null,
            ]);

            activity('entries')
                ->performedOn($entry)
                ->event('accepted-remote')
                ->withChanges(['attributes' => $values, 'old' => $old])
                ->withProperties(['provider' => $providerName, 'fields' => $fields])
                ->log('accepted-remote');
        });

        SyncLog::record(
            $attachment->provider,
            $entry,
            'accept-remote',
            'success',
            $fields === []
                ? "{$entry->fqdn} already matches {$attachment->label()} — marked synced"
                : "Accepted {$attachment->label()} values for {$entry->fqdn} (" . implode(', ', $fields) . ')',
            $entry->dns_zone_id,
        );

        $repushed = $fields === [] ? 0 : $this->sync->syncEntryExcept($entry, $attachment->id);

        return ['fields' => $fields, 'repushed' => $repushed];
    }

    /**
     * Re-validate the entry with the provider's values merged in, exactly
     * like a bulk edit: zone-aware rules plus the zone+name+type+content
     * duplicate check.
     */
    private function validate(DnsEntry $entry, array $values, string $providerName): void
    {
        if ($values === []) {
            return;
        }

        $payload = array_merge([
            'name' => $entry->name,
            'type' => $entry->type->value,
            'content' => $entry->content,
            'ttl' => $entry->ttl,
            'priority' => $entry->priority,
            'proxied' => (bool) $entry->proxied,
            'comment' => $entry->comment,
        ], $values);

        $validator = Validator::make($payload, DnsEntryRules::rules($entry->type, $entry->zone), DnsEntryRules::messages());

        if ($validator->fails()) {
            throw new AcceptRemoteException("{$providerName}'s values are not valid for {$entry->fqdn}: {$validator->errors()->first()}");
        }

        $duplicate = DnsEntry::query()
            ->whereKeyNot($entry->id)
            ->where('dns_zone_id', $entry->dns_zone_id)
            ->where('name', $entry->name)
            ->where('type', $entry->type->value)
            ->where('content', $payload['content'])
            ->exists();

        if ($duplicate) {
            throw new AcceptRemoteException("Accepting {$providerName}'s values would make {$entry->fqdn} a duplicate of another entry in {$entry->zone->name}.");
        }
    }
}
