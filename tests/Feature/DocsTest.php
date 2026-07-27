<?php

declare(strict_types = 1);

use App\Support\DocsRepository;

beforeEach(function () {
    // Point the repository at fixture markdown so tests never depend on
    // the real docs/content files (written/maintained separately).
    $this->app->bind(
        DocsRepository::class,
        fn () => new DocsRepository(base_path('tests/Fixtures/docs')),
    );
});

it('renders the index page at /docs', function () {
    $response = $this->get('/docs');

    $response->assertOk();
    $response->assertSee('Fixture Home');
    $response->assertSee(
        'You are viewing the documentation for your installed version (v' . config('app.version') . ').'
    );
});

it('renders a specific page with the nav in nav_order order', function () {
    $response = $this->get('/docs/providers');

    $response->assertOk();
    $response->assertSee('Fixture Providers');
    $response->assertSeeInOrder(['Fixture Home', 'Fixture Providers', 'Fixture Advanced']);
});

it('returns 404 for an unknown slug', function () {
    $this->get('/docs/nope-not-here')->assertNotFound();
});

it('rejects path traversal attempts', function () {
    $this->get('/docs/..%2f..')->assertNotFound();
    $this->get('/docs/../..')->assertNotFound();
    $this->get('/docs/..%2f..%2f.env')->assertNotFound();
});

it('links the version banner to the latest docs site', function () {
    $this->get('/docs')
        ->assertOk()
        ->assertSee(config('app.docs_site_url'), false);
});

it('converts markdown tables to HTML and strips raw HTML', function () {
    $response = $this->get('/docs/providers');

    $response->assertOk();
    $response->assertSee('<table>', false);
    $response->assertSee('Cloudflare');
    $response->assertDontSee('<script>', false);
    $response->assertDontSee('onclick', false);
});

it('is reachable without authentication', function () {
    $this->assertGuest();

    $this->get('/docs')->assertOk();
    $this->get('/docs/providers')->assertOk();
});
