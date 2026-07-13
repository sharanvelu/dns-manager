<?php

namespace App\Connectors;

use App\Connectors\Contracts\DnsConnector;
use App\Models\Provider;
use InvalidArgumentException;

class ConnectorRegistry
{
    /**
     * All available connector implementations. Register new providers
     * (Technitium, Unbound, ...) here.
     *
     * @var array<string, class-string<DnsConnector>>
     */
    protected array $connectors = [
        CloudflareConnector::class,
        PiholeConnector::class,
    ];

    /** @var array<string, class-string<DnsConnector>> */
    protected array $byType;

    public function __construct()
    {
        $this->byType = collect($this->connectors)
            ->mapWithKeys(fn (string $class) => [$class::type() => $class])
            ->all();
    }

    public function for(Provider $provider): DnsConnector
    {
        return $this->make($provider->type->value, $provider);
    }

    public function make(string $type, Provider $provider): DnsConnector
    {
        $class = $this->byType[$type]
            ?? throw new InvalidArgumentException("Unknown DNS connector type [{$type}].");

        return new $class($provider);
    }

    /** @return class-string<DnsConnector> */
    public function classFor(string $type): string
    {
        return $this->byType[$type]
            ?? throw new InvalidArgumentException("Unknown DNS connector type [{$type}].");
    }

    /**
     * Static metadata for every registered connector — used by the
     * Providers UI to render type pickers and config forms.
     */
    public function descriptors(): array
    {
        return collect($this->byType)
            ->map(fn (string $class, string $type) => [
                'type' => $type,
                'displayName' => $class::displayName(),
                'supportedRecordTypes' => $class::supportedRecordTypes(),
                'configSchema' => array_map(fn ($f) => $f->toArray(), $class::configSchema()),
                'capabilities' => $class::capabilities()->toArray(),
            ])
            ->values()
            ->all();
    }
}
