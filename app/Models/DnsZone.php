<?php

namespace App\Models;

use Database\Factories\DnsZoneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class DnsZone extends Model
{
    /** @use HasFactory<DnsZoneFactory> */
    use HasFactory, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('zones')
            ->logOnly(['name', 'description'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected $fillable = [
        'name',
        'description',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(DnsEntry::class, 'dns_zone_id');
    }

    public function zoneProviders(): HasMany
    {
        return $this->hasMany(ZoneProvider::class, 'dns_zone_id');
    }

    public function providers(): BelongsToMany
    {
        return $this->belongsToMany(Provider::class, 'zone_providers', 'dns_zone_id')
            ->withPivot(['id', 'enabled'])
            ->withTimestamps();
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class, 'dns_zone_id');
    }

    public function userGrants(): HasMany
    {
        return $this->hasMany(ZoneUser::class, 'dns_zone_id');
    }

    /**
     * Expand a zone-relative record name ('@', 'www', '*.app') to its FQDN.
     */
    public function fqdn(string $relativeName): string
    {
        return $relativeName === '@' ? $this->name : "{$relativeName}.{$this->name}";
    }

    /**
     * Convert an FQDN to a zone-relative name, or null when the
     * hostname does not fall under this zone.
     */
    public function relativize(string $fqdn): ?string
    {
        $fqdn = strtolower(rtrim($fqdn, '.'));

        if ($fqdn === $this->name) {
            return '@';
        }

        if (str_ends_with($fqdn, '.'.$this->name)) {
            return substr($fqdn, 0, -strlen('.'.$this->name));
        }

        return null;
    }
}
