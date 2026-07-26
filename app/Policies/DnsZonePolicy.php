<?php

namespace App\Policies;

use App\Enums\ZoneRole;
use App\Models\DnsZone;
use App\Models\User;

/**
 * Zone-scoped abilities. Super Admin passes everything via Gate::before();
 * Super Viewer is read-only by construction — no mutating ability here ever
 * returns true for them (deny-by-default, nothing to strip later).
 * ZONE_ADMIN grant-target restrictions (never grants containing zone-admin,
 * never their own) depend on the target payload and live in
 * ZoneAccessController, not here.
 */
class DnsZonePolicy
{
    public function view(User $user, DnsZone $zone): bool
    {
        return $user->isSuperViewer() || $user->zoneRoles($zone) !== [];
    }

    public function manageRecords(User $user, DnsZone $zone): bool
    {
        return $user->hasZoneRole($zone, ZoneRole::ZoneAdmin, ZoneRole::ZoneDnsManager);
    }

    public function manageAttachments(User $user, DnsZone $zone): bool
    {
        return $user->hasZoneRole($zone, ZoneRole::ZoneAdmin, ZoneRole::ZoneProviderManager);
    }

    public function update(User $user, DnsZone $zone): bool
    {
        return $user->hasZoneRole($zone, ZoneRole::ZoneAdmin);
    }

    public function delete(User $user, DnsZone $zone): bool
    {
        return false; // Super Admin only, via Gate::before().
    }

    public function viewActivity(User $user, DnsZone $zone): bool
    {
        return $user->isSuperViewer()
            || $user->hasZoneRole($zone, ZoneRole::ZoneAdmin, ZoneRole::ZoneViewer);
    }

    public function viewAccess(User $user, DnsZone $zone): bool
    {
        return $user->isSuperViewer()
            || $user->isUserAdmin()
            || $user->hasZoneRole($zone, ZoneRole::ZoneAdmin);
    }

    public function manageAccess(User $user, DnsZone $zone): bool
    {
        return $user->isUserAdmin()
            || $user->hasZoneRole($zone, ZoneRole::ZoneAdmin);
    }
}
