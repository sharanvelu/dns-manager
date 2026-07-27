<?php

declare(strict_types = 1);

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
    ) {
    }

    /**
     * Whether this remote record matches the desired local state.
     * TTL/priority/proxied are only compared when the connector supports them.
     */
    public function matches(DnsEntry $entry, ConnectorCapabilities $capabilities): bool
    {
        return $this->diff($entry, $capabilities) === [];
    }

    /**
     * The fields where this remote record differs from the desired local
     * state — `tracked` is the managed entry's value, `actual` what the
     * provider holds. Empty when the record matches. Null and the
     * connector's default TTL count as the same TTL on both sides.
     *
     * @return list<array{field: string, tracked: string|int|bool|null, actual: string|int|bool|null}>
     */
    public function diff(DnsEntry $entry, ConnectorCapabilities $capabilities): array
    {
        $differences = [];

        if (strcasecmp(rtrim($this->name, '.'), rtrim($entry->fqdn, '.')) !== 0) {
            $differences[] = ['field' => 'name', 'tracked' => rtrim($entry->fqdn, '.'), 'actual' => rtrim($this->name, '.')];
        }

        if ($this->type !== $entry->type->value) {
            $differences[] = ['field' => 'type', 'tracked' => $entry->type->value, 'actual' => $this->type];
        }

        if (strcasecmp(rtrim($this->content, '.'), rtrim($entry->content, '.')) !== 0) {
            $differences[] = ['field' => 'content', 'tracked' => $entry->content, 'actual' => $this->content];
        }

        if ($capabilities->supportsTtl
            && ($this->ttl ?? $capabilities->defaultTtl) !== ($entry->ttl ?? $capabilities->defaultTtl)) {
            $differences[] = ['field' => 'ttl', 'tracked' => $entry->ttl, 'actual' => $this->ttl];
        }

        if ($capabilities->supportsPriority && $entry->type->hasPriority() && $this->priority !== $entry->priority) {
            $differences[] = ['field' => 'priority', 'tracked' => $entry->priority, 'actual' => $this->priority];
        }

        if ($capabilities->supportsProxied && $this->proxied !== $entry->proxied) {
            $differences[] = ['field' => 'proxied', 'tracked' => $entry->proxied, 'actual' => $this->proxied];
        }

        return $differences;
    }
}
