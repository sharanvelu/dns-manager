<?php

namespace App\Connectors;

use App\Connectors\DTOs\ConfigField;
use App\Connectors\DTOs\ConnectorCapabilities;
use App\Connectors\DTOs\RemoteRecord;
use App\Connectors\DTOs\TestResult;
use App\Connectors\Exceptions\ConnectorException;
use App\Models\DnsEntry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Throwable;

class CloudflareConnector extends AbstractDnsConnector
{
    protected const BASE_URL = 'https://api.cloudflare.com/client/v4';

    protected const PER_PAGE = 5000;

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
                key: 'zone_id',
                label: 'Zone ID',
                help: 'Found on the zone Overview page in the Cloudflare dashboard.',
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
            $verify = $this->http()->get('/user/tokens/verify');

            if (! $verify->successful() || $verify->json('success') !== true) {
                return TestResult::failure('Token verification failed: '.$this->errorMessageFrom($verify));
            }

            $zone = $this->http()->get('/zones/'.$this->config('zone_id'));

            if (! $zone->successful() || $zone->json('success') !== true) {
                return TestResult::failure('Zone lookup failed: '.$this->errorMessageFrom($zone));
            }

            return TestResult::success('Connected to zone '.$zone->json('result.name'), [
                'zone' => $zone->json('result.name'),
                'status' => $zone->json('result.status'),
            ]);
        } catch (ConnectionException $e) {
            return TestResult::failure('Could not reach Cloudflare: '.$e->getMessage());
        }
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

        if (! $response->successful()) {
            throw $this->failed($response, 'create record');
        }

        return (string) $response->json('result.id');
    }

    public function updateRecord(DnsEntry $entry, string $externalId): string
    {
        $response = $this->http()->put($this->recordsPath().'/'.$externalId, $this->payloadFor($entry));

        if (! $response->successful()) {
            throw $this->failed($response, 'update record');
        }

        return (string) $response->json('result.id');
    }

    public function deleteRecord(string $externalId): void
    {
        $response = $this->http()->delete($this->recordsPath().'/'.$externalId);

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

    protected function recordsPath(): string
    {
        return '/zones/'.$this->config('zone_id').'/dns_records';
    }

    /**
     * Build the Cloudflare create/update payload for a local entry.
     */
    protected function payloadFor(DnsEntry $entry): array
    {
        $type = $entry->type->value;

        $payload = [
            'type' => $type,
            'name' => $entry->name,
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

        return '"'.$content.'"';
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
