<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncLog extends Model
{
    protected $fillable = [
        'provider_id',
        'dns_entry_id',
        'action',
        'status',
        'message',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(DnsEntry::class, 'dns_entry_id');
    }

    public static function record(?Provider $provider, ?DnsEntry $entry, string $action, string $status, ?string $message = null): self
    {
        return self::create([
            'provider_id' => $provider?->id,
            'dns_entry_id' => $entry?->id,
            'action' => $action,
            'status' => $status,
            'message' => $message,
        ]);
    }
}
