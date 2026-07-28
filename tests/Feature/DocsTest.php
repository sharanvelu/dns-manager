<?php

declare(strict_types = 1);

use App\Support\DocsRepository;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Point the repository at fixture markdown so tests never depend on
    // the real docs/content files (written/maintained separately).
    $this->app->bind(
        DocsRepository::class,
        fn () => new DocsRepository(base_path('tests/Fixtures/docs')),
    );
});

it('renders the index page at /docs with version and latest-docs props', function () {
    $this->get('/docs')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('docs/show')
            ->where('doc.title', 'Fixture Home')
            ->where('current', 'index')
            ->where('version', config('app.version'))
            ->where('docsSiteUrl', config('app.docs_site_url')));
});

it('renders a specific page with the nav in nav_order order', function () {
    $this->get('/docs/providers')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('docs/show')
            ->where('doc.title', 'Fixture Providers')
            ->where('pages.0.title', 'Fixture Home')
            ->where('pages.1.title', 'Fixture Providers')
            ->where('pages.2.title', 'Fixture Advanced'));
});

it('returns 404 for an unknown slug', function () {
    $this->get('/docs/nope-not-here')->assertNotFound();
});

it('rejects path traversal attempts', function () {
    $this->get('/docs/..%2f..')->assertNotFound();
    $this->get('/docs/../..')->assertNotFound();
    $this->get('/docs/..%2f..%2f.env')->assertNotFound();
});

it('converts markdown tables to HTML and strips raw HTML', function () {
    $this->get('/docs/providers')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('doc.html', fn ($html) => str_contains((string) $html, '<table>')
                && str_contains((string) $html, 'Cloudflare')
                && ! str_contains((string) $html, '<script>')
                && ! str_contains((string) $html, 'onclick')));
});

it('exposes the h2/h3 outline, highlighted code, and callouts', function () {
    $this->get('/docs/advanced')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('doc.headings.0.id', 'setup')
            ->where('doc.headings.0.level', 2)
            ->where('doc.html', fn ($html) => str_contains((string) $html, 'class="phiki')
                && str_contains((string) $html, '--phiki-dark-color')
                && str_contains((string) $html, 'docs-callout docs-callout-warning')
                && str_contains((string) $html, 'class="docs-anchor"')));
});

it('rewrites relative page links to /docs URLs', function () {
    $this->get('/docs/providers')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('doc.html', fn ($html) => str_contains((string) $html, 'href="/docs/advanced"')
                && str_contains((string) $html, 'href="/docs/"')));
});

it('ships a plain-text search index for every page', function () {
    $this->get('/docs')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('searchIndex', 3)
            ->where('searchIndex.0.slug', 'index')
            ->where('searchIndex.2.text', fn ($text) => str_contains((string) $text, 'Careful with this.')
                && ! str_contains((string) $text, '<')));
});

it('is reachable without authentication', function () {
    $this->assertGuest();

    $this->get('/docs')->assertOk();
    $this->get('/docs/providers')->assertOk();
});
