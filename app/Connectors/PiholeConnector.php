<?php

namespace App\Connectors;

use App\Connectors\DTOs\ConfigField;
use App\Connectors\DTOs\ConnectorCapabilities;
use App\Connectors\DTOs\RemoteRecord;
use App\Connectors\DTOs\TestResult;
use App\Connectors\Exceptions\ConnectorException;
use App\Enums\RecordType;
use App\Models\DnsEntry;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Throwable;

class PiholeConnector extends AbstractDnsConnector
{
    /**
     * Pi-hole restarts FTL's embedded resolver after every CNAME config
     * write, which takes the whole REST API down for a few seconds. The
     * session that follows a CNAME write waits this long first.
     */
    protected const CNAME_RESTART_COOLDOWN_SECONDS = 5;

    protected const SESSION_LOCK_SECONDS = 60;

    protected const SESSION_LOCK_WAIT_SECONDS = 30;

    protected const CNAME_CONFIG_PATH = '/api/config/dns/cnameRecords';

    private bool $wroteCnameConfig = false;

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
            new ConfigField(
                key: 'adopt_existing',
                label: 'Adopt existing records',
                type: 'boolean',
                required: false,
                help: 'When a record you create already exists in Pi-hole, adopt and manage it instead of failing.',
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
            supportsZones: false,
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
                ? "{$entry->fqdn},{$entry->content},{$entry->ttl}"
                : "{$entry->fqdn},{$entry->content}";
        }

        return "{$entry->content} {$entry->fqdn}";
    }

    protected function configPathFor(string $entryString): string
    {
        return str_contains($entryString, ',')
            ? self::CNAME_CONFIG_PATH
            : '/api/config/dns/hosts';
    }

    /**
     * Flag before the request goes out: even a write that errors mid-flight
     * may have been applied, and an extra cooldown is harmless.
     */
    protected function touchingConfigPath(string $path): void
    {
        if ($path === self::CNAME_CONFIG_PATH) {
            $this->wroteCnameConfig = true;
        }
    }

    protected function putEntry(string $sid, DnsEntry $entry): string
    {
        $value = $this->entryString($entry);
        $path = $this->configPathFor($value);

        $this->touchingConfigPath($path);

        $response = $this->http($sid)->put($path.'/'.rawurlencode($value));

        // 400 "already exists" means the desired state is already in place —
        // adopt it, unless the provider is configured not to.
        if ($response->status() === 400 && ! $this->shouldAdoptExisting()) {
            throw $this->failed($response, "creating {$entry->type->value} record {$entry->fqdn} (a matching record already exists and adoption is disabled)");
        }

        if ($response->failed() && $response->status() !== 400) {
            throw $this->failed($response, "creating {$entry->type->value} record {$entry->fqdn}");
        }

        return $value;
    }

    protected function shouldAdoptExisting(): bool
    {
        return (bool) $this->config('adopt_existing', true);
    }

    protected function deleteEntry(string $sid, string $externalId): void
    {
        $path = $this->configPathFor($externalId);

        $this->touchingConfigPath($path);

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
     * Sessions are serialized per provider and pace themselves after CNAME
     * writes: each write restarts the resolver and takes the API down, so
     * an unthrottled bulk push fails on every entry after the first.
     *
     * @template TReturn
     *
     * @param  callable(string): TReturn  $callback
     * @return TReturn
     */
    protected function withSession(callable $callback): mixed
    {
        $lock = Cache::lock("pihole-session:{$this->provider->id}", self::SESSION_LOCK_SECONDS);

        try {
            $lock->block(self::SESSION_LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            throw new ConnectorException('Pi-hole is still applying earlier changes — the operation was not started. It will be retried automatically.');
        }

        try {
            $this->awaitRestartCooldown();

            $sid = $this->authenticate();

            try {
                return $callback($sid);
            } finally {
                $this->logout($sid);
            }
        } finally {
            if ($this->wroteCnameConfig) {
                $this->stampRestartCooldown();
                $this->wroteCnameConfig = false;
            }

            $lock->release();
        }
    }

    private function awaitRestartCooldown(): void
    {
        $until = Cache::get($this->restartCooldownKey());

        if ($until instanceof CarbonInterface && $until->isFuture()) {
            Sleep::until($until);
        }
    }

    private function stampRestartCooldown(): void
    {
        $until = now()->addSeconds(self::CNAME_RESTART_COOLDOWN_SECONDS);

        Cache::put($this->restartCooldownKey(), $until, $until);
    }

    private function restartCooldownKey(): string
    {
        return "pihole-restart-cooldown:{$this->provider->id}";
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
