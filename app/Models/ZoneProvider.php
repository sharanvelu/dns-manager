<?php

declare(strict_types = 1);

namespace App\Models;

use App\Connectors\ConnectorRegistry;
use Illuminate\Database\Eloquent\Model;
use App\Connectors\Contracts\DnsConnector;
use Database\Factories\ZoneProviderFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ZoneProvider extends Model
{
    /** @use HasFactory<ZoneProviderFactory> */
    use HasFactory;

    protected $table = 'zone_providers';

    protected $fillable = [
        'dns_zone_id',
        'provider_id',
        'config',
        'enabled',
    ];

    protected $hidden = [
        'config',
    ];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DnsZone::class, 'dns_zone_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function syncStates(): HasMany
    {
        return $this->hasMany(EntrySyncState::class, 'zone_provider_id');
    }

    public function connector(): DnsConnector
    {
        return app(ConnectorRegistry::class)->for($this);
    }

    /**
     * Provider credentials overlaid with the per-zone settings (zone wins).
     */
    public function effectiveConfig(): array
    {
        return array_merge($this->provider->config ?? [], $this->config ?? []);
    }

    public function isActive(): bool
    {
        return $this->enabled && $this->provider->enabled;
    }

    public function managesType(string $type): bool
    {
        return $this->isActive() && $this->provider->managesType($type);
    }

    /**
     * Human label for sync-log messages, e.g. "Cloudflare main (sharan.link)".
     */
    public function label(): string
    {
        return "{$this->provider->name} ({$this->zone->name})";
    }

    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
            'enabled' => 'boolean',
        ];
    }
}
