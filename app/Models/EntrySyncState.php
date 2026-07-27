<?php

declare(strict_types = 1);

namespace App\Models;

use App\Enums\SyncStatus;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
