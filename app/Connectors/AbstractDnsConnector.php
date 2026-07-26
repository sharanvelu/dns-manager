<?php

namespace App\Connectors;

use App\Connectors\Contracts\DnsConnector;
use App\Connectors\DTOs\TestResult;
use App\Connectors\Exceptions\ConnectorException;
use App\Models\DnsZone;
use App\Models\Provider;
use App\Models\ZoneProvider;
use Illuminate\Http\Client\Response;

abstract class AbstractDnsConnector implements DnsConnector
{
    public function __construct(
        protected Provider $provider,
        protected ?ZoneProvider $zoneProvider = null,
    ) {}

    /**
     * Decrypted provider configuration overlaid with the per-zone
     * settings when a zone attachment is present (zone value wins).
     */
    protected function config(?string $key = null, mixed $default = null): mixed
    {
        $config = array_merge(
            $this->provider->config ?? [],
            $this->zoneProvider?->config ?? [],
        );

        return $key === null ? $config : ($config[$key] ?? $default);
    }

    /**
     * Per-zone settings this connector needs. Default: none.
     */
    public static function zoneConfigSchema(): array
    {
        return [];
    }

    /**
     * The zone attachment this connector was built for, or throw when the
     * operation was invoked without one.
     */
    protected function requireZoneContext(): ZoneProvider
    {
        return $this->zoneProvider
            ?? throw new ConnectorException(static::displayName().' operation requires a zone attachment.');
    }

    protected function zone(): DnsZone
    {
        return $this->requireZoneContext()->zone;
    }

    /**
     * For zoneless connectors the attachment is valid whenever the
     * credentials are — connectors with real zones override this.
     */
    public function testZone(): TestResult
    {
        return $this->testConnection();
    }

    /**
     * Connectors that can look zones up remotely override this.
     */
    public function discoverZoneConfig(string $zoneName): ?array
    {
        return null;
    }

    /**
     * Turn a failed HTTP response into a ConnectorException with a
     * provider-appropriate message.
     */
    protected function failed(Response $response, string $action): ConnectorException
    {
        return new ConnectorException(sprintf(
            '%s: %s failed with HTTP %d: %s',
            static::displayName(),
            $action,
            $response->status(),
            $this->errorMessageFrom($response),
        ));
    }

    /**
     * Extract a human-readable error message from an error response body.
     * Connectors override this to parse their provider's error envelope.
     */
    protected function errorMessageFrom(Response $response): string
    {
        return mb_strimwidth($response->body(), 0, 500, '…');
    }
}
