<?php

namespace App\Models;

use App\Enums\SyncStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class DnsEntryProvider extends Pivot
{
    protected $table = 'dns_entry_provider';

    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'sync_status' => SyncStatus::class,
            'last_synced_at' => 'datetime',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(DnsEntry::class, 'dns_entry_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
