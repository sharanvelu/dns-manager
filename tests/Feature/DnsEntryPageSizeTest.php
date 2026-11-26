<?php

declare(strict_types = 1);

use App\Models\User;
use App\Models\DnsZone;
use App\Models\DnsEntry;
use App\Support\EntryQuery;

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    $this->zone = DnsZone::factory()->create(['name' => 'example.com']);

    foreach (range(1, 60) as $i) {
        DnsEntry::factory()->create([
            'dns_zone_id' => $this->zone->id,
            'name' => sprintf('host-%02d', $i),
            'type' => 'A',
            'content' => "10.0.0.{$i}",
        ]);
    }
});

function pageOf($response): array
{
    return $response->viewData('page')['props']['entries'];
}

test('entries default to 25 per page', function () {
    $response = $this->get('/entries')->assertOk()->assertInertia(
        fn ($page) => $page->where('filters.per_page', 25),
    );

    expect(pageOf($response))
        ->per_page->toBe(25)
        ->last_page->toBe(3)
        ->and(pageOf($response)['data'])->toHaveCount(25);
});

test('the per_page param picks one of the offered page sizes', function (int $size, int $rows, int $lastPage) {
    $response = $this->get("/entries?per_page={$size}")->assertOk()->assertInertia(
        fn ($page) => $page->where('filters.per_page', $size),
    );

    expect(pageOf($response))
        ->per_page->toBe($size)
        ->last_page->toBe($lastPage)
        ->and(pageOf($response)['data'])->toHaveCount($rows);
})->with([
    'ten' => [10, 10, 6],
    'fifty' => [50, 50, 2],
    'hundred' => [100, 60, 1],
]);

test('unsupported page sizes fall back to the default instead of erroring', function (string $value) {
    $response = $this->get('/entries?per_page=' . urlencode($value))->assertOk()->assertInertia(
        fn ($page) => $page->where('filters.per_page', EntryQuery::DEFAULT_PAGE_SIZE),
    );

    expect(pageOf($response)['per_page'])->toBe(EntryQuery::DEFAULT_PAGE_SIZE);
})->with(['7', '0', '-25', '100000', 'abc', '50.5', '']);

test('the remembered cookie sets the page size when the request has none', function () {
    $response = $this->withUnencryptedCookie(EntryQuery::PAGE_SIZE_COOKIE, '50')
        ->get('/entries')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters.per_page', 50));

    expect(pageOf($response)['data'])->toHaveCount(50);
});

test('an explicit per_page param wins over the remembered cookie', function () {
    $response = $this->withUnencryptedCookie(EntryQuery::PAGE_SIZE_COOKIE, '50')
        ->get('/entries?per_page=10')
        ->assertOk();

    expect(pageOf($response)['per_page'])->toBe(10);
});

test('an invalid remembered cookie falls back to the default', function () {
    $response = $this->withUnencryptedCookie(EntryQuery::PAGE_SIZE_COOKIE, '9999')
        ->get('/entries')
        ->assertOk();

    expect(pageOf($response)['per_page'])->toBe(EntryQuery::DEFAULT_PAGE_SIZE);
});

test('pagination links keep the page size and active filters', function () {
    $response = $this->get('/entries?per_page=10&type=A&sort=name&direction=desc')->assertOk();

    $next = pageOf($response)['next_page_url'];

    expect($next)->toContain('per_page=10')
        ->toContain('type=A')
        ->toContain('direction=desc')
        ->toContain('page=2');
});

test('the zone records page honours the page size too', function () {
    $response = $this->get("/zones/{$this->zone->id}/records?per_page=50")->assertOk()->assertInertia(
        fn ($page) => $page->where('filters.per_page', 50),
    );

    expect(pageOf($response)['data'])->toHaveCount(50);
});
