<?php

use App\Enums\Role;
use App\Enums\SyncStatus;
use App\Jobs\DeleteEntryFromProvider;
use App\Jobs\SyncEntryToProvider;
use App\Models\DnsEntry;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Queue::fake();

    $this->cloudflare = Provider::factory()->cloudflare()->create([
        'managed_record_types' => ['A', 'AAAA', 'CNAME', 'MX', 'TXT'],
    ]);
});

function bulkEntry(Provider $provider, array $attributes = []): DnsEntry
{
    $entry = DnsEntry::factory()->create($attributes + ['type' => 'A', 'content' => '10.0.0.1']);
    $entry->syncStates()->create([
        'provider_id' => $provider->id,
        'sync_status' => SyncStatus::Synced,
        'external_id' => "ext-{$entry->id}",
    ]);

    return $entry;
}

test('bulk sync re-queues a push for each entry to its assigned providers', function () {
    $first = bulkEntry($this->cloudflare, ['name' => 'a.example.com']);
    $second = bulkEntry($this->cloudflare, ['name' => 'b.example.com', 'content' => '10.0.0.2']);

    $this->post('/entries/bulk/sync', ['ids' => [$first->id, $second->id, 999999]])
        ->assertRedirect()
        ->assertSessionHas('success', fn ($message) => str_contains($message, '2 entries'));

    Queue::assertPushed(SyncEntryToProvider::class, 2);
    expect($first->syncStates()->sole()->sync_status)->toBe(SyncStatus::Pending);
});

test('bulk provider selection retargets entries, removing them from deselected providers', function () {
    $pihole = Provider::factory()->pihole()->create(['managed_record_types' => ['A', 'CNAME']]);

    $entry = bulkEntry($this->cloudflare, ['name' => 'move.example.com']);

    $this->post('/entries/bulk/providers', ['ids' => [$entry->id], 'providers' => [$pihole->id]])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($entry->syncStates()->where('provider_id', $this->cloudflare->id)->sole()->sync_status)->toBe(SyncStatus::Deleting)
        ->and($entry->syncStates()->where('provider_id', $pihole->id)->sole()->sync_status)->toBe(SyncStatus::Pending);

    Queue::assertPushed(DeleteEntryFromProvider::class, 1);
    Queue::assertPushed(SyncEntryToProvider::class, fn ($job) => $job->providerId === $pihole->id);
});

test('bulk provider selection skips providers that do not manage an entry type', function () {
    $pihole = Provider::factory()->pihole()->create(['managed_record_types' => ['A', 'CNAME']]);

    $mx = bulkEntry($this->cloudflare, ['name' => 'mail.example.com', 'type' => 'MX', 'content' => 'mx.example.com', 'priority' => 10]);

    $this->post('/entries/bulk/providers', ['ids' => [$mx->id], 'providers' => [$this->cloudflare->id, $pihole->id]])
        ->assertRedirect();

    // Pi-hole cannot hold an MX record, so only Cloudflare is (re)assigned.
    expect($mx->syncStates()->pluck('provider_id')->all())->toBe([$this->cloudflare->id]);
    Queue::assertPushed(SyncEntryToProvider::class, 1);
});

test('bulk edit applies the ticked fields to every entry and re-syncs', function () {
    $first = bulkEntry($this->cloudflare, ['name' => 'a.example.com', 'ttl' => null]);
    $second = bulkEntry($this->cloudflare, ['name' => 'b.example.com', 'content' => '10.0.0.2', 'ttl' => 120]);

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
    $ip = bulkEntry($this->cloudflare, ['name' => 'ok.example.com']);
    $host = bulkEntry($this->cloudflare, ['name' => 'cname.example.com', 'type' => 'CNAME', 'content' => 'target.example.com']);

    // Changing everything to type A: the CNAME's hostname content is not an IPv4.
    $this->patch('/entries/bulk', [
        'ids' => [$ip->id, $host->id],
        'set' => ['type' => 'A'],
    ])->assertRedirect()->assertSessionHas('success', fn ($message) => str_contains($message, '1 skipped as invalid'));

    expect($host->refresh()->type->value)->toBe('CNAME');
});

test('bulk edit skips changes that would duplicate another entry', function () {
    bulkEntry($this->cloudflare, ['name' => 'dup.example.com', 'content' => '10.0.0.9']);
    $entry = bulkEntry($this->cloudflare, ['name' => 'dup.example.com', 'content' => '10.0.0.1']);

    $this->patch('/entries/bulk', [
        'ids' => [$entry->id],
        'set' => ['content' => '10.0.0.9'],
    ])->assertRedirect()->assertSessionHas('success', fn ($message) => str_contains($message, '1 skipped to avoid duplicates'));

    expect($entry->refresh()->content)->toBe('10.0.0.1');
});

test('bulk edit clears the priority when the type change drops it', function () {
    $mx = bulkEntry($this->cloudflare, ['name' => 'mail.example.com', 'type' => 'MX', 'content' => 'mx.example.com', 'priority' => 10]);

    $this->patch('/entries/bulk', [
        'ids' => [$mx->id],
        'set' => ['type' => 'CNAME'],
    ])->assertRedirect();

    expect($mx->refresh()->type->value)->toBe('CNAME')
        ->and($mx->priority)->toBeNull();
});

test('bulk edit requires at least one field', function () {
    $entry = bulkEntry($this->cloudflare);

    $this->from('/entries')
        ->patch('/entries/bulk', ['ids' => [$entry->id], 'set' => []])
        ->assertRedirect('/entries')
        ->assertSessionHasErrors('set');
});

test('bulk delete removes entries from their providers', function () {
    $pushed = bulkEntry($this->cloudflare, ['name' => 'a.example.com']);
    $localOnly = DnsEntry::factory()->create(['name' => 'local.example.com', 'type' => 'A', 'content' => '10.0.0.5']);

    $this->delete('/entries/bulk', ['ids' => [$pushed->id, $localOnly->id]])
        ->assertRedirect()
        ->assertSessionHas('success', fn ($message) => str_contains($message, 'Deleting 2 entries'));

    expect($pushed->syncStates()->sole()->sync_status)->toBe(SyncStatus::Deleting)
        ->and(DnsEntry::whereKey($localOnly->id)->exists())->toBeFalse();

    Queue::assertPushed(DeleteEntryFromProvider::class, 1);
});

test('bulk actions require the manage-entries permission', function () {
    $this->actingAs(User::factory()->withRoles(Role::ProvidersManager)->create());

    $entry = DnsEntry::factory()->create(['type' => 'A', 'content' => '10.0.0.1']);

    $this->post('/entries/bulk/sync', ['ids' => [$entry->id]])->assertForbidden();
    $this->post('/entries/bulk/providers', ['ids' => [$entry->id], 'providers' => []])->assertForbidden();
    $this->patch('/entries/bulk', ['ids' => [$entry->id], 'set' => ['ttl' => 300]])->assertForbidden();
    $this->delete('/entries/bulk', ['ids' => [$entry->id]])->assertForbidden();
});
