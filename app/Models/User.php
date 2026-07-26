<?php

namespace App\Models;

use App\Enums\Role;
use App\Enums\ZoneRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, LogsActivity, Notifiable;

    /**
     * Memoized zone-grant map — every policy check, zoneCan prop, and query
     * scope in a request goes through this single query.
     *
     * @var Collection<int, array<string>>|null map of dns_zone_id => role values
     */
    protected ?Collection $zoneRolesMap = null;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('users')
            ->logOnly(['name', 'email', 'roles'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'oidc_sub',
        'avatar_url',
        'roles',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'roles' => 'array',
        ];
    }

    public function zoneGrants(): HasMany
    {
        return $this->hasMany(ZoneUser::class);
    }

    public function hasRole(Role $role): bool
    {
        return in_array($role->value, $this->roles ?? [], true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(Role::SuperAdmin);
    }

    public function isSuperViewer(): bool
    {
        return $this->hasRole(Role::SuperViewer);
    }

    public function isUserAdmin(): bool
    {
        return $this->hasRole(Role::UserAdmin);
    }

    /**
     * @return Collection<int, array<string>> dns_zone_id => zone-role values
     */
    public function zoneRolesMap(): Collection
    {
        return $this->zoneRolesMap ??= $this->zoneGrants()
            ->get(['dns_zone_id', 'roles'])
            ->mapWithKeys(fn (ZoneUser $grant) => [$grant->dns_zone_id => $grant->roles ?? []]);
    }

    /** Drop the memoized grant map (call after mutating grants in-request). */
    public function forgetZoneRolesMap(): void
    {
        $this->zoneRolesMap = null;
    }

    /**
     * @return array<ZoneRole>
     */
    public function zoneRoles(DnsZone|int $zone): array
    {
        $zoneId = $zone instanceof DnsZone ? $zone->id : $zone;

        return array_values(array_filter(array_map(
            ZoneRole::tryFrom(...),
            $this->zoneRolesMap()->get($zoneId, []),
        )));
    }

    public function hasZoneRole(DnsZone|int $zone, ZoneRole ...$roles): bool
    {
        $held = $this->zoneRoles($zone);

        foreach ($roles as $role) {
            if (in_array($role, $held, true)) {
                return true;
            }
        }

        return false;
    }

    public function hasAnyZoneAccess(): bool
    {
        return $this->zoneRolesMap()->isNotEmpty();
    }

    /**
     * Zone ids this user may see (or, when $roles is given, ids where they
     * hold ANY of those roles). Null means unrestricted — Super Admins and
     * Super Viewers see every zone.
     *
     * @param  array<ZoneRole>|null  $roles
     * @return array<int>|null
     */
    public function accessibleZoneIds(?array $roles = null): ?array
    {
        if ($this->isSuperAdmin() || $this->isSuperViewer()) {
            return null;
        }

        $wanted = $roles === null ? null : array_map(fn (ZoneRole $role) => $role->value, $roles);

        return $this->zoneRolesMap()
            ->filter(fn (array $held) => $wanted === null || array_intersect($held, $wanted) !== [])
            ->keys()
            ->all();
    }
}
