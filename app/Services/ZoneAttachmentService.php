<?php

declare(strict_types = 1);

namespace App\Services;

use App\Models\DnsZone;
use App\Models\Provider;
use App\Models\ZoneProvider;
use App\Connectors\ConnectorRegistry;

/**
 * Auto-attachment of zoneless providers (Pi-hole, ...) to zones. A deleted
 * attachment row is the opt-out: nothing here resurrects a detached pair —
 * the only auto-attach triggers are zone creation and provider creation.
 */
class ZoneAttachmentService
{
    public function __construct(private ConnectorRegistry $registry)
    {
    }

    /**
     * When a zone is created, attach every enabled provider whose connector
     * has no zone concept of its own (it serves all zones alike).
     */
    public function attachZonelessProviders(DnsZone $zone): void
    {
        Provider::query()
            ->where('enabled', true)
            ->get()
            ->reject(fn (Provider $provider) => $this->supportsZones($provider))
            ->each(fn (Provider $provider) => ZoneProvider::firstOrCreate([
                'dns_zone_id' => $zone->id,
                'provider_id' => $provider->id,
            ]));
    }

    /**
     * When a zoneless provider is created, attach it to every existing zone.
     * No-op for connectors that manage real zones (Cloudflare, ...).
     */
    public function attachToAllZones(Provider $provider): void
    {
        if ($this->supportsZones($provider)) {
            return;
        }

        DnsZone::query()->pluck('id')->each(fn (int $zoneId) => ZoneProvider::firstOrCreate([
            'dns_zone_id' => $zoneId,
            'provider_id' => $provider->id,
        ]));
    }

    protected function supportsZones(Provider $provider): bool
    {
        return $this->registry->classFor($provider->type->value)::capabilities()->supportsZones;
    }
}
