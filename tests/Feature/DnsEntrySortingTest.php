<?php

use App\Models\DnsEntry;
use App\Models\DnsZone;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    $this->zone = DnsZone::factory()->create(['name' => 'example.com']);
});

function entryNamesOn($response): array
{
    return collect($response->viewData('page')['props']['entries']['data'])
        ->pluck('name')
        ->all();
}

function makeEntry(array $attributes, ?Carbon $updatedAt = null): DnsEntry
{
    $entry = DnsEntry::factory()->create($attributes + ['dns_zone_id' => test()->zone->id]);

    if ($updatedAt) {
        $entry->forceFill(['updated_at' => $updatedAt])->saveQuietly();
    }

    return $entry;
}

test('entries default to name ascending', function () {
    makeEntry(['name' => 'charlie']);
    makeEntry(['name' => 'alpha']);
    makeEntry(['name' => 'bravo']);

    $response = $this->get('/entries')->assertOk()->assertInertia(
        fn ($page) => $page
            ->component('entries/index')
            ->where('filters.sort', 'name')
            ->where('filters.direction', 'asc'),
    );

    expect(entryNamesOn($response))->toBe(['alpha', 'bravo', 'charlie']);
});

test('entries sort by name descending', function () {
    makeEntry(['name' => 'alpha']);
    makeEntry(['name' => 'charlie']);
    makeEntry(['name' => 'bravo']);

    $response = $this->get('/entries?sort=name&direction=desc')->assertOk();

    expect(entryNamesOn($response))->toBe(['charlie', 'bravo', 'alpha']);
});

test('entries sort by type with name tiebreak', function () {
    makeEntry(['name' => 'zeta', 'type' => 'A', 'content' => '10.0.0.1']);
    makeEntry(['name' => 'mail', 'type' => 'TXT', 'content' => 'v=spf1']);
    makeEntry(['name' => 'alpha', 'type' => 'A', 'content' => '10.0.0.2']);

    $response = $this->get('/entries?sort=type&direction=asc')->assertOk();

    expect(entryNamesOn($response))->toBe(['alpha', 'zeta', 'mail']);
});

test('entries sort by content', function () {
    makeEntry(['name' => 'a', 'content' => '10.0.0.30']);
    makeEntry(['name' => 'b', 'content' => '10.0.0.10']);
    makeEntry(['name' => 'c', 'content' => '10.0.0.20']);

    $response = $this->get('/entries?sort=content')->assertOk();

    expect(entryNamesOn($response))->toBe(['b', 'c', 'a']);
});

test('entries sort by zone name with name tiebreak', function () {
    $alpha = DnsZone::factory()->create(['name' => 'alpha.dev']);
    $zulu = DnsZone::factory()->create(['name' => 'zulu.dev']);

    makeEntry(['name' => 'bravo', 'dns_zone_id' => $zulu->id, 'content' => '10.0.0.1']);
    makeEntry(['name' => 'delta', 'dns_zone_id' => $alpha->id, 'content' => '10.0.0.2']);
    makeEntry(['name' => 'alpha', 'dns_zone_id' => $alpha->id, 'content' => '10.0.0.3']);

    $asc = $this->get('/entries?sort=zone&direction=asc')->assertOk();
    expect(entryNamesOn($asc))->toBe(['alpha', 'delta', 'bravo']);

    $desc = $this->get('/entries?sort=zone&direction=desc')->assertOk();
    expect(entryNamesOn($desc))->toBe(['bravo', 'alpha', 'delta']);
});

test('ttl sorting keeps automatic (null) ttl entries last in both directions', function () {
    makeEntry(['name' => 'auto', 'ttl' => null]);
    makeEntry(['name' => 'slow', 'ttl' => 3600]);
    makeEntry(['name' => 'fast', 'ttl' => 60]);

    $asc = $this->get('/entries?sort=ttl&direction=asc')->assertOk();
    expect(entryNamesOn($asc))->toBe(['fast', 'slow', 'auto']);

    $desc = $this->get('/entries?sort=ttl&direction=desc')->assertOk();
    expect(entryNamesOn($desc))->toBe(['slow', 'fast', 'auto']);
});

test('entries sort by last update', function () {
    makeEntry(['name' => 'oldest'], now()->subDays(3));
    makeEntry(['name' => 'newest'], now()->subHour());
    makeEntry(['name' => 'middle'], now()->subDay());

    $response = $this->get('/entries?sort=updated&direction=desc')->assertOk();

    expect(entryNamesOn($response))->toBe(['newest', 'middle', 'oldest']);
});

test('unknown sort values fall back to the defaults instead of erroring', function () {
    makeEntry(['name' => 'bravo']);
    makeEntry(['name' => 'alpha']);

    $response = $this->get('/entries?sort=providers;drop--&direction=sideways')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->where('filters.sort', 'name')
                ->where('filters.direction', 'asc'),
        );

    expect(entryNamesOn($response))->toBe(['alpha', 'bravo']);
});

test('the zone filter narrows entries to one zone', function () {
    $other = DnsZone::factory()->create(['name' => 'other.dev']);

    makeEntry(['name' => 'kept']);
    makeEntry(['name' => 'excluded', 'dns_zone_id' => $other->id]);

    $response = $this->get("/entries?zone={$this->zone->id}")->assertOk()->assertInertia(
        fn ($page) => $page->where('filters.zone', (string) $this->zone->id),
    );

    expect(entryNamesOn($response))->toBe(['kept']);
});

test('search matches the zone name as well as name and content', function () {
    $other = DnsZone::factory()->create(['name' => 'homelab.dev']);

    makeEntry(['name' => 'www']);
    makeEntry(['name' => 'nas', 'dns_zone_id' => $other->id]);

    $response = $this->get('/entries?search=homelab')->assertOk();

    expect(entryNamesOn($response))->toBe(['nas']);
});

test('sorting combines with filters and pagination preserves both', function () {
    makeEntry(['name' => 'a', 'type' => 'A', 'content' => '10.0.0.1']);
    makeEntry(['name' => 'b', 'type' => 'TXT', 'content' => 'v=spf1 include:a']);

    foreach (range(1, 26) as $i) {
        makeEntry(['name' => sprintf('bulk-%02d', $i), 'type' => 'A', 'content' => "10.1.0.{$i}"]);
    }

    // Filter to A records sorted by content desc — the TXT record is excluded.
    $response = $this->get('/entries?type=A&sort=content&direction=desc')->assertOk();
    $names = entryNamesOn($response);

    expect($names)->not->toContain('b')
        ->and(count($names))->toBe(25);

    // Page 2 keeps the sort + filter via withQueryString links.
    $props = $response->viewData('page')['props']['entries'];
    $nextLink = collect($props['links'])->firstWhere('label', '2');

    expect($nextLink['url'])->toContain('sort=content')
        ->and($nextLink['url'])->toContain('direction=desc')
        ->and($nextLink['url'])->toContain('type=A');
});
