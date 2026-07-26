<?php

namespace App\Models;

use App\Enums\RecordType;
use Database\Factories\DnsEntryFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Contracts\Activity;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class DnsEntry extends Model
{
    /** @use HasFactory<DnsEntryFactory> */
    use HasFactory, LogsActivity;

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

    protected function casts(): array
    {
        return [
            'type' => RecordType::class,
            'ttl' => 'integer',
            'priority' => 'integer',
            'proxied' => 'boolean',
        ];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DnsZone::class, 'dns_zone_id');
    }

    public function zoneProviders(): BelongsToMany
    {
        return $this->belongsToMany(ZoneProvider::class, 'dns_entry_zone_provider')
            ->using(EntrySyncState::class)
            ->withPivot(['id', 'external_id', 'sync_status', 'last_synced_at', 'last_error'])
            ->withTimestamps();
    }

    public function syncStates(): HasMany
    {
        return $this->hasMany(EntrySyncState::class);
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
