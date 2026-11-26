<?php

declare(strict_types = 1);

namespace App\Models;

use App\Enums\SyncStatus;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $dns_entry_id
 * @property int $zone_provider_id
 * @property string|null $external_id
 * @property SyncStatus $sync_status
 * @property \Illuminate\Support\Carbon|null $last_synced_at
 * @property string|null $last_error
 * @property list<array{field: string, tracked: mixed, actual: mixed}>|null $drift_details
 * @property-read DnsEntry|null $entry
 * @property-read ZoneProvider|null $zoneProvider
 */
class EntrySyncState extends Pivot
{
    public $incrementing = true;
    protected $table = 'dns_entry_zone_provider';

    public function entry(): BelongsTo
    {
        return $this->belongsTo(DnsEntry::class, 'dns_entry_id');
    }

    public function zoneProvider(): BelongsTo
    {
        return $this->belongsTo(ZoneProvider::class);
    }

    protected function casts(): array
    {
        return [
            'sync_status' => SyncStatus::class,
            'last_synced_at' => 'datetime',
            'drift_details' => 'array',
        ];
    }
}
