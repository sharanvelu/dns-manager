<?php

namespace App\Connectors;

use App\Connectors\DTOs\ConfigField;
use App\Connectors\DTOs\ConnectorCapabilities;
use App\Connectors\DTOs\RemoteRecord;
use App\Connectors\DTOs\TestResult;
use App\Connectors\Exceptions\ConnectorException;
use App\Enums\RecordType;
use App\Models\DnsEntry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Throwable;

class PiholeConnector extends AbstractDnsConnector
{
    public static function type(): string
    {
        return 'pihole';
    }

    public static function displayName(): string
    {
        return 'Pi-hole';
    }

    public static function supportedRecordTypes(): array
    {
        return [RecordType::A->value, RecordType::AAAA->value, RecordType::CNAME->value];
    }

    public static function configSchema(): array
    {
        return [
            new ConfigField(
                key: 'base_url',
                label: 'Base URL',
                type: 'url',
                help: 'Pi-hole address, e.g. https://pihole.local — no trailing slash.',
            ),
            new ConfigField(
                key: 'app_password',
                label: 'App password',
                type: 'password',
                secret: true,
                help: 'Generate in Pi-hole Settings → Web interface/API → app password.',
            ),
            new ConfigField(
                key: 'verify_tls',
                label: 'Verify TLS certificate',
                type: 'boolean',
                required: false,
                help: 'Disable when the Pi-hole uses a self-signed certificate.',
                default: true,
            ),
        ];
    }

    public static function capabilities(): ConnectorCapabilities
    {
        // Hosts entries have no TTL and CNAME TTL is optional, so TTL is
        // excluded from drift comparison even though we send it when set.
        return new ConnectorCapabilities(
            supportsProxied: false,
            supportsTtl: false,
            supportsPriority: false,
        );
    }

    public function testConnection(): TestResult
    {
        try {
            return $this->withSession(function (string $sid): TestResult {
                $response = $this->http($sid)->get('/api/info/version');

                if ($response->failed()) {
                    return TestResult::failure(sprintf(
                        'Authenticated, but reading the Pi-hole version failed with HTTP %d: %s',
                        $response->status(),
                        $this->errorMessageFrom($response),
                    ));
                }

                $version = $response->json('version.core.local.version') ?? 'unknown';

                return TestResult::success(
                    "Connected to Pi-hole {$version}",
                    ['version' => $version],
                );
            });
        } catch (ConnectionException $e) {
            return TestResult::failure('Could not reach Pi-hole: '.$e->getMessage());
        } catch (ConnectorException $e) {
            return TestResult::failure($e->getMessage());
        }
    }

    public function listRecords(): Collection
    {
        return $this->withSession(function (string $sid): Collection {
            $hosts = $this->http($sid)->get('/api/config/dns/hosts');

            if ($hosts->failed()) {
                throw $this->failed($hosts, 'listing host records');
            }

            $cnames = $this->http($sid)->get('/api/config/dns/cnameRecords');

            if ($cnames->failed()) {
                throw $this->failed($cnames, 'listing CNAME records');
            }

            return collect($hosts->json('config.dns.hosts') ?? [])
                ->flatMap(fn (string $line) => $this->hostLineToRecords($line))
                ->merge(
                    collect($cnames->json('config.dns.cnameRecords') ?? [])
                        ->map(fn (string $line) => $this->cnameLineToRecord($line))
                        ->filter()
                )
                ->values();
        });
    }

    public function createRecord(DnsEntry $entry): string
    {
        return $this->withSession(fn (string $sid): string => $this->putEntry($sid, $entry));
    }

    public function updateRecord(DnsEntry $entry, string $externalId): string
    {
        return $this->withSession(function (string $sid) use ($entry, $externalId): string {
            $this->deleteEntry($sid, $externalId);

            return $this->putEntry($sid, $entry);
        });
    }

    public function deleteRecord(string $externalId): void
    {
        $this->withSession(function (string $sid) use ($externalId): void {
            $this->deleteEntry($sid, $externalId);
        });
    }

    /**
     * The exact raw string Pi-hole stores for this entry — also used as the
     * external identifier ("192.168.2.123 host.lan" / "alias.lan,target.lan,3600").
     */
    protected function entryString(DnsEntry $entry): string
    {
        if ($entry->type === RecordType::CNAME) {
            return $entry->ttl !== null
                ? "{$entry->name},{$entry->content},{$entry->ttl}"
                : "{$entry->name},{$entry->content}";
        }

        return "{$entry->content} {$entry->name}";
    }

    protected function configPathFor(string $entryString): string
    {
        return str_contains($entryString, ',')
            ? '/api/config/dns/cnameRecords'
            : '/api/config/dns/hosts';
    }

    protected function putEntry(string $sid, DnsEntry $entry): string
    {
        $value = $this->entryString($entry);
        $path = $this->configPathFor($value);

        $response = $this->http($sid)->put($path.'/'.rawurlencode($value));

        // 400 "already exists" means the desired state is already in place.
        if ($response->failed() && $response->status() !== 400) {
            throw $this->failed($response, "creating {$entry->type->value} record {$entry->name}");
        }

        return $value;
    }

    protected function deleteEntry(string $sid, string $externalId): void
    {
        $path = $this->configPathFor($externalId);

        $response = $this->http($sid)->delete($path.'/'.rawurlencode($externalId));

        // 404 means the entry is already gone.
        if ($response->failed() && $response->status() !== 404) {
            throw $this->failed($response, "deleting record {$externalId}");
        }
    }

    /** @return list<RemoteRecord> */
    protected function hostLineToRecords(string $line): array
    {
        $tokens = preg_split('/\s+/', trim($line), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($tokens) < 2) {
            return [];
        }

        $ip = array_shift($tokens);
        $type = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            ? RecordType::A->value
            : RecordType::AAAA->value;

        return array_map(fn (string $hostname) => new RemoteRecord(
            externalId: $line,
            type: $type,
            name: $hostname,
            content: $ip,
        ), $tokens);
    }

    protected function cnameLineToRecord(string $line): ?RemoteRecord
    {
        $parts = explode(',', trim($line));

        if (count($parts) < 2) {
            return null;
        }

        return new RemoteRecord(
            externalId: $line,
            type: RecordType::CNAME->value,
            name: $parts[0],
            content: $parts[1],
            ttl: isset($parts[2]) ? (int) $parts[2] : null,
        );
    }

    /**
     * Run $callback inside a single authenticated Pi-hole session, always
     * releasing the session afterwards (Pi-hole caps concurrent sessions).
     *
     * @template TReturn
     *
     * @param  callable(string): TReturn  $callback
     * @return TReturn
     */
    protected function withSession(callable $callback): mixed
    {
        $sid = $this->authenticate();

        try {
            return $callback($sid);
        } finally {
            $this->logout($sid);
        }
    }

    private function authenticate(): string
    {
        $response = $this->http()->post('/api/auth', [
            'password' => (string) $this->config('app_password'),
        ]);

        if ($response->failed() || $response->json('session.valid') !== true) {
            throw $this->failed($response, 'authentication');
        }

        return (string) $response->json('session.sid');
    }

    private function logout(string $sid): void
    {
        try {
            $this->http($sid)->delete('/api/auth');
        } catch (Throwable) {
            // Never let a failed logout mask the operation's outcome.
        }
    }

    protected function http(?string $sid = null): PendingRequest
    {
        $request = Http::baseUrl(rtrim((string) $this->config('base_url'), '/'))
            ->acceptJson();

        if ($this->config('verify_tls', true) === false) {
            $request = $request->withoutVerifying();
        }

        if ($sid !== null) {
            $request = $request->withHeaders(['X-FTL-SID' => $sid]);
        }

        return $request;
    }

    protected function errorMessageFrom(Response $response): string
    {
        $message = $response->json('error.message');

        if (! is_string($message) || $message === '') {
            return parent::errorMessageFrom($response);
        }

        $hint = $response->json('error.hint');

        return is_string($hint) && $hint !== '' ? "{$message} ({$hint})" : $message;
    }
}
