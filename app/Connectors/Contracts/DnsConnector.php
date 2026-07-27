<?php

declare(strict_types = 1);

namespace App\Connectors\Contracts;

use App\Models\DnsEntry;
use Illuminate\Support\Collection;
use App\Connectors\DTOs\TestResult;
use App\Connectors\DTOs\ConfigField;
use App\Connectors\DTOs\RemoteRecord;
use App\Connectors\DTOs\ConnectorCapabilities;

interface DnsConnector
{
    /**
     * Machine identifier matching ProviderType, e.g. "cloudflare".
     */
    public static function type(): string;

    /**
     * Human-readable connector name, e.g. "Cloudflare".
     */
    public static function displayName(): string;

    /**
     * Record types this connector is able to manage (RecordType values).
     * The provider config UI offers exactly this list; the user narrows it
     * down to the subset the app should actually manage.
     *
     * @return list<string>
     */
    public static function supportedRecordTypes(): array;

    /**
     * Declarative description of the credentials/settings this connector
     * needs. The Providers UI renders its form from this schema.
     *
     * @return list<ConfigField>
     */
    public static function configSchema(): array;

    /**
     * Declarative description of the per-zone settings this connector
     * needs when a provider is attached to a DNS zone. Zoneless
     * connectors (Pi-hole, ...) return an empty list.
     *
     * @return list<ConfigField>
     */
    public static function zoneConfigSchema(): array;

    public static function capabilities(): ConnectorCapabilities;

    /**
     * Verify the stored config by talking to the real provider.
     */
    public function testConnection(): TestResult;

    /**
     * Validate the zone attachment against the real provider — requires
     * the connector to have been built with a ZoneProvider context.
     */
    public function testZone(): TestResult;

    /**
     * Auto-fill the per-zone config by looking up $zoneName at the remote
     * API. Returns null when the zone cannot be found or the connector
     * does not support discovery.
     */
    public function discoverZoneConfig(string $zoneName): ?array;

    /**
     * All records currently present at the provider (only types the
     * connector supports).
     *
     * @return Collection<int, RemoteRecord>
     */
    public function listRecords(): Collection;

    /**
     * Create the record remotely. Returns the provider-side identifier
     * (Cloudflare record id, Pi-hole entry string, ...).
     */
    public function createRecord(DnsEntry $entry): string;

    /**
     * Update the remote record identified by $externalId to match $entry.
     * Returns the (possibly new) external identifier.
     */
    public function updateRecord(DnsEntry $entry, string $externalId): string;

    public function deleteRecord(string $externalId): void;
}
