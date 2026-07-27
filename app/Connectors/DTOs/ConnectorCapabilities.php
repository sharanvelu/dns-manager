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
        /**
         * The TTL the provider stores when an entry has a null (auto) TTL.
         * Drift comparison treats null and this value as the same TTL on
         * both sides, so "auto" and an explicit default never drift.
         */
        public ?int $defaultTtl = null,
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
            'defaultTtl' => $this->defaultTtl,
        ];
    }
}
