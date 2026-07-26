<?php

namespace App\Connectors\DTOs;

final readonly class ConnectorCapabilities
{
    public function __construct(
        public bool $supportsProxied = false,
        public bool $supportsTtl = true,
        public bool $supportsPriority = false,
        public ?int $minTtl = null,
        public ?int $maxTtl = null,
        public bool $supportsZones = true,
    ) {}

    public function toArray(): array
    {
        return [
            'supportsProxied' => $this->supportsProxied,
            'supportsTtl' => $this->supportsTtl,
            'supportsPriority' => $this->supportsPriority,
            'minTtl' => $this->minTtl,
            'maxTtl' => $this->maxTtl,
            'supportsZones' => $this->supportsZones,
        ];
    }
}
