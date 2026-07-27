<?php

declare(strict_types = 1);

namespace App\Connectors;

use Throwable;
use App\Models\DnsEntry;
use Illuminate\Support\Collection;
use App\Connectors\DTOs\TestResult;
use App\Connectors\DTOs\ConfigField;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use App\Connectors\DTOs\RemoteRecord;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use App\Connectors\DTOs\ConnectorCapabilities;
use Illuminate\Http\Client\ConnectionException;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\RecordNotFoundException;

class CloudflareConnector extends AbstractDnsConnector
{
    protected const BASE_URL = 'https://api.cloudflare.com/client/v4';

    protected const PER_PAGE = 5000;

    /**
     * Cloudflare error codes meaning "a record like this is already there":
     * 81057 = identical record exists, 81053 = conflicting record on the name.
     */
    protected const ALREADY_EXISTS_CODES = [81057, 81053];

    public static function type(): string
    {
        return 'cloudflare';
    }

    public static function displayName(): string
    {
        return 'Cloudflare';
    }

    public static function supportedRecordTypes(): array
    {
        return ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'NS', 'CAA', 'PTR'];
    }

    public static function configSchema(): array
    {
        return [
            new ConfigField(
                key: 'api_token',
                label: 'API Token',
                type: 'password',
                secret: true,
                help: 'API token with Zone.DNS Edit and Zone Read permissions.',
            ),
            new ConfigField(
                key: 'adopt_existing',
                label: 'Adopt existing records',
                type: 'boolean',
                required: false,
                help: 'When a record you create already exists at Cloudflare, adopt and manage it (aligning TTL/proxy to your entry) instead of failing.',
                default: true,
            ),
        ];
    }

    public static function zoneConfigSchema(): array
    {
        return [
            new ConfigField(
                key: 'zone_id',
                label: 'Zone ID',
                type: 'text',
                required: true,
                help: 'Auto-discovered from the zone name; override if needed.',
            ),
        ];
    }

    public static function capabilities(): ConnectorCapabilities
    {
        return new ConnectorCapabilities(
            supportsProxied: true,
            supportsTtl: true,
            supportsPriority: true,
            minTtl: 60,
            maxTtl: 86400,
        );
    }

    public function testConnection(): TestResult
    {
        try {
            // Listing zones validates the token without needing any zone
            // context — it works for account-owned AND zone-scoped tokens.
            // Deliberately NOT /user/tokens/verify: that endpoint rejects
            // account-owned tokens even when they are fully valid for zone
            // operations.
            $response = $this->http()->get('/zones', ['per_page' => 1]);

            if (! $response->successful() || $response->json('success') !== true) {
                return TestResult::failure('Token check failed: ' . $this->errorMessageFrom($response));
            }

            $zoneCount = $response->json('result_info.total_count');

            if (is_int($zoneCount)) {
                return TestResult::success(
                    sprintf('API token is valid — %d zone%s accessible.', $zoneCount, $zoneCount === 1 ? '' : 's'),
                    ['zones' => $zoneCount],
                );
            }

            return TestResult::success('API token is valid.');
        } catch (ConnectionException $e) {
            return TestResult::failure('Could not reach Cloudflare: ' . $e->getMessage());
        }
    }

    public function testZone(): TestResult
    {
        $this->requireZoneContext();

        try {
            $zone = $this->http()->get('/zones/' . $this->config('zone_id'));

            if (! $zone->successful() || $zone->json('success') !== true) {
                return TestResult::failure('Zone lookup failed: ' . $this->errorMessageFrom($zone));
            }

            $remoteName = (string) $zone->json('result.name');
            $localName = $this->zone()->name;

            if (strcasecmp($remoteName, $localName) !== 0) {
                return TestResult::failure("Zone ID belongs to {$remoteName} — expected {$localName}");
            }

            return TestResult::success('Connected to zone ' . $remoteName, [
                'zone' => $remoteName,
                'status' => $zone->json('result.status'),
            ]);
        } catch (ConnectionException $e) {
            return TestResult::failure('Could not reach Cloudflare: ' . $e->getMessage());
        }
    }

    public function discoverZoneConfig(string $zoneName): ?array
    {
        try {
            $response = $this->http()->get('/zones', [
                'name' => $zoneName,
                'per_page' => 1,
            ]);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful() || $response->json('success') !== true) {
            return null;
        }

        $zoneId = $response->json('result.0.id');

        return $zoneId !== null ? ['zone_id' => (string) $zoneId] : null;
    }

    public function listRecords(): Collection
    {
        $records = collect();
        $page = 1;

        do {
            $response = $this->http()->get($this->recordsPath(), [
                'per_page' => self::PER_PAGE,
                'page' => $page,
            ]);

            if (! $response->successful()) {
                throw $this->failed($response, 'list records');
            }

            foreach ($response->json('result', []) as $record) {
                if (in_array($record['type'], static::supportedRecordTypes(), true)) {
                    $records->push($this->toRemoteRecord($record));
                }
            }

            $totalPages = (int) $response->json('result_info.total_pages', 1);
            $page++;
        } while ($page <= $totalPages);

        return $records;
    }

    public function createRecord(DnsEntry $entry): string
    {
        $response = $this->http()->post($this->recordsPath(), $this->payloadFor($entry));

        if ($response->successful()) {
            return (string) $response->json('result.id');
        }

        if ($this->shouldAdoptExisting() && $this->isAlreadyExistsError($response)) {
            $adopted = $this->findAdoptableRecord($entry);

            if ($adopted !== null) {
                // Take over the existing record and align it with the local
                // entry (DB wins — same policy as drift re-push).
                return $this->updateRecord($entry, $adopted);
            }
        }

        throw $this->failed($response, 'create record');
    }

    public function updateRecord(DnsEntry $entry, string $externalId): string
    {
        $response = $this->http()->put($this->recordsPath() . '/' . $externalId, $this->payloadFor($entry));

        // 404 / 81044: the record was deleted out-of-band — signal it so the
        // sync job can fall back to creating the record fresh.
        if ($response->status() === 404) {
            throw new RecordNotFoundException($this->failed($response, 'update record')->getMessage());
        }

        if (! $response->successful()) {
            throw $this->failed($response, 'update record');
        }

        return (string) $response->json('result.id');
    }

    public function deleteRecord(string $externalId): void
    {
        $response = $this->http()->delete($this->recordsPath() . '/' . $externalId);

        if ($response->status() === 404) {
            return; // Already gone remotely — nothing to do.
        }

        if (! $response->successful()) {
            throw $this->failed($response, 'delete record');
        }
    }

    protected function errorMessageFrom(Response $response): string
    {
        $errors = $response->json('errors');

        if (is_array($errors) && $errors !== []) {
            return collect($errors)
                ->map(fn (array $error): string => sprintf(
                    '[%s] %s',
                    $error['code'] ?? '?',
                    $error['message'] ?? 'Unknown error',
                ))
                ->implode('; ');
        }

        return parent::errorMessageFrom($response);
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withToken((string) $this->config('api_token'))
            ->acceptJson()
            ->retry(3, 1000, function (Throwable $exception): bool {
                return $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->status() === 429);
            }, throw: false);
    }

    /**
     * Every record operation goes through here — zone_id lives in the
     * attachment config, so zone-less use must fail loudly.
     */
    protected function recordsPath(): string
    {
        $this->requireZoneContext();

        return '/zones/' . $this->config('zone_id') . '/dns_records';
    }

    protected function shouldAdoptExisting(): bool
    {
        return (bool) $this->config('adopt_existing', true);
    }

    protected function isAlreadyExistsError(Response $response): bool
    {
        return collect($response->json('errors', []))
            ->pluck('code')
            ->intersect(self::ALREADY_EXISTS_CODES)
            ->isNotEmpty();
    }

    /**
     * Find the remote record to adopt after an already-exists conflict:
     * same type + name, preferring an exact content match; otherwise only
     * an unambiguous single record (e.g. the one conflicting CNAME).
     */
    protected function findAdoptableRecord(DnsEntry $entry): ?string
    {
        $response = $this->http()->get($this->recordsPath(), [
            'type' => $entry->type->value,
            'name' => $entry->fqdn,
            'per_page' => 100,
        ]);

        if (! $response->successful()) {
            return null;
        }

        $candidates = collect($response->json('result', []));

        $normalize = fn (string $value): string => strtolower(rtrim($value, '.'));

        $contentMatch = $candidates->first(fn (array $record): bool => $normalize($this->contentFrom($record)) === $normalize($entry->content));

        $adopted = $contentMatch ?? ($candidates->count() === 1 ? $candidates->first() : null);

        return $adopted !== null ? (string) $adopted['id'] : null;
    }

    /**
     * Build the Cloudflare create/update payload for a local entry.
     */
    protected function payloadFor(DnsEntry $entry): array
    {
        $type = $entry->type->value;

        $payload = [
            'type' => $type,
            'name' => $entry->fqdn,
            'content' => $entry->content,
            // Cloudflare uses ttl 1 for "auto"; proxied records always use it.
            'ttl' => $entry->proxied ? 1 : ($entry->ttl ?? 1),
        ];

        if ($entry->comment !== null) {
            $payload['comment'] = $entry->comment;
        }

        if (in_array($type, ['A', 'AAAA', 'CNAME'], true)) {
            $payload['proxied'] = (bool) $entry->proxied;
        }

        return match ($type) {
            'MX' => [...$payload, 'priority' => $entry->priority ?? 10],
            'TXT' => [...$payload, 'content' => $this->quoteTxt($entry->content)],
            'SRV' => $this->withSrvData($payload, $entry),
            'CAA' => $this->withCaaData($payload, $entry),
            default => $payload,
        };
    }

    /**
     * Parse local SRV content ("weight port target") into Cloudflare's data object.
     */
    protected function withSrvData(array $payload, DnsEntry $entry): array
    {
        if (! preg_match('/^\s*(\d+)\s+(\d+)\s+(\S+)\s*$/', (string) $entry->content, $matches)) {
            throw new ConnectorException(sprintf(
                'Cloudflare: SRV content "%s" is invalid — expected "weight port target".',
                $entry->content,
            ));
        }

        unset($payload['content']);

        $payload['data'] = [
            'priority' => $entry->priority ?? 0,
            'weight' => (int) $matches[1],
            'port' => (int) $matches[2],
            'target' => $matches[3],
        ];

        return $payload;
    }

    /**
     * Parse local CAA content ('flags tag "value"') into Cloudflare's data object.
     */
    protected function withCaaData(array $payload, DnsEntry $entry): array
    {
        if (! preg_match('/^\s*(\d+)\s+(\S+)\s+"?([^"]+)"?\s*$/', (string) $entry->content, $matches)) {
            throw new ConnectorException(sprintf(
                'Cloudflare: CAA content "%s" is invalid — expected \'flags tag "value"\'.',
                $entry->content,
            ));
        }

        unset($payload['content']);

        $payload['data'] = [
            'flags' => (int) $matches[1],
            'tag' => $matches[2],
            'value' => $matches[3],
        ];

        return $payload;
    }

    protected function quoteTxt(string $content): string
    {
        if (str_starts_with($content, '"') && str_ends_with($content, '"')) {
            return $content;
        }

        return '"' . $content . '"';
    }

    /**
     * Map a Cloudflare API record to the normalized RemoteRecord DTO.
     */
    protected function toRemoteRecord(array $record): RemoteRecord
    {
        $ttl = (int) ($record['ttl'] ?? 1);

        return new RemoteRecord(
            externalId: (string) $record['id'],
            type: $record['type'],
            name: $record['name'],
            content: $this->contentFrom($record),
            ttl: $ttl === 1 ? null : $ttl,
            priority: isset($record['priority']) ? (int) $record['priority'] : null,
            proxied: (bool) ($record['proxied'] ?? false),
        );
    }

    /**
     * Rebuild the local content string from a remote record so drift
     * comparison matches the format stored in DnsEntry rows.
     */
    protected function contentFrom(array $record): string
    {
        $data = $record['data'] ?? [];

        return match ($record['type']) {
            'SRV' => sprintf(
                '%d %d %s',
                $data['weight'] ?? 0,
                $data['port'] ?? 0,
                $data['target'] ?? '',
            ),
            'CAA' => sprintf(
                '%d %s "%s"',
                $data['flags'] ?? 0,
                $data['tag'] ?? '',
                $data['value'] ?? '',
            ),
            'TXT' => trim((string) ($record['content'] ?? ''), '"'),
            default => (string) ($record['content'] ?? ''),
        };
    }
}
