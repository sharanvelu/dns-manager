<?php

declare(strict_types = 1);

use Tests\TestCase;
use App\Support\DocsRepository;

uses(TestCase::class);

beforeEach(function () {
    $this->repo = new DocsRepository(base_path('tests/Fixtures/docs'));
});

it('lists pages sorted by nav_order', function () {
    $slugs = array_column($this->repo->pages(), 'slug');

    expect($slugs)->toBe(['index', 'providers', 'advanced']);
});

it('extracts an h2/h3 outline with unique ids', function () {
    $headings = $this->repo->find('advanced')['headings'];

    expect(array_column($headings, 'text'))
        ->toBe(['Setup', 'Install / Configure', 'Install / Configure', 'Alerts'])
        ->and(array_column($headings, 'level'))->toBe([2, 3, 3, 2])
        ->and(array_column($headings, 'id'))->toBe(
            array_values(array_unique(array_column($headings, 'id')))
        );
});

it('applies ids to headings and appends permalink anchors', function () {
    $html = $this->repo->find('advanced')['html'];

    expect($html)
        ->toContain('<h2 id="setup">')
        ->toContain('class="docs-anchor"');
});

it('highlights fenced code with dual phiki themes', function () {
    $html = $this->repo->find('advanced')['html'];

    expect($html)
        ->toContain('class="phiki')
        ->toContain('--phiki-dark-color');
});

it('renders known callout markers and leaves unknown ones as blockquotes', function () {
    $html = $this->repo->find('advanced')['html'];

    expect($html)
        ->toContain('docs-callout docs-callout-warning')
        ->toContain('<p class="docs-callout-title">Warning</p>')
        ->toContain('docs-callout docs-callout-tip')
        ->toContain('<blockquote>')
        ->toContain('[!FOO]')
        ->not->toContain('docs-callout-foo');
});

it('builds a search index with plain text only', function () {
    $index = $this->repo->searchIndex();

    expect(array_column($index, 'slug'))->toBe(['index', 'providers', 'advanced']);

    $advanced = $index[2];

    expect($advanced['title'])->toBe('Fixture Advanced')
        ->and($advanced['headings'])->not->toBeEmpty()
        ->and($advanced['text'])->toContain('Careful with this.')
        ->and($advanced['text'])->not->toContain('<');
});

it('falls back to a headline title and bottom nav_order without frontmatter', function () {
    $dir = sys_get_temp_dir() . '/docs-fixture-' . uniqid();
    mkdir($dir);
    file_put_contents($dir . '/loose-page.md', "# Loose\n\nNo frontmatter here.");

    try {
        $page = (new DocsRepository($dir))->pages()[0];

        expect($page['title'])->toBe('Loose Page')
            ->and($page['nav_order'])->toBe(PHP_INT_MAX)
            ->and($page['description'])->toBe('');
    } finally {
        unlink($dir . '/loose-page.md');
        rmdir($dir);
    }
});
