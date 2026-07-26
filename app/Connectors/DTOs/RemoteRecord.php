<?php

namespace App\Connectors\DTOs;

use App\Models\DnsEntry;

/**
 * Normalized view of a record as it exists at a provider, used for drift
 * comparison against local DnsEntry rows.
 */
final readonly class RemoteRecord
{
    public function __construct(
        public string $externalId,
        public string $type,
        public string $name,
        public string $content,
        public ?int $ttl = null,
        public ?int $priority = null,
        public bool $proxied = false,
    ) {}

    /**
     * Whether this remote record matches the desired local state.
     * TTL/priority/proxied are only compared when the connector supports them.
     */
    public function matches(DnsEntry $entry, ConnectorCapabilities $capabilities): bool
    {
        if (strcasecmp(rtrim($this->name, '.'), rtrim($entry->fqdn, '.')) !== 0) {
            return false;
        }

        if ($this->type !== $entry->type->value) {
            return false;
        }

        if (strcasecmp(rtrim($this->content, '.'), rtrim($entry->content, '.')) !== 0) {
            return false;
        }

        if ($capabilities->supportsTtl && $this->ttl !== $entry->ttl) {
            return false;
        }

        if ($capabilities->supportsPriority && $entry->type->hasPriority() && $this->priority !== $entry->priority) {
            return false;
        }

        if ($capabilities->supportsProxied && $this->proxied !== $entry->proxied) {
            return false;
        }

        return true;
    }
}
