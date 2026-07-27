<?php

declare(strict_types = 1);

namespace App\Connectors;

use App\Models\Provider;
use App\Models\ZoneProvider;
use InvalidArgumentException;
use App\Connectors\Contracts\DnsConnector;

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
        TechnitiumConnector::class,
    ];

    /** @var array<string, class-string<DnsConnector>> */
    protected array $byType;

    public function __construct()
    {
        $this->byType = collect($this->connectors)
            ->mapWithKeys(fn (string $class) => [$class::type() => $class])
            ->all();
    }

    public function for(Provider|ZoneProvider $subject): DnsConnector
    {
        if ($subject instanceof ZoneProvider) {
            return $this->make($subject->provider->type->value, $subject->provider, $subject);
        }

        return $this->make($subject->type->value, $subject);
    }

    public function make(string $type, Provider $provider, ?ZoneProvider $zoneProvider = null): DnsConnector
    {
        $class = $this->byType[$type]
            ?? throw new InvalidArgumentException("Unknown DNS connector type [{$type}].");

        return new $class($provider, $zoneProvider);
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
                'zoneConfigSchema' => array_map(fn ($f) => $f->toArray(), $class::zoneConfigSchema()),
                'capabilities' => $class::capabilities()->toArray(),
            ])
            ->values()
            ->all();
    }
}
