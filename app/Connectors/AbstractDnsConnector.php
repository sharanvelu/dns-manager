<?php

namespace App\Connectors;

use App\Connectors\Contracts\DnsConnector;
use App\Connectors\Exceptions\ConnectorException;
use App\Models\Provider;
use Illuminate\Http\Client\Response;

abstract class AbstractDnsConnector implements DnsConnector
{
    public function __construct(protected Provider $provider) {}

    /**
     * Decrypted provider configuration entered on the Providers page.
     */
    protected function config(?string $key = null, mixed $default = null): mixed
    {
        $config = $this->provider->config ?? [];

        return $key === null ? $config : ($config[$key] ?? $default);
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
