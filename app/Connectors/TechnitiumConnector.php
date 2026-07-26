<?php

namespace App\Connectors;

use App\Connectors\DTOs\ConfigField;
use App\Connectors\DTOs\ConnectorCapabilities;
use App\Connectors\DTOs\RemoteRecord;
use App\Connectors\DTOs\TestResult;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\RecordNotFoundException;
use App\Models\DnsEntry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Technitium DNS Server connector (HTTP API with a permanent API token).
 *
 * Zoned, but with an EMPTY zone config schema: the Technitium zone is
 * addressed by the DnsZone's own name (`zone={zone name}` on every record
 * call), so attachments carry no per-zone settings — testZone() and
 * discoverZoneConfig() only verify the zone exists on the server.
 *
 * Technitium has no stable record ids (same philosophy as Pi-hole's raw
 * entry strings). The external id encodes the record tuple as canonical
 * JSON — {"type","name",...identifying rData params} with lowercased
 * domain-name values — produced identically by createRecord() and
 * listRecords() so drift checks match on string equality, and decoded by
 * updateRecord()/deleteRecord() to address the existing record.
 */
class TechnitiumConnector extends AbstractDnsConnector
{
    /**
     * Technitium always stores a TTL and applies its settings default when
     * a record is added without one. Entries with a null (auto) TTL are
     * pushed with this value, and remote records at this value are reported
     * as auto so they never flag TTL drift.
     */
    protected const DEFAULT_TTL = 3600;

    /**
     * External-id keys holding domain names / addresses — lowercased in the
     * encoding because DNS compares them case-insensitively.
     */
    protected const CASE_INSENSITIVE_KEYS = ['ipAddress', 'cname', 'nameServer', 'ptrName', 'exchange', 'target'];

    public static function type(): string
    {
        return 'technitium';
    }

    public static function displayName(): string
    {
        return 'Technitium';
    }

    public static function supportedRecordTypes(): array
    {
        return ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'NS', 'CAA', 'PTR'];
    }

    public static function configSchema(): array
    {
        return [
            new ConfigField(
                key: 'base_url',
                label: 'Base URL',
                type: 'url',
                help: 'Technitium web service address, e.g. https://technitium.local:53443 — no trailing slash.',
            ),
            new ConfigField(
                key: 'api_token',
                label: 'API Token',
                type: 'password',
                secret: true,
                help: 'Permanent API token — create one in Technitium under Administration → Sessions → Create Token.',
            ),
            new ConfigField(
                key: 'verify_tls',
                label: 'Verify TLS certificate',
                type: 'boolean',
                required: false,
                help: 'Disable when the Technitium server uses a self-signed certificate.',
                default: true,
            ),
            new ConfigField(
                key: 'adopt_existing',
                label: 'Adopt existing records',
                type: 'boolean',
                required: false,
                help: 'When a record you create already exists at Technitium, adopt and manage it (aligning it to your entry) instead of failing.',
                default: true,
            ),
        ];
    }

    public static function capabilities(): ConnectorCapabilities
    {
        return new ConnectorCapabilities(
            supportsProxied: false,
            supportsTtl: true,
            supportsPriority: true,
        );
    }

    public function testConnection(): TestResult
    {
        try {
            $response = $this->http()->get('/api/zones/list', ['pageNumber' => 1, 'zonesPerPage' => 1]);

            if ($response->failed() || $response->json('status') !== 'ok') {
                return TestResult::failure('Token check failed: '.$this->errorMessageFrom($response));
            }

            $zoneCount = $response->json('response.totalZones');

            if (is_int($zoneCount)) {
                return TestResult::success(
                    sprintf('API token is valid — %d zone%s hosted.', $zoneCount, $zoneCount === 1 ? '' : 's'),
                    ['zones' => $zoneCount],
                );
            }

            return TestResult::success('API token is valid.');
        } catch (ConnectionException $e) {
            return TestResult::failure('Could not reach Technitium: '.$e->getMessage());
        }
    }

    public function testZone(): TestResult
    {
        $this->requireZoneContext();

        $zoneName = $this->zone()->name;

        try {
            if ($this->zoneExists($zoneName)) {
                return TestResult::success('Connected to zone '.$zoneName, ['zone' => $zoneName]);
            }

            return TestResult::failure("Zone {$zoneName} does not exist on this Technitium server");
        } catch (ConnectionException $e) {
            return TestResult::failure('Could not reach Technitium: '.$e->getMessage());
        } catch (ConnectorException $e) {
            return TestResult::failure($e->getMessage());
        }
    }

    /**
     * The zone needs no per-zone config — discovery is an attach-time
     * existence check: [] when the zone is hosted, null otherwise.
     */
    public function discoverZoneConfig(string $zoneName): ?array
    {
        try {
            return $this->zoneExists($zoneName) ? [] : null;
        } catch (ConnectionException|ConnectorException) {
            return null;
        }
    }

    public function listRecords(): Collection
    {
        $zoneName = $this->zone()->name;

        $response = $this->http()->get('/api/zones/records/get', [
            'domain' => $zoneName,
            'zone' => $zoneName,
            'listZone' => 'true',
        ]);

        if ($response->failed()) {
            throw $this->failed($response, 'list records');
        }

        if ($response->json('status') !== 'ok') {
            throw $this->envelopeError($response, 'list records');
        }

        return collect($response->json('response.records', []))
            ->filter(fn (array $record): bool => in_array($record['type'], static::supportedRecordTypes(), true))
            ->map(fn (array $record): RemoteRecord => $this->toRemoteRecord($record))
            ->values();
    }

    public function createRecord(DnsEntry $entry): string
    {
        $params = $this->addParams($entry);

        $response = $this->http()->get('/api/zones/records/add', $params);

        if ($response->failed()) {
            throw $this->failed($response, 'create record');
        }

        if ($response->json('status') === 'ok') {
            return $this->externalIdFor($entry);
        }

        if ($this->isAlreadyExistsError($response)) {
            if (! $this->shouldAdoptExisting()) {
                throw $this->envelopeError($response, "creating {$entry->type->value} record {$entry->fqdn} (a matching record already exists and adoption is disabled)");
            }

            // Adopt by overwriting the record set with the local entry (DB
            // wins — same policy as drift re-push). Technitium only rejects
            // an identical record or a conflicting singleton (CNAME), so
            // the overwrite is unambiguous.
            $overwrite = $this->http()->get('/api/zones/records/add', [...$params, 'overwrite' => 'true']);

            if (! $overwrite->failed() && $overwrite->json('status') === 'ok') {
                return $this->externalIdFor($entry);
            }

            throw $this->envelopeError($overwrite, "adopting existing {$entry->type->value} record {$entry->fqdn}");
        }

        throw $this->envelopeError($response, "creating {$entry->type->value} record {$entry->fqdn}");
    }

    public function updateRecord(DnsEntry $entry, string $externalId): string
    {
        $old = $this->decodeExternalId($externalId);

        if ($old === null || $old['type'] !== $entry->type->value) {
            // records/update cannot change a record's type (and an
            // undecodable id cannot address anything) — replace instead.
            $this->deleteRecord($externalId);

            return $this->createRecord($entry);
        }

        $params = [
            'domain' => $old['name'],
            'zone' => $this->zone()->name,
            'type' => $entry->type->value,
            'ttl' => $entry->ttl ?? self::DEFAULT_TTL,
            ...$this->updateParams($entry, $old),
        ];

        if (strcasecmp($old['name'], rtrim($entry->fqdn, '.')) !== 0) {
            $params['newDomain'] = $entry->fqdn;
        }

        if ($entry->comment !== null) {
            $params['comments'] = $entry->comment;
        }

        $response = $this->http()->get('/api/zones/records/update', $params);

        if ($response->failed()) {
            throw $this->failed($response, 'update record');
        }

        if ($response->json('status') !== 'ok') {
            // The old record was removed out-of-band — signal it so the
            // sync job can fall back to creating the record fresh.
            if ($this->isNotFoundError($response)) {
                throw new RecordNotFoundException($this->envelopeError($response, 'update record')->getMessage());
            }

            throw $this->envelopeError($response, "updating {$entry->type->value} record {$entry->fqdn}");
        }

        return $this->externalIdFor($entry);
    }

    public function deleteRecord(string $externalId): void
    {
        $zoneName = $this->zone()->name;

        $old = $this->decodeExternalId($externalId);

        if ($old === null) {
            return; // Nothing addressable — treat like an already-gone record.
        }

        $response = $this->http()->get('/api/zones/records/delete', [
            'domain' => $old['name'],
            'zone' => $zoneName,
            'type' => $old['type'],
            ...$this->deleteParams($old),
        ]);

        if ($response->failed()) {
            throw $this->failed($response, 'delete record');
        }

        // A record (or zone) that is already gone is a success.
        if ($response->json('status') !== 'ok' && ! $this->isNotFoundError($response)) {
            throw $this->envelopeError($response, "deleting record {$old['type']} {$old['name']}");
        }
    }

    /**
     * Whether $zoneName is hosted on this Technitium server (exact,
     * case-insensitive match against the zones listing).
     */
    protected function zoneExists(string $zoneName): bool
    {
        $response = $this->http()->get('/api/zones/list', [
            'filterName' => $zoneName,
            'pageNumber' => 1,
            'zonesPerPage' => 100,
        ]);

        if ($response->failed()) {
            throw $this->failed($response, 'zone lookup');
        }

        if ($response->json('status') !== 'ok') {
            throw $this->envelopeError($response, 'zone lookup');
        }

        return collect($response->json('response.zones', []))
            ->contains(fn (array $zone): bool => strcasecmp((string) $zone['name'], $zoneName) === 0);
    }

    /**
     * The records/add query for a local entry.
     */
    protected function addParams(DnsEntry $entry): array
    {
        $params = [
            'domain' => $entry->fqdn,
            'zone' => $this->zone()->name,
            'type' => $entry->type->value,
            'ttl' => $entry->ttl ?? self::DEFAULT_TTL,
            ...$this->rdataParams($entry),
        ];

        if ($entry->comment !== null) {
            $params['comments'] = $entry->comment;
        }

        return $params;
    }

    /**
     * Per-type rData params, shared by records/add and the external id.
     */
    protected function rdataParams(DnsEntry $entry): array
    {
        return match ($entry->type->value) {
            'A', 'AAAA' => ['ipAddress' => $entry->content],
            'CNAME' => ['cname' => $entry->content],
            'NS' => ['nameServer' => $entry->content],
            'PTR' => ['ptrName' => $entry->content],
            'MX' => ['exchange' => $entry->content, 'preference' => $entry->priority ?? 10],
            'TXT' => ['text' => $entry->content],
            'SRV' => $this->srvParams($entry),
            'CAA' => $this->caaParams($entry),
            default => throw new ConnectorException("Technitium: unsupported record type {$entry->type->value}."),
        };
    }

    /**
     * Parse local SRV content ("weight port target") into Technitium params.
     */
    protected function srvParams(DnsEntry $entry): array
    {
        if (! preg_match('/^\s*(\d+)\s+(\d+)\s+(\S+)\s*$/', (string) $entry->content, $matches)) {
            throw new ConnectorException(sprintf(
                'Technitium: SRV content "%s" is invalid — expected "weight port target".',
                $entry->content,
            ));
        }

        return [
            'priority' => $entry->priority ?? 0,
            'weight' => (int) $matches[1],
            'port' => (int) $matches[2],
            'target' => $matches[3],
        ];
    }

    /**
     * Parse local CAA content ('flags tag "value"') into Technitium params.
     */
    protected function caaParams(DnsEntry $entry): array
    {
        if (! preg_match('/^\s*(\d+)\s+(\S+)\s+"?([^"]+)"?\s*$/', (string) $entry->content, $matches)) {
            throw new ConnectorException(sprintf(
                'Technitium: CAA content "%s" is invalid — expected \'flags tag "value"\'.',
                $entry->content,
            ));
        }

        return [
            'flags' => (int) $matches[1],
            'tag' => $matches[2],
            'value' => $matches[3],
        ];
    }

    /**
     * records/update addresses the old record with the current values and
     * carries the entry's values in the new* params.
     */
    protected function updateParams(DnsEntry $entry, array $old): array
    {
        $new = $this->rdataParams($entry);

        return match ($entry->type->value) {
            'A', 'AAAA' => ['ipAddress' => $old['ipAddress'], 'newIpAddress' => $new['ipAddress']],
            // A CNAME is a singleton per name — records/update takes the
            // new target directly, with no "current cname" selector.
            'CNAME' => ['cname' => $new['cname']],
            'NS' => ['nameServer' => $old['nameServer'], 'newNameServer' => $new['nameServer']],
            'PTR' => ['ptrName' => $old['ptrName'], 'newPtrName' => $new['ptrName']],
            'MX' => [
                'exchange' => $old['exchange'], 'newExchange' => $new['exchange'],
                'preference' => $old['preference'], 'newPreference' => $new['preference'],
            ],
            'TXT' => ['text' => $old['text'], 'newText' => $new['text']],
            'SRV' => [
                'priority' => $old['priority'], 'newPriority' => $new['priority'],
                'weight' => $old['weight'], 'newWeight' => $new['weight'],
                'port' => $old['port'], 'newPort' => $new['port'],
                'target' => $old['target'], 'newTarget' => $new['target'],
            ],
            'CAA' => [
                'flags' => $old['flags'], 'newFlags' => $new['flags'],
                'tag' => $old['tag'], 'newTag' => $new['tag'],
                'value' => $old['value'], 'newValue' => $new['value'],
            ],
            default => throw new ConnectorException("Technitium: unsupported record type {$entry->type->value}."),
        };
    }

    /**
     * The records/delete params identifying the record from a decoded
     * external id. CNAME needs no value — domain + type identify it fully.
     */
    protected function deleteParams(array $old): array
    {
        if ($old['type'] === 'CNAME') {
            return [];
        }

        return collect($this->identityKeysFor($old['type']))
            ->mapWithKeys(fn (string $key) => [$key => $old[$key]])
            ->all();
    }

    /**
     * The rData keys that identify a record of the given type.
     *
     * @return list<string>
     */
    protected function identityKeysFor(string $type): array
    {
        return match ($type) {
            'A', 'AAAA' => ['ipAddress'],
            'CNAME' => ['cname'],
            'NS' => ['nameServer'],
            'PTR' => ['ptrName'],
            'MX' => ['exchange', 'preference'],
            'TXT' => ['text'],
            'SRV' => ['priority', 'weight', 'port', 'target'],
            'CAA' => ['flags', 'tag', 'value'],
            default => [],
        };
    }

    protected function externalIdFor(DnsEntry $entry): string
    {
        return $this->encodeExternalId($entry->type->value, $entry->fqdn, $this->rdataParams($entry));
    }

    /**
     * Canonical external id for a record tuple: fixed key order, lowercased
     * domain-name values — createRecord() and listRecords() must produce
     * byte-identical encodings for the drift check's string comparison.
     */
    protected function encodeExternalId(string $type, string $name, array $rdata): string
    {
        $normalized = [];

        foreach ($rdata as $key => $value) {
            $normalized[$key] = in_array($key, self::CASE_INSENSITIVE_KEYS, true)
                ? strtolower(rtrim((string) $value, '.'))
                : $value;
        }

        return json_encode([
            'type' => $type,
            'name' => strtolower(rtrim($name, '.')),
            ...$normalized,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function decodeExternalId(string $externalId): ?array
    {
        $decoded = json_decode($externalId, true);

        if (! is_array($decoded) || ! is_string($decoded['type'] ?? null) || ! is_string($decoded['name'] ?? null)) {
            return null;
        }

        foreach ($this->identityKeysFor($decoded['type']) as $key) {
            if (! array_key_exists($key, $decoded)) {
                return null;
            }
        }

        return $decoded;
    }

    /**
     * Map a Technitium API record to the normalized RemoteRecord DTO.
     */
    protected function toRemoteRecord(array $record): RemoteRecord
    {
        $type = $record['type'];
        $rData = $record['rData'] ?? [];
        $ttl = (int) ($record['ttl'] ?? self::DEFAULT_TTL);

        return new RemoteRecord(
            externalId: $this->encodeExternalId($type, (string) $record['name'], $this->identityFromRdata($type, $rData)),
            type: $type,
            name: (string) $record['name'],
            content: $this->contentFrom($type, $rData),
            ttl: $ttl === self::DEFAULT_TTL ? null : $ttl,
            priority: match ($type) {
                'MX' => isset($rData['preference']) ? (int) $rData['preference'] : null,
                'SRV' => isset($rData['priority']) ? (int) $rData['priority'] : null,
                default => null,
            },
        );
    }

    /**
     * The identifying rData params of a remote record, typed to match what
     * rdataParams() produces for a local entry.
     */
    protected function identityFromRdata(string $type, array $rData): array
    {
        return match ($type) {
            'A', 'AAAA' => ['ipAddress' => (string) ($rData['ipAddress'] ?? '')],
            'CNAME' => ['cname' => (string) ($rData['cname'] ?? '')],
            'NS' => ['nameServer' => (string) ($rData['nameServer'] ?? '')],
            'PTR' => ['ptrName' => (string) ($rData['ptrName'] ?? '')],
            'MX' => ['exchange' => (string) ($rData['exchange'] ?? ''), 'preference' => (int) ($rData['preference'] ?? 1)],
            'TXT' => ['text' => $this->txtContent($rData)],
            'SRV' => [
                'priority' => (int) ($rData['priority'] ?? 0),
                'weight' => (int) ($rData['weight'] ?? 0),
                'port' => (int) ($rData['port'] ?? 0),
                'target' => (string) ($rData['target'] ?? ''),
            ],
            'CAA' => [
                'flags' => (int) ($rData['flags'] ?? 0),
                'tag' => (string) ($rData['tag'] ?? ''),
                'value' => (string) ($rData['value'] ?? ''),
            ],
            default => [],
        };
    }

    /**
     * Rebuild the local content string from remote rData so drift
     * comparison matches the format stored in DnsEntry rows.
     */
    protected function contentFrom(string $type, array $rData): string
    {
        return match ($type) {
            'A', 'AAAA' => (string) ($rData['ipAddress'] ?? ''),
            'CNAME' => (string) ($rData['cname'] ?? ''),
            'NS' => (string) ($rData['nameServer'] ?? ''),
            'PTR' => (string) ($rData['ptrName'] ?? ''),
            'MX' => (string) ($rData['exchange'] ?? ''),
            'TXT' => $this->txtContent($rData),
            'SRV' => sprintf('%d %d %s', $rData['weight'] ?? 0, $rData['port'] ?? 0, $rData['target'] ?? ''),
            'CAA' => sprintf('%d %s "%s"', $rData['flags'] ?? 0, $rData['tag'] ?? '', $rData['value'] ?? ''),
            default => '',
        };
    }

    /**
     * TXT rData across Technitium versions: `text`, base64 character
     * strings, or plain character strings.
     */
    protected function txtContent(array $rData): string
    {
        $text = $rData['text'] ?? null;

        if (is_string($text) && $text !== '') {
            return $text;
        }

        $base64 = $rData['characterStringsBase64'] ?? null;

        if (is_array($base64)) {
            return implode('', array_map(
                fn ($chunk): string => (string) base64_decode((string) $chunk, true),
                $base64,
            ));
        }

        return implode('', (array) ($rData['characterStrings'] ?? []));
    }

    protected function shouldAdoptExisting(): bool
    {
        return (bool) $this->config('adopt_existing', true);
    }

    protected function isAlreadyExistsError(Response $response): bool
    {
        return $response->json('status') === 'error'
            && stripos((string) $response->json('errorMessage'), 'already exists') !== false;
    }

    protected function isNotFoundError(Response $response): bool
    {
        if ($response->json('status') !== 'error') {
            return false;
        }

        $message = (string) $response->json('errorMessage');

        return stripos($message, 'no such') !== false
            || stripos($message, 'does not exist') !== false
            || stripos($message, 'not found') !== false;
    }

    /**
     * Turn a `status: error` envelope (Technitium errors arrive on HTTP
     * 200) into a ConnectorException.
     */
    protected function envelopeError(Response $response, string $action): ConnectorException
    {
        return new ConnectorException(sprintf(
            '%s: %s failed: %s',
            static::displayName(),
            $action,
            $this->errorMessageFrom($response),
        ));
    }

    protected function errorMessageFrom(Response $response): string
    {
        $message = $response->json('errorMessage');

        if (is_string($message) && $message !== '') {
            return $message;
        }

        $status = $response->json('status');

        if (is_string($status) && $status !== '' && $status !== 'ok') {
            return "API responded with status \"{$status}\"."; // e.g. invalid-token
        }

        return parent::errorMessageFrom($response);
    }

    protected function http(): PendingRequest
    {
        $request = Http::baseUrl(rtrim((string) $this->config('base_url'), '/'))
            ->withToken((string) $this->config('api_token'))
            ->acceptJson();

        if ($this->config('verify_tls', true) === false) {
            $request = $request->withoutVerifying();
        }

        return $request;
    }
}
