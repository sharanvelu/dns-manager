<?php

declare(strict_types = 1);

use App\Models\User;
use App\Models\DnsEntry;
use App\Providers\AppServiceProvider;

test('FORCE_HTTPS makes generated URLs use https', function () {
    config(['app.force_https' => true]);

    (new AppServiceProvider(app()))->boot();

    expect(url('/entries'))->toStartWith('https://');
});

test('URLs keep the request scheme by default', function () {
    expect(config('app.force_https'))->toBeFalse()
        ->and(url('/entries'))->toStartWith('http://');
});

test('pagination links honor the proxy X-Forwarded-Proto header', function () {
    // TLS terminates at the ingress: the app receives plain http plus
    // X-Forwarded-Proto. Pagination links derive from the REQUEST url
    // (not the URL generator), so they need the trusted-proxy path.
    $this->actingAs(User::factory()->create());
    DnsEntry::factory()->count(26)->create(); // 25/page -> 2 pages

    $response = $this->get('http://dns.example.com/entries', [
        'X-Forwarded-Proto' => 'https',
    ]);

    $links = collect($response->viewData('page')['props']['entries']['links'])
        ->pluck('url')
        ->filter();

    expect($links)->not->toBeEmpty()
        ->and($links->every(fn (string $url) => str_starts_with($url, 'https://')))->toBeTrue();
});
