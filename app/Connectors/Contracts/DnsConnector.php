<?php

namespace App\Connectors\Contracts;

use App\Connectors\DTOs\ConfigField;
use App\Connectors\DTOs\ConnectorCapabilities;
use App\Connectors\DTOs\RemoteRecord;
use App\Connectors\DTOs\TestResult;
use App\Models\DnsEntry;
use Illuminate\Support\Collection;

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

    public static function capabilities(): ConnectorCapabilities;

    /**
     * Verify the stored config by talking to the real provider.
     */
    public function testConnection(): TestResult;

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
