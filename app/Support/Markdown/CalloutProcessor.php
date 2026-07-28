<?php

declare(strict_types = 1);

namespace App\Support\Markdown;

use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;

/**
 * Rewrites blockquotes whose first line is a GitHub alert marker
 * (`> [!NOTE]`, `> [!WARNING]`, …) into Callout nodes. Blockquotes with an
 * unknown marker (or none) are left untouched.
 */
class CalloutProcessor
{
    public function __invoke(DocumentParsedEvent $event): void
    {
        foreach ($event->getDocument()->iterator() as $node) {
            if (! $node instanceof BlockQuote) {
                continue;
            }

            $type = $this->extractMarker($node);

            if ($type === null) {
                continue;
            }

            $callout = new Callout($type);

            foreach ($node->children() as $child) {
                $callout->appendChild($child);
            }

            $node->replaceWith($callout);
        }
    }

    /**
     * Returns the callout type when the blockquote starts with a valid
     * marker — and strips the marker from the tree as a side effect.
     */
    private function extractMarker(BlockQuote $quote): ?string
    {
        $paragraph = $quote->firstChild();

        if (! $paragraph instanceof Paragraph) {
            return null;
        }

        $marker = $paragraph->firstChild();

        if (! $marker instanceof Text) {
            return null;
        }

        if (! preg_match('/\A\[!([A-Z]+)\]\z/', trim($marker->getLiteral()), $match)) {
            return null;
        }

        $type = strtolower($match[1]);

        if (! in_array($type, Callout::TYPES, true)) {
            return null;
        }

        $next = $marker->next();
        $marker->detach();

        if ($next instanceof Newline) {
            $next->detach();
        }

        if ($paragraph->firstChild() === null) {
            $paragraph->detach();
        }

        return $type;
    }
}
