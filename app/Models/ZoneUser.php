<?php

namespace App\Models;

use Database\Factories\ZoneUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class ZoneUser extends Model
{
    /** @use HasFactory<ZoneUserFactory> */
    use HasFactory, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('users')
            ->logOnly(['roles'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected $table = 'zone_user';

    protected $fillable = [
        'dns_zone_id',
        'user_id',
        'roles',
    ];

    protected function casts(): array
    {
        return [
            'roles' => 'array',
        ];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DnsZone::class, 'dns_zone_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
