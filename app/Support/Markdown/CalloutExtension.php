<?php

declare(strict_types = 1);

namespace App\Support\Markdown;

use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Environment\EnvironmentBuilderInterface;

class CalloutExtension implements ExtensionInterface
{
    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addEventListener(DocumentParsedEvent::class, new CalloutProcessor());
        $environment->addRenderer(Callout::class, new CalloutRenderer());
    }
}
