<?php

declare(strict_types = 1);

namespace App\Models;

use App\Enums\RecordType;
use Database\Factories\DnsEntryFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Contracts\Activity;
use Spatie\Activitylog\Support\LogOptions;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class DnsEntry extends Model
{
    /** @use HasFactory<DnsEntryFactory> */
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'dns_zone_id',
        'name',
        'type',
        'content',
        'ttl',
        'priority',
        'proxied',
        'comment',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('entries')
            ->logOnly(['name', 'type', 'content', 'ttl', 'priority', 'proxied', 'comment'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * Stamp the zone onto every entry activity so zone-scoped activity
     * queries keep working after the entry itself is deleted.
     * (activitylog v5's replacement for the old tapActivity hook.)
     */
    public function beforeActivityLogged(Activity $activity, string $eventName): void
    {
        $activity->properties = $activity->properties->merge([
            'dns_zone_id' => $this->dns_zone_id,
            'zone' => $this->zone?->name,
        ]);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DnsZone::class, 'dns_zone_id');
    }

    public function zoneProviders(): BelongsToMany
    {
        return $this->belongsToMany(ZoneProvider::class, 'dns_entry_zone_provider')
            ->using(EntrySyncState::class)
            ->withPivot(['id', 'external_id', 'sync_status', 'last_synced_at', 'last_error', 'drift_details'])
            ->withTimestamps();
    }

    public function syncStates(): HasMany
    {
        return $this->hasMany(EntrySyncState::class);
    }

    protected function casts(): array
    {
        return [
            'type' => RecordType::class,
            'ttl' => 'integer',
            'priority' => 'integer',
            'proxied' => 'boolean',
        ];
    }

    /**
     * The full hostname — `name` is stored relative to the zone ('@', 'www').
     * Connectors always speak FQDN; eager-load `zone` on hot paths.
     */
    protected function fqdn(): Attribute
    {
        return Attribute::get(fn (): string => $this->zone->fqdn($this->name));
    }
}
