<?php

declare(strict_types = 1);

namespace App\Support\Markdown;

use League\CommonMark\Node\Block\AbstractBlock;

/**
 * A GitHub-style alert blockquote (`> [!NOTE]` …) promoted to its own block
 * so it renders as a styled callout instead of a plain <blockquote>.
 */
class Callout extends AbstractBlock
{
    public const TYPES = ['note', 'tip', 'important', 'warning', 'caution'];

    public function __construct(public readonly string $type)
    {
        parent::__construct();
    }
}
