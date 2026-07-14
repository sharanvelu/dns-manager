<?php

use App\Enums\HealthStatus;
use App\Enums\Role;
use App\Enums\SyncStatus;
use App\Jobs\CheckProviderHealth;
use App\Models\DnsEntry;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as OidcSocialiteUser;
use Spatie\Activitylog\Models\Activity;

// ── Model activities ────────────────────────────────────────────────────────

test('creating and updating an entry over http logs entries activities with the acting user as causer', function () {
    Queue::fake();

    $admin = User::factory()->create();
    $this->actingAs($admin);

    $this->post('/entries', [
        'name' => 'app.example.com',
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
        ->and(data_get($created->attribute_changes, 'attributes.name'))->toBe('app.example.com')
        ->and(data_get($created->attribute_changes, 'attributes.content'))->toBe('10.0.0.1');

    $this->put("/entries/{$entry->id}", [
        'name' => 'app.example.com',
        'type' => 'A',
        'content' => '10.0.0.2',
        'proxied' => false,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $updated = Activity::query()->where('log_name', 'entries')->where('event', 'updated')->sole();

    expect($updated->causer_type)->toBe('user')
        ->and($updated->causer_id)->toEqual($admin->id)
        ->and(data_get($updated->attribute_changes, 'attributes.content'))->toBe('10.0.0.2')
        ->and(data_get($updated->attribute_changes, 'old.content'))->toBe('10.0.0.1');
});

test('the data endpoint serializes the attribute diff into the changes contract shape', function () {
    $admin = User::factory()->create();
    $this->actingAs($admin);

    $entry = DnsEntry::factory()->create(['content' => '10.0.0.1']);
    $entry->update(['content' => '10.0.0.2']);

    $this->getJson("/settings/activity/data?subject_type=entry&subject_id={$entry->id}&event=updated")
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
    $user = User::factory()->viewer()->create();

    $this->actingAs($admin)
        ->put("/settings/users/{$user->id}", ['roles' => ['dns-manager', 'providers-manager']])
        ->assertRedirect()->assertSessionHasNoErrors();

    $activity = Activity::query()->where('log_name', 'users')->where('event', 'updated')->sole();

    expect($activity->causer_type)->toBe('user')
        ->and($activity->causer_id)->toEqual($admin->id)
        ->and($activity->subject_type)->toBe('user')
        ->and($activity->subject_id)->toEqual($user->id)
        ->and(data_get($activity->attribute_changes, 'attributes.roles'))->toEqualCanonicalizing(['dns-manager', 'providers-manager'])
        ->and(data_get($activity->attribute_changes, 'old.roles'))->toBe(['viewer']);
});

// ── Provider config changes never leak secrets ──────────────────────────────

test('provider credential updates log the change without persisting any secret value', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    $provider = Provider::factory()->cloudflare()->create([
        'config' => ['api_token' => 'original-secret-token', 'zone_id' => 'zone-1'],
    ]);

    $this->put("/providers/{$provider->id}", [
        'name' => $provider->name,
        'type' => 'cloudflare',
        'enabled' => true,
        'managed_record_types' => $provider->managed_record_types,
        'config' => ['api_token' => 'brand-new-secret-token', 'zone_id' => 'zone-1'],
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
        'config' => ['api_token' => 'tok', 'zone_id' => 'zone-ok'],
    ]);
    $failing = Provider::factory()->cloudflare()->create([
        'config' => ['api_token' => 'tok', 'zone_id' => 'zone-bad'],
    ]);

    Http::fake([
        'api.cloudflare.com/client/v4/zones/zone-ok' => Http::response([
            'success' => true, 'errors' => [], 'messages' => [],
            'result' => ['name' => 'example.com', 'status' => 'active'],
        ]),
        'api.cloudflare.com/*' => Http::response([
            'success' => false, 'errors' => [['code' => 9109, 'message' => 'Invalid access token']], 'result' => null,
        ], 401),
    ]);

    Activity::query()->delete();

    (new CheckProviderHealth($healthy->id))->handle();
    (new CheckProviderHealth($failing->id))->handle();

    expect($healthy->fresh()->health_status)->toBe(HealthStatus::Ok)
        ->and($failing->fresh()->health_status)->toBe(HealthStatus::Error)
        ->and(Activity::query()->count())->toBe(0);
});

// ── Auth events ─────────────────────────────────────────────────────────────

test('oidc login logs an auth activity with the logged-in user as causer and subject', function () {
    Socialite::shouldReceive('driver->user')->andReturn((new OidcSocialiteUser)->map([
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

    $provider = Provider::factory()->cloudflare()->create();

    $single = DnsEntry::factory()->create();
    $single->syncStates()->create(['provider_id' => $provider->id, 'sync_status' => SyncStatus::Synced, 'external_id' => 'cf-1']);

    $bulk = DnsEntry::factory()->create();
    $bulk->syncStates()->create(['provider_id' => $provider->id, 'sync_status' => SyncStatus::Synced, 'external_id' => 'cf-2']);

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

    $cloudflare = Provider::factory()->cloudflare()->create(['name' => 'Cloudflare Prod']);
    $pihole = Provider::factory()->pihole()->create(['name' => 'Pi-hole Lab']);
    $entry = DnsEntry::factory()->create();

    $this->post('/entries/bulk/providers', [
        'ids' => [$entry->id],
        'providers' => [$cloudflare->id, $pihole->id],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $activity = Activity::query()->where('event', 'providers-changed')->sole();

    expect($activity->log_name)->toBe('entries')
        ->and($activity->causer_id)->toEqual($admin->id)
        ->and($activity->subject_type)->toBe('entry')
        ->and($activity->subject_id)->toEqual($entry->id)
        ->and($activity->properties->get('providers'))->toEqualCanonicalizing(['Cloudflare Prod', 'Pi-hole Lab']);

    // Custom properties surface inside changes.attributes on the data endpoint.
    $names = $this->getJson('/settings/activity/data?event=providers-changed')
        ->assertOk()
        ->json('data.0.changes.attributes.providers');

    expect($names)->toEqualCanonicalizing(['Cloudflare Prod', 'Pi-hole Lab']);
});

// ── Authorization ───────────────────────────────────────────────────────────

test('only super admins can view the activity log', function () {
    $viewer = User::factory()->viewer()->create();
    $manager = User::factory()->withRoles(Role::DnsManager)->create();
    $admin = User::factory()->create();

    $this->actingAs($viewer)->get('/settings/activity')->assertForbidden();
    $this->actingAs($viewer)->getJson('/settings/activity/data')->assertForbidden();

    $this->actingAs($manager)->get('/settings/activity')->assertForbidden();
    $this->actingAs($manager)->getJson('/settings/activity/data')->assertForbidden();

    $this->actingAs($admin)->get('/settings/activity')->assertOk()->assertInertia(
        fn ($page) => $page->component('settings/activity')
            ->has('activities')
            ->has('filters')
            ->has('users')
            ->has('events')
    );
    $this->actingAs($admin)->getJson('/settings/activity/data')->assertOk();
});

test('guests are redirected to login from the activity log routes', function () {
    $this->get('/settings/activity')->assertRedirect('/login');
    $this->get('/settings/activity/data')->assertRedirect('/login');
});

// ── Filters & pagination ────────────────────────────────────────────────────

test('subject filters narrow the data endpoint to one record\'s history', function () {
    $this->actingAs(User::factory()->create());

    $target = DnsEntry::factory()->create();
    DnsEntry::factory()->create();
    $target->update(['content' => '10.9.9.9']);

    $response = $this->getJson("/settings/activity/data?subject_type=entry&subject_id={$target->id}")
        ->assertOk()
        ->assertJsonPath('meta.total', 2);

    expect(collect($response->json('data'))->pluck('subjectId')->unique()->all())->toBe([$target->id])
        ->and(collect($response->json('data'))->pluck('event')->all())->toEqualCanonicalizing(['created', 'updated']);
});

test('event and causer filters narrow the results', function () {
    $admin = User::factory()->create();
    $colleague = User::factory()->create();

    $this->actingAs($admin);
    $first = DnsEntry::factory()->create();

    $this->actingAs($colleague);
    DnsEntry::factory()->create();
    $first->update(['content' => '10.4.4.4']);

    $this->getJson('/settings/activity/data?event=updated')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.event', 'updated')
        ->assertJsonPath('data.0.subjectId', $first->id);

    $this->getJson("/settings/activity/data?causer_id={$admin->id}&log=entries")
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.causer.id', $admin->id)
        ->assertJsonPath('data.0.subjectId', $first->id);
});

test('pagination meta reflects per_page and page', function () {
    $this->actingAs(User::factory()->create());

    DnsEntry::factory()->count(5)->create();

    $this->getJson('/settings/activity/data?subject_type=entry&per_page=2&page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.currentPage', 2)
        ->assertJsonPath('meta.lastPage', 3)
        ->assertJsonPath('meta.perPage', 2)
        ->assertJsonPath('meta.total', 5);
});

test('invalid filter values are rejected with 422', function () {
    $this->actingAs(User::factory()->create());

    $this->getJson('/settings/activity/data?subject_type=banana')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('subject_type');

    $this->getJson('/settings/activity/data?per_page=500')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
});
