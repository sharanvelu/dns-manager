<?php

declare(strict_types = 1);

namespace App\Models;

use App\Enums\Role;
use App\Enums\ZoneRole;
use Illuminate\Support\Str;
use App\Support\AuthMethods;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Database\Factories\UserFactory;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Password;
use App\Notifications\InviteNotification;
use Spatie\Activitylog\Support\LogOptions;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;
    use LogsActivity;
    use Notifiable;

    /**
     * Memoized zone-grant map — every policy check, zoneCan prop, and query
     * scope in a request goes through this single query.
     *
     * @var Collection<int, array<string>>|null map of dns_zone_id => role values
     */
    protected ?Collection $zoneRolesMap = null;

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
        'sso_only',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('users')
            ->logOnly(['name', 'email', 'roles', 'sso_only'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function zoneGrants(): HasMany
    {
        return $this->hasMany(ZoneUser::class);
    }

    /** Admin-created account that has neither set a password nor signed in via SSO yet. */
    public function isPendingInvite(): bool
    {
        return $this->password === null && $this->oidc_sub === null && $this->passwordLoginAllowed();
    }

    /** Secret issued but never confirmed with a code — enrollment abandoned or in progress. */
    public function hasPendingTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at === null;
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /** SSO-only users never use a password — but the flag is inert while SSO itself is off (no lockout). */
    public function passwordLoginAllowed(): bool
    {
        return ! ($this->sso_only && AuthMethods::sso());
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /** Email a fresh first-time "create your password" link (invalidates any earlier one). */
    public function sendInvite(): void
    {
        $this->notify(new InviteNotification(Password::broker('invites')->createToken($this)));
    }

    /**
     * Invalidate remember-me tokens and database sessions — used whenever the
     * password changes. Pass the current session id to keep that one alive.
     */
    public function signOutOtherSessions(?string $exceptSessionId = null): void
    {
        $this->forceFill(['remember_token' => Str::random(60)])->save();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $this->getKey())
                ->when($exceptSessionId, fn ($query) => $query->where('id', '!=', $exceptSessionId))
                ->delete();
        }
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
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'sso_only' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }
}
