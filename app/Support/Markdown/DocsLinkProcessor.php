<?php

declare(strict_types = 1);

namespace App\Support\Markdown;

use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;

/**
 * Rewrites the relative page links used in docs/content markdown
 * (`zones`, `providers#zones`) to absolute /docs URLs. The Blade docs page
 * used a <base> tag for this; an SPA page cannot, so it happens at render.
 */
class DocsLinkProcessor
{
    public function __invoke(DocumentParsedEvent $event): void
    {
        foreach ($event->getDocument()->iterator() as $node) {
            if (! $node instanceof Link) {
                continue;
            }

            $url = $node->getUrl();

            if ($url === '' || preg_match('#\A(?:[a-z][a-z0-9+.\-]*:|/|\#)#i', $url)) {
                continue;
            }

            $node->setUrl('/docs/' . ($url === 'index' ? '' : $url));
        }
    }
}
