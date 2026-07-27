<?php

declare(strict_types = 1);

use App\Models\User;
use App\Models\DnsZone;
use App\Models\DnsEntry;
use App\Models\Provider;
use App\Models\ZoneUser;
use App\Enums\SyncStatus;
use App\Enums\HealthStatus;
use App\Models\ZoneProvider;
use App\Jobs\CheckProviderHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as OidcSocialiteUser;

// ── Model activities ────────────────────────────────────────────────────────

test('creating and updating an entry over http logs entries activities stamped with the zone', function () {
    Queue::fake();

    $admin = User::factory()->create();
    $this->actingAs($admin);

    $zone = DnsZone::factory()->create(['name' => 'example.com']);

    $this->post('/entries', [
        'dns_zone_id' => $zone->id,
        'name' => 'app',
        'type' => 'A',
        'content' => '10.0.0.1',
        'proxied' => false,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $entry = DnsEntry::sole();

    $created = Activity::query()->where('log_name', 'entries')->where('event', 'created')->sole();

    expect($created->causer_type)->toBe('user')
        ->and($created->causer_id)->toEqual($admin->id)
        ->and($created->subject_type)->toBe('entry')
        ->and($created->subject_id)->toEqual($entry->id)
        ->and(data_get($created->attribute_changes, 'attributes.name'))->toBe('app')
        ->and(data_get($created->attribute_changes, 'attributes.content'))->toBe('10.0.0.1')
        // The zone stamp keeps zone-scoped queries working after deletion.
        ->and($created->properties->get('dns_zone_id'))->toBe($zone->id)
        ->and($created->properties->get('zone'))->toBe('example.com');

    $this->put("/entries/{$entry->id}", [
        'name' => 'app',
        'type' => 'A',
        'content' => '10.0.0.2',
        'proxied' => false,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $updated = Activity::query()->where('log_name', 'entries')->where('event', 'updated')->sole();

    expect($updated->causer_type)->toBe('user')
        ->and($updated->causer_id)->toEqual($admin->id)
        ->and(data_get($updated->attribute_changes, 'attributes.content'))->toBe('10.0.0.2')
        ->and(data_get($updated->attribute_changes, 'old.content'))->toBe('10.0.0.1')
        ->and($updated->properties->get('dns_zone_id'))->toBe($zone->id);
});

test('the data endpoint serializes the attribute diff into the changes contract shape', function () {
    $admin = User::factory()->create();
    $this->actingAs($admin);

    $entry = DnsEntry::factory()->create(['content' => '10.0.0.1']);
    $entry->update(['content' => '10.0.0.2']);

    $this->getJson("/activity/data?subject_type=entry&subject_id={$entry->id}&event=updated")
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'logName', 'event', 'description', 'causer', 'subjectType', 'subjectId', 'subjectLabel', 'changes', 'createdAt']],
            'meta' => ['currentPage', 'lastPage', 'perPage', 'total'],
        ])
        ->assertJsonPath('data.0.logName', 'entries')
        ->assertJsonPath('data.0.event', 'updated')
        ->assertJsonPath('data.0.subjectType', 'entry')
        ->assertJsonPath('data.0.subjectId', $entry->id)
        ->assertJsonPath('data.0.subjectLabel', $entry->name)
        ->assertJsonPath('data.0.causer.id', $admin->id)
        ->assertJsonPath('data.0.causer.name', $admin->name)
        ->assertJsonPath('data.0.changes.attributes.content', '10.0.0.2')
        ->assertJsonPath('data.0.changes.old.content', '10.0.0.1');
});

test('changing a user\'s roles logs a users activity with the old and new roles', function () {
    $admin = User::factory()->create();
    $user = User::factory()->superViewer()->create();

    $this->actingAs($admin)
        ->put("/users/{$user->id}", ['roles' => ['super-viewer', 'user-admin']])
        ->assertRedirect()->assertSessionHasNoErrors();

    $activity = Activity::query()->where('log_name', 'users')->where('event', 'updated')->sole();

    expect($activity->causer_type)->toBe('user')
        ->and($activity->causer_id)->toEqual($admin->id)
        ->and($activity->subject_type)->toBe('user')
        ->and($activity->subject_id)->toEqual($user->id)
        ->and(data_get($activity->attribute_changes, 'attributes.roles'))->toEqualCanonicalizing(['super-viewer', 'user-admin'])
        ->and(data_get($activity->attribute_changes, 'old.roles'))->toBe(['super-viewer']);
});

// ── Provider config changes never leak secrets ──────────────────────────────

test('provider credential updates log the change without persisting any secret value', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    $provider = Provider::factory()->cloudflare()->create([
        'config' => ['api_token' => 'original-secret-token'],
    ]);

    $this->put("/providers/{$provider->id}", [
        'name' => $provider->name,
        'type' => 'cloudflare',
        'enabled' => true,
        'managed_record_types' => $provider->managed_record_types,
        'config' => ['api_token' => 'brand-new-secret-token'],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $activity = Activity::query()
        ->where('log_name', 'providers')
        ->where('description', 'updated connection settings')
        ->sole();

    expect($activity->event)->toBe('updated')
        ->and($activity->subject_type)->toBe('provider')
        ->and($activity->subject_id)->toEqual($provider->id)
        ->and($activity->properties->get('config_changed'))->toBeTrue();

    $rawTable = json_encode(DB::table('activity_log')->get());

    expect($rawTable)->not->toContain('brand-new-secret-token')
        ->not->toContain('original-secret-token');
});

// ── Background jobs stay silent ─────────────────────────────────────────────

test('provider health checks write zero activity rows', function () {
    $healthy = Provider::factory()->cloudflare()->create([
        'config' => ['api_token' => 'tok-ok'],
    ]);
    $failing = Provider::factory()->cloudflare()->create([
        'config' => ['api_token' => 'tok-bad'],
    ]);

    Http::fake(function ($request) {
        if ($request->header('Authorization')[0] === 'Bearer tok-ok') {
            return Http::response([
                'success' => true, 'errors' => [], 'messages' => [],
                'result' => [], 'result_info' => ['total_count' => 1],
            ]);
        }

        return Http::response([
            'success' => false, 'errors' => [['code' => 9109, 'message' => 'Invalid access token']], 'result' => null,
        ], 401);
    });

    Activity::query()->delete();

    (new CheckProviderHealth($healthy->id))->handle();
    (new CheckProviderHealth($failing->id))->handle();

    expect($healthy->fresh()->health_status)->toBe(HealthStatus::Ok)
        ->and($failing->fresh()->health_status)->toBe(HealthStatus::Error)
        ->and(Activity::query()->count())->toBe(0);
});

// ── Auth events ─────────────────────────────────────────────────────────────

test('oidc login logs an auth activity with the logged-in user as causer and subject', function () {
    Socialite::shouldReceive('driver->user')->andReturn((new OidcSocialiteUser())->map([
        'id' => 'sub-1',
        'name' => 'Jane Doe',
        'nickname' => 'jane',
        'email' => 'jane@example.com',
    ]));

    $this->get('/auth/callback?code=abc&state=xyz')->assertRedirect(route('dashboard'));

    $user = User::sole();
    $activity = Activity::query()->where('log_name', 'auth')->where('event', 'login')->sole();

    expect($activity->causer_type)->toBe('user')
        ->and($activity->causer_id)->toEqual($user->id)
        ->and($activity->subject_type)->toBe('user')
        ->and($activity->subject_id)->toEqual($user->id);
});

// ── Deferred deletions ──────────────────────────────────────────────────────

test('deferred entry deletions log delete-requested with the acting user as causer', function () {
    Queue::fake();

    $admin = User::factory()->create();
    $this->actingAs($admin);

    $attachment = ZoneProvider::factory()->cloudflare()->create();

    $makePushed = function (string $externalId) use ($attachment) {
        $entry = DnsEntry::factory()->create(['dns_zone_id' => $attachment->dns_zone_id]);
        $entry->syncStates()->create([
            'zone_provider_id' => $attachment->id,
            'sync_status' => SyncStatus::Synced,
            'external_id' => $externalId,
        ]);

        return $entry;
    };

    $single = $makePushed('cf-1');
    $bulk = $makePushed('cf-2');

    $this->delete("/entries/{$single->id}")->assertRedirect();
    $this->delete('/entries/bulk', ['ids' => [$bulk->id]])->assertRedirect();

    // Deletion is deferred to queued jobs — both rows still exist.
    expect(DnsEntry::find($single->id))->not->toBeNull()
        ->and(DnsEntry::find($bulk->id))->not->toBeNull();

    $activities = Activity::query()->where('log_name', 'entries')->where('event', 'delete-requested')->get();

    expect($activities)->toHaveCount(2)
        ->and($activities->pluck('subject_id')->map(fn ($id) => (int) $id)->all())->toEqualCanonicalizing([$single->id, $bulk->id])
        ->and($activities->pluck('causer_id')->unique()->all())->toEqual([$admin->id])
        ->and($activities->pluck('subject_type')->unique()->all())->toEqual(['entry']);
});

// ── Bulk provider reassignment ──────────────────────────────────────────────

test('bulk provider reassignment logs providers-changed with the assigned provider names', function () {
    Queue::fake();

    $admin = User::factory()->create();
    $this->actingAs($admin);

    $zone = DnsZone::factory()->create(['name' => 'example.com']);
    $cloudflare = ZoneProvider::factory()->create([
        'dns_zone_id' => $zone->id,
        'provider_id' => Provider::factory()->cloudflare()->create(['name' => 'Cloudflare Prod'])->id,
        'config' => ['zone_id' => 'cf-zone-1'],
    ]);
    $pihole = ZoneProvider::factory()->create([
        'dns_zone_id' => $zone->id,
        'provider_id' => Provider::factory()->pihole()->create(['name' => 'Pi-hole Lab'])->id,
    ]);

    $entry = DnsEntry::factory()->create(['dns_zone_id' => $zone->id]);

    $this->post('/entries/bulk/providers', [
        'ids' => [$entry->id],
        'zone_providers' => [$cloudflare->id, $pihole->id],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $activity = Activity::query()->where('event', 'providers-changed')->sole();

    expect($activity->log_name)->toBe('entries')
        ->and($activity->causer_id)->toEqual($admin->id)
        ->and($activity->subject_type)->toBe('entry')
        ->and($activity->subject_id)->toEqual($entry->id)
        ->and($activity->properties->get('providers'))->toEqualCanonicalizing(['Cloudflare Prod', 'Pi-hole Lab']);

    // Custom properties surface inside changes.attributes on the data endpoint.
    $names = $this->getJson('/activity/data?event=providers-changed')
        ->assertOk()
        ->json('data.0.changes.attributes.providers');

    expect($names)->toEqualCanonicalizing(['Cloudflare Prod', 'Pi-hole Lab']);
});

// ── Authorization ───────────────────────────────────────────────────────────

test('the global activity log is limited to super admins and super viewers', function () {
    $userAdmin = User::factory()->userAdmin()->create();
    $zoneScoped = User::factory()->noRoles()->create();
    ZoneUser::factory()->admin()->create(['user_id' => $zoneScoped->id, 'dns_zone_id' => DnsZone::factory()->create()->id]);
    $superViewer = User::factory()->superViewer()->create();
    $admin = User::factory()->create();

    $this->actingAs($userAdmin)->get('/activity')->assertForbidden();
    $this->actingAs($userAdmin)->getJson('/activity/data')->assertForbidden();

    // Even a zone admin's grant does not open the GLOBAL trail.
    $this->actingAs($zoneScoped)->get('/activity')->assertForbidden();
    $this->actingAs($zoneScoped)->getJson('/activity/data')->assertForbidden();

    // Super Viewer is read-only everything — including the global trail.
    $this->actingAs($superViewer)->get('/activity')->assertOk();
    $this->actingAs($superViewer)->getJson('/activity/data')->assertOk();

    $this->actingAs($admin)->get('/activity')->assertOk()->assertInertia(
        fn ($page) => $page->component('activity')
            ->has('activities')
            ->has('filters')
            ->has('users')
            ->has('events')
    );
    $this->actingAs($admin)->getJson('/activity/data')->assertOk();
});

test('guests are redirected to login from the activity log routes', function () {
    $this->get('/activity')->assertRedirect('/login');
    $this->get('/activity/data')->assertRedirect('/login');
});

// ── Filters & pagination ────────────────────────────────────────────────────

test('subject filters narrow the data endpoint to one record\'s history', function () {
    $this->actingAs(User::factory()->create());

    $target = DnsEntry::factory()->create();
    DnsEntry::factory()->create();
    $target->update(['content' => '10.9.9.9']);

    $response = $this->getJson("/activity/data?subject_type=entry&subject_id={$target->id}")
        ->assertOk()
        ->assertJsonPath('meta.total', 2);

    expect(collect($response->json('data'))->pluck('subjectId')->unique()->all())->toBe([$target->id])
        ->and(collect($response->json('data'))->pluck('event')->all())->toEqualCanonicalizing(['created', 'updated']);
});

test('zone activities can be filtered by subject and are labelled with the zone name', function () {
    $this->actingAs(User::factory()->create());

    $zone = DnsZone::factory()->create(['name' => 'example.com']);
    DnsZone::factory()->create(['name' => 'other.dev']);
    $zone->update(['description' => 'Main zone']);

    $this->getJson("/activity/data?subject_type=zone&subject_id={$zone->id}")
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.subjectType', 'zone')
        ->assertJsonPath('data.0.subjectLabel', 'example.com');
});

test('the zone filter matches zone activities and zone-stamped entry activities even after deletion', function () {
    $this->actingAs(User::factory()->create());

    $zone = DnsZone::factory()->create(['name' => 'example.com']);
    $otherZone = DnsZone::factory()->create(['name' => 'other.dev']);

    $entry = DnsEntry::factory()->create(['dns_zone_id' => $zone->id]);
    DnsEntry::factory()->create(['dns_zone_id' => $otherZone->id]);

    // Inline delete (no remote copies) — the row disappears but its
    // activities keep the dns_zone_id stamp.
    $entry->delete();

    $response = $this->getJson("/activity/data?zone_id={$zone->id}")
        ->assertOk()
        ->assertJsonPath('meta.total', 3);

    $items = collect($response->json('data'));

    // Zone created + entry created + entry deleted; the other zone's
    // activities are excluded.
    expect($items->pluck('event')->all())->toEqualCanonicalizing(['created', 'created', 'deleted'])
        ->and($items->where('subjectType', 'zone')->pluck('subjectId')->all())->toBe([$zone->id])
        ->and($items->where('subjectType', 'entry')->pluck('subjectId')->unique()->all())->toBe([$entry->id]);
});

test('event and causer filters narrow the results', function () {
    $admin = User::factory()->create();
    $colleague = User::factory()->create();

    $this->actingAs($admin);
    $first = DnsEntry::factory()->create();

    $this->actingAs($colleague);
    DnsEntry::factory()->create();
    $first->update(['content' => '10.4.4.4']);

    $this->getJson('/activity/data?event=updated')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.event', 'updated')
        ->assertJsonPath('data.0.subjectId', $first->id);

    $this->getJson("/activity/data?causer_id={$admin->id}&log=entries")
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.causer.id', $admin->id)
        ->assertJsonPath('data.0.subjectId', $first->id);
});

test('pagination meta reflects per_page and page', function () {
    $this->actingAs(User::factory()->create());

    DnsEntry::factory()->count(5)->create();

    $this->getJson('/activity/data?subject_type=entry&per_page=2&page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.currentPage', 2)
        ->assertJsonPath('meta.lastPage', 3)
        ->assertJsonPath('meta.perPage', 2)
        ->assertJsonPath('meta.total', 5);
});

test('invalid filter values are rejected with 422', function () {
    $this->actingAs(User::factory()->create());

    $this->getJson('/activity/data?subject_type=banana')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('subject_type');

    $this->getJson('/activity/data?per_page=500')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
});
