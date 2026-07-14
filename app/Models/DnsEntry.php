<?php

namespace App\Models;

use App\Enums\RecordType;
use Database\Factories\DnsEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    protected $fillable = [
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

    public function providers(): BelongsToMany
    {
        return $this->belongsToMany(Provider::class, 'dns_entry_provider')
            ->using(DnsEntryProvider::class)
            ->withPivot(['id', 'external_id', 'sync_status', 'last_synced_at', 'last_error'])
            ->withTimestamps();
    }

    public function syncStates(): HasMany
    {
        return $this->hasMany(DnsEntryProvider::class);
    }
}
