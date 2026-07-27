<?php

declare(strict_types = 1);

namespace App\Models;

use Database\Factories\ZoneUserFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ZoneUser extends Model
{
    /** @use HasFactory<ZoneUserFactory> */
    use HasFactory;
    use LogsActivity;

    protected $table = 'zone_user';

    protected $fillable = [
        'dns_zone_id',
        'user_id',
        'roles',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('users')
            ->logOnly(['roles'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DnsZone::class, 'dns_zone_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'roles' => 'array',
        ];
    }
}
