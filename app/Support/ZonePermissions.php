<?php

declare(strict_types = 1);

namespace App\Support;

use App\Models\User;
use App\Models\DnsZone;

class ZonePermissions
{
    /**
     * The `zoneCan` prop for zone pages. Every check runs off the user's
     * memoized grants map — one query per request regardless of call count.
     *
     * @return array{viewZone: bool, manageRecords: bool, manageAttachments: bool, updateZone: bool, deleteZone: bool, viewActivity: bool, viewAccess: bool, manageAccess: bool}
     */
    public static function for(User $user, DnsZone $zone): array
    {
        return [
            // A user-admin may open the Access tab without being able to see
            // the zone itself — the tab bar needs to know the difference.
            'viewZone' => $user->can('view', $zone),
            'manageRecords' => $user->can('manageRecords', $zone),
            'manageAttachments' => $user->can('manageAttachments', $zone),
            'updateZone' => $user->can('update', $zone),
            'deleteZone' => $user->can('delete', $zone),
            'viewActivity' => $user->can('viewActivity', $zone),
            'viewAccess' => $user->can('viewAccess', $zone),
            'manageAccess' => $user->can('manageAccess', $zone),
        ];
    }

    /**
     * The per-zone map for the entries pages:
     * zoneId => { manageRecords, viewActivity }.
     *
     * @param  iterable<DnsZone>  $zones
     */
    public static function mapFor(User $user, iterable $zones): array
    {
        $map = [];

        foreach ($zones as $zone) {
            $map[$zone->id] = [
                'manageRecords' => $user->can('manageRecords', $zone),
                'viewActivity' => $user->can('viewActivity', $zone),
            ];
        }

        return $map;
    }
}
