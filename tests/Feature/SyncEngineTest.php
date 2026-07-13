<?php

use App\Enums\SyncStatus;
use App\Jobs\CheckProviderDrift;
use App\Jobs\DeleteEntryFromProvider;
use App\Jobs\SyncEntryToProvider;
use App\Models\DnsEntry;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

function cloudflareProvider(array $attributes = []): Provider
{
    return Provider::factory()->cloudflare()->create($attributes + [
        'managed_record_types' => ['A', 'AAAA', 'CNAME', 'MX'],
    ]);
}

test('creating an entry dispatches sync jobs only to compatible enabled providers', function () {
    Queue::fake();

    $cloudflare = cloudflareProvider();
    $pihole = Provider::factory()->pihole()->create(['managed_record_types' => ['A', 'CNAME']]);
    $disabled = cloudflareProvider(['enabled' => false]);
    $aOnly = Provider::factory()->pihole()->create(['managed_record_types' => ['A']]);

    $this->post('/entries', [
        'name' => 'app.example.com',
        'type' => 'CNAME',
        'content' => 'target.example.com',
        'proxied' => false,
    ])->assertRedirect();

    $entry = DnsEntry::sole();

    expect($entry->syncStates()->pluck('provider_id')->all())
        ->toEqualCanonicalizing([$cloudflare->id, $pihole->id]);

    Queue::assertPushed(SyncEntryToProvider::class, 2);
    Queue::assertNotPushed(SyncEntryToProvider::class, fn ($job) => $job->providerId === $disabled->id);
    Queue::assertNotPushed(SyncEntryToProvider::class, fn ($job) => $job->providerId === $aOnly->id);
});

test('an explicit provider selection limits where the entry syncs', function () {
    Queue::fake();

    $cloudflare = cloudflareProvider();
    $pihole = Provider::factory()->pihole()->create(['managed_record_types' => ['A', 'CNAME']]);

    $this->post('/entries', [
        'name' => 'internal.example.com',
        'type' => 'A',
        'content' => '10.0.0.9',
        'proxied' => false,
        'providers' => [$pihole->id],
    ])->assertRedirect();

    $entry = DnsEntry::sole();

    expect($entry->syncStates()->pluck('provider_id')->all())->toBe([$pihole->id]);
    Queue::assertPushed(SyncEntryToProvider::class, 1);
    Queue::assertNotPushed(SyncEntryToProvider::class, fn ($job) => $job->providerId === $cloudflare->id);
});

test('deselecting a provider on update removes the record from it', function () {
    Queue::fake();

    $cloudflare = cloudflareProvider();
    $pihole = Provider::factory()->pihole()->create(['managed_record_types' => ['A', 'CNAME']]);

    $entry = DnsEntry::factory()->create(['type' => 'A', 'content' => '10.0.0.9']);
    $entry->syncStates()->create(['provider_id' => $cloudflare->id, 'sync_status' => SyncStatus::Synced, 'external_id' => 'cf-1']);
    $entry->syncStates()->create(['provider_id' => $pihole->id, 'sync_status' => SyncStatus::Synced, 'external_id' => "10.0.0.9 {$entry->name}"]);

    $this->put("/entries/{$entry->id}", [
        'name' => $entry->name,
        'type' => 'A',
        'content' => '10.0.0.9',
        'proxied' => false,
        'providers' => [$pihole->id],
    ])->assertRedirect();

    expect($entry->syncStates()->where('provider_id', $cloudflare->id)->sole()->sync_status)->toBe(SyncStatus::Deleting);
    Queue::assertPushed(DeleteEntryFromProvider::class, 1);
    Queue::assertPushed(SyncEntryToProvider::class, fn ($job) => $job->providerId === $pihole->id);
});

test('manual re-sync keeps the existing provider assignment', function () {
    Queue::fake();

    $assigned = cloudflareProvider();
    $unassigned = Provider::factory()->pihole()->create(['managed_record_types' => ['A', 'CNAME']]);

    $entry = DnsEntry::factory()->create(['type' => 'A']);
    $entry->syncStates()->create(['provider_id' => $assigned->id, 'sync_status' => SyncStatus::Synced, 'external_id' => 'cf-1']);

    $this->post("/entries/{$entry->id}/sync")->assertRedirect();

    Queue::assertPushed(SyncEntryToProvider::class, 1);
    Queue::assertNotPushed(SyncEntryToProvider::class, fn ($job) => $job->providerId === $unassigned->id);
    expect($entry->syncStates()->count())->toBe(1);
});

test('states of disabled providers are paused, not deleted, on edit', function () {
    Queue::fake();

    $active = cloudflareProvider();
    $paused = Provider::factory()->pihole()->create(['enabled' => false, 'managed_record_types' => ['A', 'CNAME']]);

    $entry = DnsEntry::factory()->create(['type' => 'A', 'content' => '10.0.0.9']);
    $entry->syncStates()->create(['provider_id' => $active->id, 'sync_status' => SyncStatus::Synced, 'external_id' => 'cf-1']);
    $entry->syncStates()->create(['provider_id' => $paused->id, 'sync_status' => SyncStatus::Synced, 'external_id' => "10.0.0.9 {$entry->name}"]);

    $this->put("/entries/{$entry->id}", [
        'name' => $entry->name,
        'type' => 'A',
        'content' => '10.0.0.10',
        'proxied' => false,
        'providers' => [$active->id],
    ])->assertRedirect();

    Queue::assertNotPushed(DeleteEntryFromProvider::class);
    expect($entry->syncStates()->where('provider_id', $paused->id)->sole()->sync_status)->toBe(SyncStatus::Synced);
});

test('sync job pushes to cloudflare and stores the external id', function () {
    Http::fake([
        'api.cloudflare.com/client/v4/zones/*/dns_records' => Http::response([
            'success' => true, 'errors' => [], 'messages' => [],
            'result' => ['id' => 'cf-record-1'],
        ]),
    ]);

    $provider = cloudflareProvider();
    $entry = DnsEntry::factory()->create();
    $entry->syncStates()->create(['provider_id' => $provider->id, 'sync_status' => SyncStatus::Pending]);

    (new SyncEntryToProvider($entry->id, $provider->id))->handle();

    $state = $entry->syncStates()->sole();
    expect($state->external_id)->toBe('cf-record-1')
        ->and($state->sync_status)->toBe(SyncStatus::Synced)
        ->and($state->last_synced_at)->not->toBeNull();

    $this->assertDatabaseHas('sync_logs', ['provider_id' => $provider->id, 'action' => 'push', 'status' => 'success']);
});

test('failed sync marks the state as error with the message', function () {
    $provider = cloudflareProvider();
    $entry = DnsEntry::factory()->create();
    $entry->syncStates()->create(['provider_id' => $provider->id, 'sync_status' => SyncStatus::Pending]);

    $job = new SyncEntryToProvider($entry->id, $provider->id);
    $job->failed(new RuntimeException('Cloudflare: create failed with HTTP 400: [81057] Record already exists.'));

    $state = $entry->syncStates()->sole();
    expect($state->sync_status)->toBe(SyncStatus::Error)
        ->and($state->last_error)->toContain('81057');
});

test('deleting an entry removes it remotely then locally', function () {
    Http::fake([
        'api.cloudflare.com/client/v4/zones/*/dns_records/cf-record-1' => Http::response([
            'success' => true, 'errors' => [], 'messages' => [], 'result' => ['id' => 'cf-record-1'],
        ]),
    ]);

    $provider = cloudflareProvider();
    $entry = DnsEntry::factory()->create();
    $state = $entry->syncStates()->create([
        'provider_id' => $provider->id,
        'sync_status' => SyncStatus::Synced,
        'external_id' => 'cf-record-1',
    ]);

    Queue::fake();
    $this->delete("/entries/{$entry->id}")->assertRedirect();

    expect($entry->fresh()->syncStates()->sole()->sync_status)->toBe(SyncStatus::Deleting);
    Queue::assertPushed(DeleteEntryFromProvider::class, 1);

    (new DeleteEntryFromProvider($state->id))->handle();

    expect(DnsEntry::find($entry->id))->toBeNull();
    Http::assertSent(fn ($request) => $request->method() === 'DELETE');
});

test('entries without remote state are deleted immediately', function () {
    $entry = DnsEntry::factory()->create();

    $this->delete("/entries/{$entry->id}")->assertRedirect();

    expect(DnsEntry::find($entry->id))->toBeNull();
});

test('drift check marks missing and differing records as drifted', function () {
    $provider = cloudflareProvider();

    $synced = DnsEntry::factory()->create(['name' => 'ok.example.com', 'content' => '10.0.0.1']);
    $changed = DnsEntry::factory()->create(['name' => 'changed.example.com', 'content' => '10.0.0.2']);
    $missing = DnsEntry::factory()->create(['name' => 'gone.example.com', 'content' => '10.0.0.3']);

    foreach ([[$synced, 'cf-1'], [$changed, 'cf-2'], [$missing, 'cf-3']] as [$entry, $externalId]) {
        $entry->syncStates()->create([
            'provider_id' => $provider->id,
            'sync_status' => SyncStatus::Synced,
            'external_id' => $externalId,
        ]);
    }

    $record = fn (string $id, string $name, string $content) => [
        'id' => $id, 'type' => 'A', 'name' => $name, 'content' => $content,
        'ttl' => 1, 'proxied' => false,
    ];

    Http::fake([
        'api.cloudflare.com/client/v4/zones/*/dns_records?*' => Http::response([
            'success' => true, 'errors' => [], 'messages' => [],
            'result' => [
                $record('cf-1', 'ok.example.com', '10.0.0.1'),
                $record('cf-2', 'changed.example.com', '192.168.99.99'),
            ],
            'result_info' => ['page' => 1, 'total_pages' => 1],
        ]),
    ]);

    (new CheckProviderDrift($provider->id))->handle();

    expect($synced->syncStates()->sole()->sync_status)->toBe(SyncStatus::Synced)
        ->and($changed->syncStates()->sole()->sync_status)->toBe(SyncStatus::Drifted)
        ->and($missing->syncStates()->sole()->sync_status)->toBe(SyncStatus::Drifted)
        ->and($provider->fresh()->health_status->value)->toBe('ok');
});

test('drift check marks provider unhealthy when the API is unreachable', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false, 'errors' => [['code' => 9109, 'message' => 'Invalid access token']], 'result' => null], 401)]);

    $provider = cloudflareProvider();

    (new CheckProviderDrift($provider->id))->handle();

    expect($provider->fresh()->health_status->value)->toBe('error')
        ->and($provider->fresh()->health_message)->toContain('9109');
});

test('provider config is stored encrypted at rest', function () {
    $provider = Provider::factory()->cloudflare()->create([
        'config' => ['api_token' => 'super-secret-token', 'zone_id' => 'zone123'],
    ]);

    $raw = DB::table('providers')->where('id', $provider->id)->value('config');

    expect($raw)->not->toContain('super-secret-token')
        ->and($provider->fresh()->config['api_token'])->toBe('super-secret-token');
});

test('updating an entry type reassigns providers and deletes stale remote records', function () {
    Queue::fake();

    $pihole = Provider::factory()->pihole()->create(['managed_record_types' => ['A']]);
    $cloudflare = cloudflareProvider(['managed_record_types' => ['A', 'MX']]);

    $entry = DnsEntry::factory()->create(['type' => 'A', 'content' => '10.1.1.1']);
    $entry->syncStates()->create([
        'provider_id' => $pihole->id,
        'sync_status' => SyncStatus::Synced,
        'external_id' => '10.1.1.1 '.$entry->name,
    ]);
    $entry->syncStates()->create([
        'provider_id' => $cloudflare->id,
        'sync_status' => SyncStatus::Synced,
        'external_id' => 'cf-1',
    ]);

    $this->put("/entries/{$entry->id}", [
        'name' => $entry->name,
        'type' => 'MX',
        'content' => 'mail.example.com',
        'priority' => 10,
        'proxied' => false,
    ])->assertRedirect();

    // Pi-hole no longer applies (MX unsupported): remote delete queued.
    Queue::assertPushed(DeleteEntryFromProvider::class, 1);
    Queue::assertPushed(SyncEntryToProvider::class, fn ($job) => $job->providerId === $cloudflare->id);

    expect($entry->syncStates()->where('provider_id', $pihole->id)->sole()->sync_status)
        ->toBe(SyncStatus::Deleting);
});
