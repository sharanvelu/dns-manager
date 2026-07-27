<?php

declare(strict_types = 1);

namespace App\Support;

use Illuminate\Support\Str;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Reads the markdown documentation shipped with THIS install (docs/content)
 * and renders it to HTML for the public /docs endpoint.
 */
class DocsRepository
{
    private string $basePath;

    private GithubFlavoredMarkdownConverter $converter;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? base_path('docs/content');

        $this->converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * All pages (frontmatter only), sorted by nav_order — feeds the sidebar.
     *
     * @return list<array{slug: string, title: string, nav_order: int, description: string}>
     */
    public function pages(): array
    {
        $pages = [];

        foreach (glob($this->basePath . '/*.md') ?: [] as $file) {
            $slug = basename($file, '.md');
            [$meta] = $this->parse((string) file_get_contents($file));

            $pages[] = [
                'slug' => $slug,
                'title' => $meta['title'] ?? Str::headline($slug),
                'nav_order' => (int) ($meta['nav_order'] ?? PHP_INT_MAX),
                'description' => $meta['description'] ?? '',
            ];
        }

        usort($pages, fn (array $a, array $b) => $a['nav_order'] <=> $b['nav_order']);

        return $pages;
    }

    /**
     * A single page with its markdown body converted to HTML,
     * or null when the slug does not exist.
     *
     * @return array{slug: string, title: string, nav_order: int, description: string, html: string}|null
     */
    public function find(string $slug): ?array
    {
        if (! preg_match('/\A[a-z0-9\-]+\z/', $slug)) {
            return null;
        }

        $file = $this->basePath . '/' . $slug . '.md';

        if (! is_file($file)) {
            return null;
        }

        [$meta, $body] = $this->parse((string) file_get_contents($file));

        return [
            'slug' => $slug,
            'title' => $meta['title'] ?? Str::headline($slug),
            'nav_order' => (int) ($meta['nav_order'] ?? PHP_INT_MAX),
            'description' => $meta['description'] ?? '',
            'html' => $this->converter->convert($body)->getContent(),
        ];
    }

    /**
     * Split a document into [frontmatter, body]. The frontmatter is plain
     * `key: value` lines between `---` fences — parsed by hand on purpose
     * (no YAML dependency needed for this contract).
     *
     * @return array{0: array<string, string>, 1: string}
     */
    private function parse(string $raw): array
    {
        if (! preg_match('/\A---\R(.*?)\R---\R?(.*)\z/s', $raw, $matches)) {
            return [[], $raw];
        }

        $meta = [];

        foreach (preg_split('/\R/', $matches[1]) ?: [] as $line) {
            if (preg_match('/^([A-Za-z0-9_\-]+):\s*(.*)$/', trim($line), $pair)) {
                $meta[$pair[1]] = trim($pair[2], " \t'\"");
            }
        }

        return [$meta, $matches[2]];
    }
}
