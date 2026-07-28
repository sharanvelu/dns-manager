<?php

declare(strict_types = 1);

namespace App\Support;

use Phiki\Theme\Theme;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\MarkdownConverter;
use App\Support\Markdown\CalloutExtension;
use League\CommonMark\Node\Block\Document;
use App\Support\Markdown\DocsLinkProcessor;
use League\CommonMark\Output\RenderedContent;
use Phiki\Adapters\CommonMark\PhikiExtension;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;

/**
 * Reads the markdown documentation shipped with THIS install (docs/content)
 * and renders it to HTML for the public /docs endpoint: GFM + heading
 * permalinks (h2–h3) + Phiki dual-theme highlighting + GitHub-style callouts.
 */
class DocsRepository
{
    /** Bump when the rendering pipeline changes — invalidates cached renders. */
    private const PIPELINE_REV = 1;

    private string $basePath;

    private MarkdownConverter $converter;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? base_path('docs/content');

        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'heading_permalink' => [
                'html_class' => 'docs-anchor',
                'id_prefix' => '',
                'fragment_prefix' => '',
                'insert' => 'after',
                'min_heading_level' => 2,
                'max_heading_level' => 3,
                'symbol' => '#',
                'aria_hidden' => true,
                'apply_id_to_heading' => true,
            ],
        ]);

        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $environment->addExtension(new HeadingPermalinkExtension());
        $environment->addExtension(new PhikiExtension([
            'light' => Theme::GithubLight,
            'dark' => Theme::GithubDarkDefault,
        ]));
        $environment->addExtension(new CalloutExtension());
        $environment->addEventListener(DocumentParsedEvent::class, new DocsLinkProcessor());

        $this->converter = new MarkdownConverter($environment);
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
     * A single page with its markdown body converted to HTML and its h2/h3
     * outline extracted for the "On this page" rail — or null when the slug
     * does not exist.
     *
     * @return array{slug: string, title: string, nav_order: int, description: string, html: string, headings: list<array{id: string, text: string, level: int}>}|null
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

        $raw = (string) file_get_contents($file);
        [$meta, $body] = $this->parse($raw);

        $rendered = $this->render($body);

        return [
            'slug' => $slug,
            'title' => $meta['title'] ?? Str::headline($slug),
            'nav_order' => (int) ($meta['nav_order'] ?? PHP_INT_MAX),
            'description' => $meta['description'] ?? '',
            'html' => $rendered['html'],
            'headings' => $rendered['headings'],
        ];
    }

    /**
     * A lightweight per-page index for the ⌘K search dialog.
     *
     * @return list<array{slug: string, title: string, description: string, headings: list<array{id: string, text: string, level: int}>, text: string}>
     */
    public function searchIndex(): array
    {
        $index = [];

        foreach ($this->pages() as $page) {
            $doc = $this->find($page['slug']);

            if ($doc === null) {
                continue;
            }

            $index[] = [
                'slug' => $doc['slug'],
                'title' => $doc['title'],
                'description' => $doc['description'],
                'headings' => $doc['headings'],
                'text' => Str::limit(Str::squish(strip_tags($doc['html'])), 2000, ''),
            ];
        }

        return $index;
    }

    /**
     * Convert markdown to HTML and extract the heading outline. Cached by
     * content hash (+ app version) — edits invalidate naturally, upgrades
     * flush wholesale.
     *
     * @return array{html: string, headings: list<array{id: string, text: string, level: int}>}
     */
    private function render(string $body): array
    {
        $key = 'docs:render:' . self::PIPELINE_REV . ':' . config('app.version') . ':' . md5($body);

        return Cache::rememberForever($key, function () use ($body): array {
            $result = $this->converter->convert($body);

            return [
                'html' => $result->getContent(),
                'headings' => $result instanceof RenderedContent
                    ? $this->extractHeadings($result->getDocument())
                    : [],
            ];
        });
    }

    /**
     * Collect the h2/h3 outline. Runs after conversion, so the ids applied
     * by HeadingPermalinkProcessor are already on the heading nodes.
     *
     * @return list<array{id: string, text: string, level: int}>
     */
    private function extractHeadings(Document $document): array
    {
        $headings = [];

        foreach ($document->iterator() as $node) {
            if (! $node instanceof Heading || $node->getLevel() < 2 || $node->getLevel() > 3) {
                continue;
            }

            $id = $node->data->get('attributes/id', '');

            if (! is_string($id) || $id === '') {
                continue;
            }

            $headings[] = [
                'id' => $id,
                'text' => $this->headingText($node),
                'level' => $node->getLevel(),
            ];
        }

        return $headings;
    }

    private function headingText(Heading $heading): string
    {
        $text = '';

        foreach ($heading->iterator() as $inline) {
            if ($inline instanceof Text || $inline instanceof Code) {
                $text .= $inline->getLiteral();
            }
        }

        return trim($text);
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
