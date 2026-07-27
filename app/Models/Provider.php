<?php

declare(strict_types = 1);

namespace App\Models;

use App\Enums\HealthStatus;
use App\Enums\ProviderType;
use App\Connectors\ConnectorRegistry;
use Database\Factories\ProviderFactory;
use Illuminate\Database\Eloquent\Model;
use App\Connectors\Contracts\DnsConnector;
use Spatie\Activitylog\Support\LogOptions;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Provider extends Model
{
    /** @use HasFactory<ProviderFactory> */
    use HasFactory;
    use LogsActivity;

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

    /**
     * Never log `config` (encrypted secrets) or the health columns
     * (background jobs would flood the audit trail every few minutes —
     * logOnly + dontLogEmptyChanges guarantees those updates log nothing).
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('providers')
            ->logOnly(['name', 'type', 'enabled', 'managed_record_types'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function syncStates(): HasManyThrough
    {
        return $this->hasManyThrough(
            EntrySyncState::class,
            ZoneProvider::class,
            'provider_id',
            'zone_provider_id',
        );
    }

    public function zoneProviders(): HasMany
    {
        return $this->hasMany(ZoneProvider::class);
    }

    public function zones(): BelongsToMany
    {
        return $this->belongsToMany(DnsZone::class, 'zone_providers', 'provider_id', 'dns_zone_id')
            ->withPivot(['id', 'enabled'])
            ->withTimestamps();
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
}
