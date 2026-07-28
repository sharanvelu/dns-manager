<?php

declare(strict_types = 1);

namespace App\Support\Markdown;

use InvalidArgumentException;
use League\CommonMark\Node\Node;
use League\CommonMark\Util\HtmlElement;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Renderer\ChildNodeRendererInterface;

class CalloutRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): HtmlElement
    {
        if (! $node instanceof Callout) {
            throw new InvalidArgumentException('Block must be instance of ' . Callout::class);
        }

        $title = new HtmlElement('p', ['class' => 'docs-callout-title'], ucfirst($node->type));

        return new HtmlElement(
            'div',
            ['class' => 'docs-callout docs-callout-' . $node->type, 'data-callout' => $node->type],
            $title . "\n" . $childRenderer->renderNodes($node->children()),
        );
    }
}
