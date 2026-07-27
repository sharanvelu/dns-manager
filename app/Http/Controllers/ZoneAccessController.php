<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;
use App\Enums\ZoneRole;
use App\Models\DnsZone;
use App\Models\ZoneUser;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Support\ZonePermissions;
use Illuminate\Http\RedirectResponse;

class ZoneAccessController extends Controller
{
    public function index(Request $request, DnsZone $zone): Response
    {
        $actor = $request->user();
        $canManage = $actor->can('manageAccess', $zone);

        return Inertia::render('zones/access', [
            'zone' => ['id' => $zone->id, 'name' => $zone->name, 'description' => $zone->description],
            'zoneCan' => ZonePermissions::for($actor, $zone),
            'grants' => $zone->userGrants()
                ->with('user')
                ->get()
                ->sortBy(fn (ZoneUser $grant) => mb_strtolower($grant->user->name))
                ->values()
                ->map(fn (ZoneUser $grant) => [
                    'userId' => $grant->user_id,
                    'userName' => $grant->user->name,
                    'userEmail' => $grant->user->email,
                    'userAvatarUrl' => $grant->user->avatar_url,
                    'roles' => $grant->roles ?? [],
                    'createdAt' => $grant->created_at?->toIso8601String(),
                ]),
            'zoneRoleOptions' => collect(ZoneRole::cases())->map(fn (ZoneRole $role) => [
                'value' => $role->value,
                'label' => $role->label(),
                'description' => $role->description(),
            ]),
            // Candidates for a new grant — read-only visitors get none (they
            // cannot open the dialog anyway, and Super Viewers must not feed
            // the user directory to a page they merely observe).
            'grantableUsers' => $canManage
                ? User::query()
                    ->whereDoesntHave('zoneGrants', fn ($query) => $query->where('dns_zone_id', $zone->id))
                    ->orderBy('name')
                    ->get(['id', 'name', 'email'])
                    ->map(fn (User $candidate) => [
                        'id' => $candidate->id,
                        'name' => $candidate->name,
                        'email' => $candidate->email,
                    ])
                : [],
            // Only Super Admins and User Admins may mint or touch Zone Admin
            // grants (the controller enforces it; the UI locks accordingly).
            'canGrantZoneAdmin' => $actor->isSuperAdmin() || $actor->isUserAdmin(),
        ]);
    }

    public function upsert(Request $request, DnsZone $zone, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::enum(ZoneRole::class)],
        ]);

        $roles = array_values(array_unique($validated['roles']));
        $actor = $request->user();

        if ($this->isZoneAdminActor($actor)) {
            abort_if($user->is($actor), 403, 'You cannot change your own access.');

            $existing = $this->grantFor($zone, $user);

            abort_if(
                $existing !== null && in_array(ZoneRole::ZoneAdmin->value, $existing->roles ?? [], true),
                403,
                'Zone Admin grants can only be changed by a Super Admin or User Admin.',
            );

            abort_if(
                in_array(ZoneRole::ZoneAdmin->value, $roles, true),
                403,
                'Zone Admin grants can only be granted by a Super Admin or User Admin.',
            );
        }

        $grant = ZoneUser::updateOrCreate(
            ['dns_zone_id' => $zone->id, 'user_id' => $user->id],
            ['roles' => $roles],
        );

        $user->forgetZoneRolesMap();

        $wasCreated = $grant->wasRecentlyCreated;

        // Never rely on the automatic model log alone — record an explicit,
        // named event carrying the user + zone context (mirrors the manual
        // attachment events in ZoneProviderController).
        activity('users')
            ->performedOn($grant)
            ->event($wasCreated ? 'zone-access-granted' : 'zone-access-updated')
            ->withProperties(['user' => $user->name, 'zone' => $zone->name, 'roles' => $roles])
            ->log($wasCreated
                ? "granted {$user->name} access to {$zone->name}"
                : "updated {$user->name}'s access to {$zone->name}");

        return back()->with('success', $wasCreated
            ? "{$user->name} granted access to {$zone->name}."
            : "Updated {$user->name}'s access to {$zone->name}.");
    }

    public function destroy(Request $request, DnsZone $zone, User $user): RedirectResponse
    {
        $actor = $request->user();
        $restricted = $this->isZoneAdminActor($actor);

        abort_if($restricted && $user->is($actor), 403, 'You cannot change your own access.');

        $grant = $this->grantFor($zone, $user);

        abort_if($grant === null, 404);

        abort_if(
            $restricted && in_array(ZoneRole::ZoneAdmin->value, $grant->roles ?? [], true),
            403,
            'Zone Admin grants can only be changed by a Super Admin or User Admin.',
        );

        $grant->delete();

        $user->forgetZoneRolesMap();

        activity('users')
            ->performedOn($grant)
            ->event('zone-access-revoked')
            ->withProperties(['user' => $user->name, 'zone' => $zone->name])
            ->log("revoked {$user->name}'s access to {$zone->name}");

        return back()->with('success', "{$user->name}'s access to {$zone->name} removed.");
    }

    /**
     * True when the actor passed the manageAccess gate purely via their own
     * zone-admin grant — those actors cannot touch their own access or any
     * grant that involves the zone-admin role.
     */
    private function isZoneAdminActor(User $actor): bool
    {
        return ! $actor->isSuperAdmin() && ! $actor->isUserAdmin();
    }

    private function grantFor(DnsZone $zone, User $user): ?ZoneUser
    {
        return ZoneUser::query()
            ->where('dns_zone_id', $zone->id)
            ->where('user_id', $user->id)
            ->first();
    }
}
