<?php

namespace App\Models;

use App\Connectors\ConnectorRegistry;
use App\Connectors\Contracts\DnsConnector;
use App\Enums\HealthStatus;
use App\Enums\ProviderType;
use Database\Factories\ProviderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Provider extends Model
{
    /** @use HasFactory<ProviderFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'config',
        'managed_record_types',
        'enabled',
        'health_status',
        'health_message',
        'last_checked_at',
    ];

    protected $hidden = [
        'config',
    ];

    protected function casts(): array
    {
        return [
            'type' => ProviderType::class,
            'config' => 'encrypted:array',
            'managed_record_types' => 'array',
            'enabled' => 'boolean',
            'health_status' => HealthStatus::class,
            'last_checked_at' => 'datetime',
        ];
    }

    public function entries(): BelongsToMany
    {
        return $this->belongsToMany(DnsEntry::class, 'dns_entry_provider')
            ->using(DnsEntryProvider::class)
            ->withPivot(['id', 'external_id', 'sync_status', 'last_synced_at', 'last_error'])
            ->withTimestamps();
    }

    public function syncStates(): HasMany
    {
        return $this->hasMany(DnsEntryProvider::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class);
    }

    public function connector(): DnsConnector
    {
        return app(ConnectorRegistry::class)->for($this);
    }

    /**
     * Whether this provider manages the given record type: the type must be
     * supported by the connector AND selected in the provider's config.
     */
    public function managesType(string $type): bool
    {
        return in_array($type, $this->managed_record_types ?? [], true)
            && in_array($type, $this->connector()->supportedRecordTypes(), true);
    }
}
