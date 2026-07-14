<?php

use App\Models\DnsEntry;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

function entryNamesOn($response): array
{
    return collect($response->viewData('page')['props']['entries']['data'])
        ->pluck('name')
        ->all();
}

function makeEntry(array $attributes, ?Carbon $updatedAt = null): DnsEntry
{
    $entry = DnsEntry::factory()->create($attributes);

    if ($updatedAt) {
        $entry->forceFill(['updated_at' => $updatedAt])->saveQuietly();
    }

    return $entry;
}

test('entries default to name ascending', function () {
    makeEntry(['name' => 'charlie.example.com']);
    makeEntry(['name' => 'alpha.example.com']);
    makeEntry(['name' => 'bravo.example.com']);

    $response = $this->get('/entries')->assertOk()->assertInertia(
        fn ($page) => $page
            ->component('entries/index')
            ->where('filters.sort', 'name')
            ->where('filters.direction', 'asc'),
    );

    expect(entryNamesOn($response))->toBe(['alpha.example.com', 'bravo.example.com', 'charlie.example.com']);
});

test('entries sort by name descending', function () {
    makeEntry(['name' => 'alpha.example.com']);
    makeEntry(['name' => 'charlie.example.com']);
    makeEntry(['name' => 'bravo.example.com']);

    $response = $this->get('/entries?sort=name&direction=desc')->assertOk();

    expect(entryNamesOn($response))->toBe(['charlie.example.com', 'bravo.example.com', 'alpha.example.com']);
});

test('entries sort by type with name tiebreak', function () {
    makeEntry(['name' => 'zeta.example.com', 'type' => 'A', 'content' => '10.0.0.1']);
    makeEntry(['name' => 'mail.example.com', 'type' => 'TXT', 'content' => 'v=spf1']);
    makeEntry(['name' => 'alpha.example.com', 'type' => 'A', 'content' => '10.0.0.2']);

    $response = $this->get('/entries?sort=type&direction=asc')->assertOk();

    expect(entryNamesOn($response))->toBe(['alpha.example.com', 'zeta.example.com', 'mail.example.com']);
});

test('entries sort by content', function () {
    makeEntry(['name' => 'a.example.com', 'content' => '10.0.0.30']);
    makeEntry(['name' => 'b.example.com', 'content' => '10.0.0.10']);
    makeEntry(['name' => 'c.example.com', 'content' => '10.0.0.20']);

    $response = $this->get('/entries?sort=content')->assertOk();

    expect(entryNamesOn($response))->toBe(['b.example.com', 'c.example.com', 'a.example.com']);
});

test('ttl sorting keeps automatic (null) ttl entries last in both directions', function () {
    makeEntry(['name' => 'auto.example.com', 'ttl' => null]);
    makeEntry(['name' => 'slow.example.com', 'ttl' => 3600]);
    makeEntry(['name' => 'fast.example.com', 'ttl' => 60]);

    $asc = $this->get('/entries?sort=ttl&direction=asc')->assertOk();
    expect(entryNamesOn($asc))->toBe(['fast.example.com', 'slow.example.com', 'auto.example.com']);

    $desc = $this->get('/entries?sort=ttl&direction=desc')->assertOk();
    expect(entryNamesOn($desc))->toBe(['slow.example.com', 'fast.example.com', 'auto.example.com']);
});

test('entries sort by last update', function () {
    makeEntry(['name' => 'oldest.example.com'], now()->subDays(3));
    makeEntry(['name' => 'newest.example.com'], now()->subHour());
    makeEntry(['name' => 'middle.example.com'], now()->subDay());

    $response = $this->get('/entries?sort=updated&direction=desc')->assertOk();

    expect(entryNamesOn($response))->toBe(['newest.example.com', 'middle.example.com', 'oldest.example.com']);
});

test('unknown sort values fall back to the defaults instead of erroring', function () {
    makeEntry(['name' => 'bravo.example.com']);
    makeEntry(['name' => 'alpha.example.com']);

    $response = $this->get('/entries?sort=providers;drop--&direction=sideways')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->where('filters.sort', 'name')
                ->where('filters.direction', 'asc'),
        );

    expect(entryNamesOn($response))->toBe(['alpha.example.com', 'bravo.example.com']);
});

test('sorting combines with filters and pagination preserves both', function () {
    makeEntry(['name' => 'a.example.com', 'type' => 'A', 'content' => '10.0.0.1']);
    makeEntry(['name' => 'b.example.com', 'type' => 'TXT', 'content' => 'v=spf1 include:a']);

    foreach (range(1, 26) as $i) {
        makeEntry(['name' => sprintf('bulk-%02d.example.com', $i), 'type' => 'A', 'content' => "10.1.0.{$i}"]);
    }

    // Filter to A records sorted by content desc — the TXT record is excluded.
    $response = $this->get('/entries?type=A&sort=content&direction=desc')->assertOk();
    $names = entryNamesOn($response);

    expect($names)->not->toContain('b.example.com')
        ->and(count($names))->toBe(25);

    // Page 2 keeps the sort + filter via withQueryString links.
    $props = $response->viewData('page')['props']['entries'];
    $nextLink = collect($props['links'])->firstWhere('label', '2');

    expect($nextLink['url'])->toContain('sort=content')
        ->and($nextLink['url'])->toContain('direction=desc')
        ->and($nextLink['url'])->toContain('type=A');
});
