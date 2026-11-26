<?php

declare(strict_types = 1);

use App\Models\User;
use App\Models\DnsZone;
use App\Models\DnsEntry;
use App\Models\Provider;
use App\Models\ZoneUser;
use App\Enums\SyncStatus;
use App\Models\ZoneProvider;
use App\Jobs\SyncEntryToProvider;
use App\Jobs\DeleteEntryFromProvider;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Queue::fake();

    $this->zone = DnsZone::factory()->create(['name' => 'example.com']);

    $this->cloudflare = Provider::factory()->cloudflare()->create([
        'managed_record_types' => ['A', 'AAAA', 'CNAME', 'MX', 'TXT'],
    ]);

    $this->cfAttachment = ZoneProvider::factory()->create([
        'dns_zone_id' => $this->zone->id,
        'provider_id' => $this->cloudflare->id,
        'config' => ['zone_id' => 'cf-zone-1'],
    ]);
});

function bulkEntry(ZoneProvider $attachment, array $attributes = []): DnsEntry
{
    $entry = DnsEntry::factory()->create($attributes + [
        'dns_zone_id' => $attachment->dns_zone_id,
        'type' => 'A',
        'content' => '10.0.0.1',
    ]);

    $entry->syncStates()->create([
        'zone_provider_id' => $attachment->id,
        'sync_status' => SyncStatus::Synced,
        'external_id' => "ext-{$entry->id}",
    ]);

    return $entry;
}

function piholeAttachment(DnsZone $zone): ZoneProvider
{
    return ZoneProvider::factory()->create([
        'dns_zone_id' => $zone->id,
        'provider_id' => Provider::factory()->pihole()->create(['managed_record_types' => ['A', 'CNAME']])->id,
    ]);
}

test('bulk sync re-queues a push for each entry to its assigned providers', function () {
    $first = bulkEntry($this->cfAttachment, ['name' => 'a']);
    $second = bulkEntry($this->cfAttachment, ['name' => 'b', 'content' => '10.0.0.2']);

    $this->post('/entries/bulk/sync', ['ids' => [$first->id, $second->id, 999999]])
        ->assertRedirect()
        ->assertSessionHas('success', fn ($message) => str_contains($message, '2 entries'));

    Queue::assertPushed(SyncEntryToProvider::class, 2);
    expect($first->syncStates()->sole()->sync_status)->toBe(SyncStatus::Pending);
});

test('bulk provider selection retargets entries, removing them from deselected attachments', function () {
    $pihole = piholeAttachment($this->zone);

    $entry = bulkEntry($this->cfAttachment, ['name' => 'move']);

    $this->post('/entries/bulk/providers', ['ids' => [$entry->id], 'zone_providers' => [$pihole->id]])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($entry->syncStates()->where('zone_provider_id', $this->cfAttachment->id)->sole()->sync_status)->toBe(SyncStatus::Deleting)
        ->and($entry->syncStates()->where('zone_provider_id', $pihole->id)->sole()->sync_status)->toBe(SyncStatus::Pending);

    Queue::assertPushed(DeleteEntryFromProvider::class, 1);
    Queue::assertPushed(SyncEntryToProvider::class, fn ($job) => $job->zoneProviderId === $pihole->id);
});

test('bulk provider selection skips attachments that do not manage an entry type', function () {
    $pihole = piholeAttachment($this->zone);

    $mx = bulkEntry($this->cfAttachment, ['name' => 'mail', 'type' => 'MX', 'content' => 'mx.example.com', 'priority' => 10]);

    $this->post('/entries/bulk/providers', ['ids' => [$mx->id], 'zone_providers' => [$this->cfAttachment->id, $pihole->id]])
        ->assertRedirect();

    // Pi-hole cannot hold an MX record, so only Cloudflare is (re)assigned.
    expect($mx->syncStates()->pluck('zone_provider_id')->all())->toBe([$this->cfAttachment->id]);
    Queue::assertPushed(SyncEntryToProvider::class, 1);
});

test('bulk provider selection silently drops attachments from other zones per entry', function () {
    $otherZone = DnsZone::factory()->create(['name' => 'other.dev']);
    $otherAttachment = ZoneProvider::factory()->create([
        'dns_zone_id' => $otherZone->id,
        'provider_id' => $this->cloudflare->id,
        'config' => ['zone_id' => 'cf-zone-2'],
    ]);

    $entry = bulkEntry($this->cfAttachment, ['name' => 'here']);
    $otherEntry = bulkEntry($otherAttachment, ['name' => 'there']);

    // Both zones' attachments submitted for both entries — each entry keeps
    // only its own zone's attachment.
    $this->post('/entries/bulk/providers', [
        'ids' => [$entry->id, $otherEntry->id],
        'zone_providers' => [$this->cfAttachment->id, $otherAttachment->id],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($entry->syncStates()->pluck('zone_provider_id')->all())->toBe([$this->cfAttachment->id])
        ->and($otherEntry->syncStates()->pluck('zone_provider_id')->all())->toBe([$otherAttachment->id]);

    Queue::assertNotPushed(DeleteEntryFromProvider::class);
});

test('bulk attach adds providers without touching existing assignments', function () {
    $pihole = piholeAttachment($this->zone);

    $entry = bulkEntry($this->cfAttachment, ['name' => 'keepme']);

    $this->post('/entries/bulk/providers', ['ids' => [$entry->id], 'zone_providers' => [$pihole->id], 'mode' => 'attach'])
        ->assertRedirect()
        ->assertSessionHas('success', fn ($message) => str_contains($message, 'Attaching'));

    // Cloudflare stays exactly as it was — no re-push, no delete.
    expect($entry->syncStates()->where('zone_provider_id', $this->cfAttachment->id)->sole()->sync_status)->toBe(SyncStatus::Synced)
        ->and($entry->syncStates()->where('zone_provider_id', $pihole->id)->sole()->sync_status)->toBe(SyncStatus::Pending);

    Queue::assertNotPushed(DeleteEntryFromProvider::class);
    Queue::assertPushed(SyncEntryToProvider::class, 1);
    Queue::assertPushed(SyncEntryToProvider::class, fn ($job) => $job->zoneProviderId === $pihole->id);
});

test('bulk attach skips attachments that do not manage the entry type', function () {
    $pihole = piholeAttachment($this->zone);

    $mx = bulkEntry($this->cfAttachment, ['name' => 'mail', 'type' => 'MX', 'content' => 'mx.example.com', 'priority' => 10]);

    $this->post('/entries/bulk/providers', ['ids' => [$mx->id], 'zone_providers' => [$pihole->id], 'mode' => 'attach'])
        ->assertRedirect();

    expect($mx->syncStates()->pluck('zone_provider_id')->all())->toBe([$this->cfAttachment->id]);
    Queue::assertNotPushed(SyncEntryToProvider::class);
});

test('bulk detach removes only the selected providers and re-pushes nothing', function () {
    $pihole = piholeAttachment($this->zone);

    $entry = bulkEntry($this->cfAttachment, ['name' => 'trim']);
    $entry->syncStates()->create([
        'zone_provider_id' => $pihole->id,
        'sync_status' => SyncStatus::Synced,
        'external_id' => "pi-{$entry->id}",
    ]);

    $this->post('/entries/bulk/providers', ['ids' => [$entry->id], 'zone_providers' => [$pihole->id], 'mode' => 'detach'])
        ->assertRedirect()
        ->assertSessionHas('success', fn ($message) => str_contains($message, 'Detaching'));

    expect($entry->syncStates()->where('zone_provider_id', $pihole->id)->sole()->sync_status)->toBe(SyncStatus::Deleting)
        ->and($entry->syncStates()->where('zone_provider_id', $this->cfAttachment->id)->sole()->sync_status)->toBe(SyncStatus::Synced);

    Queue::assertPushed(DeleteEntryFromProvider::class, 1);
    Queue::assertNotPushed(SyncEntryToProvider::class);
});

test('bulk detach drops never-pushed assignments immediately', function () {
    $pihole = piholeAttachment($this->zone);

    $entry = bulkEntry($this->cfAttachment, ['name' => 'local']);
    $entry->syncStates()->create([
        'zone_provider_id' => $pihole->id,
        'sync_status' => SyncStatus::Pending,
        'external_id' => null,
    ]);

    $this->post('/entries/bulk/providers', ['ids' => [$entry->id], 'zone_providers' => [$pihole->id], 'mode' => 'detach'])
        ->assertRedirect();

    expect($entry->syncStates()->where('zone_provider_id', $pihole->id)->exists())->toBeFalse();
    Queue::assertNotPushed(DeleteEntryFromProvider::class);
});

test('bulk detach leaves paused attachments untouched', function () {
    $pihole = piholeAttachment($this->zone);
    $pihole->update(['enabled' => false]);

    $entry = bulkEntry($this->cfAttachment, ['name' => 'paused']);
    $entry->syncStates()->create([
        'zone_provider_id' => $pihole->id,
        'sync_status' => SyncStatus::Synced,
        'external_id' => "pi-{$entry->id}",
    ]);

    $this->post('/entries/bulk/providers', ['ids' => [$entry->id], 'zone_providers' => [$pihole->id], 'mode' => 'detach'])
        ->assertRedirect();

    expect($entry->syncStates()->where('zone_provider_id', $pihole->id)->sole()->sync_status)->toBe(SyncStatus::Synced);
    Queue::assertNotPushed(DeleteEntryFromProvider::class);
});

test('bulk detach with keep_remote releases the records without deleting them at the provider', function () {
    $pihole = piholeAttachment($this->zone);

    $entry = bulkEntry($this->cfAttachment, ['name' => 'keep']);
    $entry->syncStates()->create([
        'zone_provider_id' => $pihole->id,
        'sync_status' => SyncStatus::Synced,
        'external_id' => "pi-{$entry->id}",
    ]);

    $this->post('/entries/bulk/providers', [
        'ids' => [$entry->id],
        'zone_providers' => [$pihole->id],
        'mode' => 'detach',
        'keep_remote' => true,
    ])
        ->assertRedirect()
        ->assertSessionHas('success', fn ($message) => str_contains($message, 'without deleting') && str_contains($message, '1 record kept'));

    expect($entry->syncStates()->where('zone_provider_id', $pihole->id)->exists())->toBeFalse()
        ->and($entry->syncStates()->where('zone_provider_id', $this->cfAttachment->id)->sole()->sync_status)->toBe(SyncStatus::Synced)
        ->and(DnsEntry::whereKey($entry->id)->exists())->toBeTrue();

    Queue::assertNotPushed(DeleteEntryFromProvider::class);
    Queue::assertNotPushed(SyncEntryToProvider::class);

    $activity = Activity::query()->where('event', 'providers-changed')->sole();
    expect($activity->properties->get('providers'))->toBe([$this->cloudflare->name])
        ->and($activity->properties->get('kept_at_providers'))->toBe([$pihole->provider->name]);
});

test('bulk detach without keep_remote still deletes at the provider and records nothing kept', function () {
    $entry = bulkEntry($this->cfAttachment, ['name' => 'gone']);

    $this->post('/entries/bulk/providers', [
        'ids' => [$entry->id],
        'zone_providers' => [$this->cfAttachment->id],
        'mode' => 'detach',
        'keep_remote' => false,
    ])
        ->assertRedirect()
        ->assertSessionHas('success', fn ($message) => str_contains($message, 'being deleted at the provider'));

    expect($entry->syncStates()->sole()->sync_status)->toBe(SyncStatus::Deleting);
    Queue::assertPushed(DeleteEntryFromProvider::class, 1);

    expect(Activity::query()->where('event', 'providers-changed')->sole()->properties->has('kept_at_providers'))->toBeFalse();
});

test('bulk replace with keep_remote releases deselected attachments and pushes the rest', function () {
    $pihole = piholeAttachment($this->zone);

    $entry = bulkEntry($this->cfAttachment, ['name' => 'swap']);

    $this->post('/entries/bulk/providers', [
        'ids' => [$entry->id],
        'zone_providers' => [$pihole->id],
        'keep_remote' => true,
    ])
        ->assertRedirect()
        ->assertSessionHas('success', fn ($message) => str_contains($message, 'kept there'));

    expect($entry->syncStates()->where('zone_provider_id', $this->cfAttachment->id)->exists())->toBeFalse()
        ->and($entry->syncStates()->where('zone_provider_id', $pihole->id)->sole()->sync_status)->toBe(SyncStatus::Pending);

    Queue::assertNotPushed(DeleteEntryFromProvider::class);
    Queue::assertPushed(SyncEntryToProvider::class, fn ($job) => $job->zoneProviderId === $pihole->id);
});

test('keep_remote never touches paused attachments', function () {
    $pihole = piholeAttachment($this->zone);
    $pihole->provider->update(['enabled' => false]);

    $entry = bulkEntry($this->cfAttachment, ['name' => 'paused-keep']);
    $entry->syncStates()->create([
        'zone_provider_id' => $pihole->id,
        'sync_status' => SyncStatus::Synced,
        'external_id' => "pi-{$entry->id}",
    ]);

    foreach (['detach', 'replace'] as $mode) {
        $this->post('/entries/bulk/providers', [
            'ids' => [$entry->id],
            'zone_providers' => $mode === 'detach' ? [$pihole->id] : [$this->cfAttachment->id],
            'mode' => $mode,
            'keep_remote' => true,
        ])->assertRedirect();

        expect($entry->syncStates()->where('zone_provider_id', $pihole->id)->sole()->sync_status)->toBe(SyncStatus::Synced);
    }

    Queue::assertNotPushed(DeleteEntryFromProvider::class);
});

test('keep_remote leaves a delete that is already in flight alone', function () {
    $entry = bulkEntry($this->cfAttachment, ['name' => 'inflight']);
    $entry->syncStates()->update(['sync_status' => SyncStatus::Deleting]);

    $this->post('/entries/bulk/providers', [
        'ids' => [$entry->id],
        'zone_providers' => [$this->cfAttachment->id],
        'mode' => 'detach',
        'keep_remote' => true,
    ])->assertRedirect();

    expect($entry->syncStates()->sole()->sync_status)->toBe(SyncStatus::Deleting);
});

test('keep_remote must be a boolean', function () {
    $entry = bulkEntry($this->cfAttachment, ['name' => 'strict']);

    $this->post('/entries/bulk/providers', [
        'ids' => [$entry->id],
        'zone_providers' => [$this->cfAttachment->id],
        'mode' => 'detach',
        'keep_remote' => 'sometimes',
    ])->assertSessionHasErrors('keep_remote');

    expect($entry->syncStates()->sole()->sync_status)->toBe(SyncStatus::Synced);
});

test('updating an entry with keep_remote releases unticked providers without a remote delete', function () {
    $pihole = piholeAttachment($this->zone);

    $entry = bulkEntry($this->cfAttachment, ['name' => 'edit']);
    $entry->syncStates()->create([
        'zone_provider_id' => $pihole->id,
        'sync_status' => SyncStatus::Synced,
        'external_id' => "pi-{$entry->id}",
    ]);

    $payload = ['name' => 'edit', 'type' => 'A', 'content' => '10.0.0.1', 'proxied' => false, 'zone_providers' => [$this->cfAttachment->id]];

    $this->put("/entries/{$entry->id}", $payload + ['keep_remote' => true])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn ($message) => str_contains($message, "kept at {$pihole->provider->name}"));

    expect($entry->syncStates()->where('zone_provider_id', $pihole->id)->exists())->toBeFalse();
    Queue::assertNotPushed(DeleteEntryFromProvider::class);
    Queue::assertPushed(SyncEntryToProvider::class, fn ($job) => $job->zoneProviderId === $this->cfAttachment->id);

    expect(Activity::query()->where('event', 'providers-changed')->sole()->properties->get('kept_at_providers'))
        ->toBe([$pihole->provider->name]);
});

test('updating an entry without keep_remote still deletes unticked providers remotely', function () {
    $pihole = piholeAttachment($this->zone);

    $entry = bulkEntry($this->cfAttachment, ['name' => 'edit-delete']);
    $entry->syncStates()->create([
        'zone_provider_id' => $pihole->id,
        'sync_status' => SyncStatus::Synced,
        'external_id' => "pi-{$entry->id}",
    ]);

    $this->put("/entries/{$entry->id}", [
        'name' => 'edit-delete', 'type' => 'A', 'content' => '10.0.0.1', 'proxied' => false,
        'zone_providers' => [$this->cfAttachment->id],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($entry->syncStates()->where('zone_provider_id', $pihole->id)->sole()->sync_status)->toBe(SyncStatus::Deleting);
    Queue::assertPushed(DeleteEntryFromProvider::class, 1);
    expect(Activity::query()->where('event', 'providers-changed')->exists())->toBeFalse();

    $this->put("/entries/{$entry->id}", [
        'name' => 'edit-delete', 'type' => 'A', 'content' => '10.0.0.1', 'zone_providers' => [], 'keep_remote' => 'nope',
    ])->assertSessionHasErrors('keep_remote');
});

test('keep_remote does not widen who may change providers', function () {
    $entry = bulkEntry($this->cfAttachment, ['name' => 'guarded']);

    $this->actingAs(User::factory()->superViewer()->create());

    $this->post('/entries/bulk/providers', [
        'ids' => [$entry->id],
        'zone_providers' => [$this->cfAttachment->id],
        'mode' => 'detach',
        'keep_remote' => true,
    ])->assertRedirect()->assertSessionHas('success', fn ($message) => str_contains($message, '0 entries'));

    $this->put("/entries/{$entry->id}", [
        'name' => 'guarded', 'type' => 'A', 'content' => '10.0.0.1', 'zone_providers' => [], 'keep_remote' => true,
    ])->assertForbidden();

    expect($entry->syncStates()->sole()->sync_status)->toBe(SyncStatus::Synced);
});

test('bulk attach and detach require at least one provider', function () {
    $entry = bulkEntry($this->cfAttachment, ['name' => 'noop']);

    foreach (['attach', 'detach'] as $mode) {
        $this->post('/entries/bulk/providers', ['ids' => [$entry->id], 'zone_providers' => [], 'mode' => $mode])
            ->assertSessionHasErrors('zone_providers');
    }

    expect($entry->syncStates()->sole()->sync_status)->toBe(SyncStatus::Synced);
});

test('bulk providers rejects an unknown mode', function () {
    $entry = bulkEntry($this->cfAttachment, ['name' => 'nope']);

    $this->post('/entries/bulk/providers', ['ids' => [$entry->id], 'zone_providers' => [], 'mode' => 'merge'])
        ->assertSessionHasErrors('mode');
});

test('bulk edit applies the ticked fields to every entry and re-syncs', function () {
    $first = bulkEntry($this->cfAttachment, ['name' => 'a', 'ttl' => null]);
    $second = bulkEntry($this->cfAttachment, ['name' => 'b', 'content' => '10.0.0.2', 'ttl' => 120]);

    $this->patch('/entries/bulk', [
        'ids' => [$first->id, $second->id],
        'set' => ['ttl' => 3600, 'comment' => 'homelab'],
    ])->assertRedirect()->assertSessionHas('success', fn ($message) => str_contains($message, 'Updated 2 entries'));

    expect($first->refresh()->ttl)->toBe(3600)
        ->and($first->comment)->toBe('homelab')
        ->and($second->refresh()->ttl)->toBe(3600)
        // Untouched fields keep their per-entry values.
        ->and($second->content)->toBe('10.0.0.2');

    Queue::assertPushed(SyncEntryToProvider::class, 2);
});

test('bulk edit skips entries that would become invalid and reports them', function () {
    $ip = bulkEntry($this->cfAttachment, ['name' => 'ok']);
    $host = bulkEntry($this->cfAttachment, ['name' => 'alias', 'type' => 'CNAME', 'content' => 'target.example.com']);

    // Changing everything to type A: the CNAME's hostname content is not an IPv4.
    $this->patch('/entries/bulk', [
        'ids' => [$ip->id, $host->id],
        'set' => ['type' => 'A'],
    ])->assertRedirect()->assertSessionHas('success', fn ($message) => str_contains($message, '1 skipped as invalid'));

    expect($host->refresh()->type->value)->toBe('CNAME');
});

test('bulk edit skips changes that would duplicate another entry in the same zone', function () {
    bulkEntry($this->cfAttachment, ['name' => 'dup', 'content' => '10.0.0.9']);
    $entry = bulkEntry($this->cfAttachment, ['name' => 'dup', 'content' => '10.0.0.1']);

    $this->patch('/entries/bulk', [
        'ids' => [$entry->id],
        'set' => ['content' => '10.0.0.9'],
    ])->assertRedirect()->assertSessionHas('success', fn ($message) => str_contains($message, '1 skipped to avoid duplicates'));

    expect($entry->refresh()->content)->toBe('10.0.0.1');
});

test('an identical record in a different zone is not a duplicate', function () {
    $otherZone = DnsZone::factory()->create(['name' => 'other.dev']);
    $otherAttachment = ZoneProvider::factory()->create([
        'dns_zone_id' => $otherZone->id,
        'provider_id' => $this->cloudflare->id,
        'config' => ['zone_id' => 'cf-zone-2'],
    ]);

    bulkEntry($otherAttachment, ['name' => 'dup', 'content' => '10.0.0.9']);
    $entry = bulkEntry($this->cfAttachment, ['name' => 'dup', 'content' => '10.0.0.1']);

    $this->patch('/entries/bulk', [
        'ids' => [$entry->id],
        'set' => ['content' => '10.0.0.9'],
    ])->assertRedirect()->assertSessionHas('success', fn ($message) => str_contains($message, 'Updated 1 entry'));

    expect($entry->refresh()->content)->toBe('10.0.0.9');
});

test('bulk edit clears the priority when the type change drops it', function () {
    $mx = bulkEntry($this->cfAttachment, ['name' => 'mail', 'type' => 'MX', 'content' => 'mx.example.com', 'priority' => 10]);

    $this->patch('/entries/bulk', [
        'ids' => [$mx->id],
        'set' => ['type' => 'CNAME'],
    ])->assertRedirect();

    expect($mx->refresh()->type->value)->toBe('CNAME')
        ->and($mx->priority)->toBeNull();
});

test('bulk edit requires at least one field', function () {
    $entry = bulkEntry($this->cfAttachment);

    $this->from('/entries')
        ->patch('/entries/bulk', ['ids' => [$entry->id], 'set' => []])
        ->assertRedirect('/entries')
        ->assertSessionHasErrors('set');
});

test('bulk delete removes entries from their providers', function () {
    $pushed = bulkEntry($this->cfAttachment, ['name' => 'a']);
    $localOnly = DnsEntry::factory()->create([
        'dns_zone_id' => $this->zone->id, 'name' => 'local', 'type' => 'A', 'content' => '10.0.0.5',
    ]);

    $this->delete('/entries/bulk', ['ids' => [$pushed->id, $localOnly->id]])
        ->assertRedirect()
        ->assertSessionHas('success', fn ($message) => str_contains($message, 'Deleting 2 entries'));

    expect($pushed->syncStates()->sole()->sync_status)->toBe(SyncStatus::Deleting)
        ->and(DnsEntry::whereKey($localOnly->id)->exists())->toBeFalse();

    Queue::assertPushed(DeleteEntryFromProvider::class, 1);
});

test('bulk selections shrink to the zones where the user manages records', function () {
    $otherZone = DnsZone::factory()->create(['name' => 'other.dev']);
    $otherAttachment = ZoneProvider::factory()->create([
        'dns_zone_id' => $otherZone->id,
        'provider_id' => $this->cloudflare->id,
        'config' => ['zone_id' => 'cf-zone-2'],
    ]);

    $granted = bulkEntry($this->cfAttachment, ['name' => 'granted']);
    $ungranted = bulkEntry($otherAttachment, ['name' => 'ungranted']);

    $user = User::factory()->noRoles()->create();
    ZoneUser::factory()->dnsManager()->create(['user_id' => $user->id, 'dns_zone_id' => $this->zone->id]);

    $this->actingAs($user);

    // A selection spanning both zones only ever touches the granted one.
    $this->patch('/entries/bulk', [
        'ids' => [$granted->id, $ungranted->id],
        'set' => ['ttl' => 3600],
    ])->assertRedirect()->assertSessionHas('success', fn ($message) => str_contains($message, 'Updated 1 entry'));

    expect($granted->refresh()->ttl)->toBe(3600)
        ->and($ungranted->refresh()->ttl)->toBeNull();

    $this->post('/entries/bulk/sync', ['ids' => [$granted->id, $ungranted->id]])
        ->assertRedirect()
        ->assertSessionHas('success', fn ($message) => str_contains($message, '1 entry'));

    $this->delete('/entries/bulk', ['ids' => [$granted->id, $ungranted->id]])->assertRedirect();

    expect($granted->syncStates()->sole()->sync_status)->toBe(SyncStatus::Deleting)
        ->and($ungranted->syncStates()->sole()->sync_status)->not->toBe(SyncStatus::Deleting);
});

test('super viewer bulk selections shrink to nothing and mutate nothing', function () {
    $entry = bulkEntry($this->cfAttachment, ['name' => 'untouchable']);

    $this->actingAs(User::factory()->superViewer()->create());

    $this->post('/entries/bulk/sync', ['ids' => [$entry->id]])
        ->assertRedirect()
        ->assertSessionHas('success', fn ($message) => str_contains($message, '0 entries'));
    $this->patch('/entries/bulk', ['ids' => [$entry->id], 'set' => ['ttl' => 3600]])->assertRedirect();
    $this->delete('/entries/bulk', ['ids' => [$entry->id]])->assertRedirect();

    expect(DnsEntry::whereKey($entry->id)->exists())->toBeTrue()
        ->and($entry->refresh()->ttl)->toBeNull()
        ->and($entry->syncStates()->sole()->sync_status)->toBe(SyncStatus::Synced);
});
