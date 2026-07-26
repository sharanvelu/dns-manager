<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Enums\ZoneRole;
use App\Models\DnsZone;
use App\Models\User;
use App\Models\ZoneUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('users/index', [
            'users' => User::query()
                ->withCount('zoneGrants')
                ->orderBy('name')
                ->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatarUrl' => $user->avatar_url,
                    'roles' => $user->roles ?? [],
                    'zoneGrantsCount' => $user->zone_grants_count,
                    'createdAt' => $user->created_at->toIso8601String(),
                ]),
            'canManage' => Gate::allows('manage-users'),
        ]);
    }

    public function show(Request $request, User $user): Response
    {
        $canManage = $request->user()->can('manage-users')
            && ! $this->isUserAdminActingOnSelf($request->user(), $user);

        $grants = $user->zoneGrants()
            ->with('zone:id,name')
            ->get()
            ->sortBy(fn (ZoneUser $grant) => $grant->zone->name)
            ->values();

        return Inertia::render('users/show', [
            'managedUser' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatarUrl' => $user->avatar_url,
                'roles' => $user->roles ?? [],
                'createdAt' => $user->created_at->toIso8601String(),
            ],
            'grants' => $grants->map(fn (ZoneUser $grant) => [
                'zoneId' => $grant->dns_zone_id,
                'zoneName' => $grant->zone->name,
                'roles' => $grant->roles ?? [],
            ]),
            'roleOptions' => collect(Role::cases())->map(fn (Role $role) => [
                'value' => $role->value,
                'label' => $role->label(),
                'description' => $role->description(),
            ]),
            'zoneRoleOptions' => collect(ZoneRole::cases())->map(fn (ZoneRole $role) => [
                'value' => $role->value,
                'label' => $role->label(),
                'description' => $role->description(),
            ]),
            'grantableZones' => $canManage
                ? DnsZone::query()
                    ->whereNotIn('id', $grants->pluck('dns_zone_id'))
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (DnsZone $zone) => ['id' => $zone->id, 'name' => $zone->name])
                : [],
            'canManage' => $canManage,
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        // Zero roles is legitimate — OIDC provisions new users with [] and
        // access can come entirely from zone grants.
        $validated = $request->validate([
            'roles' => ['present', 'array'],
            'roles.*' => [Rule::enum(Role::class)],
        ]);

        $roles = array_values(array_unique($validated['roles']));
        $actor = $request->user();

        if ($this->isUserAdminActingOnSelf($actor, $user)) {
            return back()->withErrors([
                'roles' => 'You cannot change your own account.',
            ]);
        }

        // Escalation guard: only a Super Admin may change whether someone
        // holds the Super Admin role — in either direction.
        if (! $actor->isSuperAdmin()
            && in_array(Role::SuperAdmin->value, $roles, true) !== $user->isSuperAdmin()) {
            return back()->withErrors([
                'roles' => 'Only a Super Admin can grant or revoke the Super Admin role.',
            ]);
        }

        if ($this->wouldRemoveLastSuperAdmin($user, $roles)) {
            return back()->withErrors([
                'roles' => 'At least one Super Admin must remain — assign the role to another user first.',
            ]);
        }

        $user->update(['roles' => $roles]);

        return back()->with('success', "Roles updated for {$user->name}.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->withErrors(['user' => 'You cannot delete your own account.']);
        }

        if ($user->isSuperAdmin() && User::query()->whereJsonContains('roles', Role::SuperAdmin->value)->count() === 1) {
            return back()->withErrors(['user' => 'Cannot delete the last Super Admin.']);
        }

        $user->delete();

        return redirect()->route('users.index')
            ->with('success', "{$user->name} removed. If they sign in again they will be re-provisioned with no access.");
    }

    /** A User Admin (who is not also Super Admin) may never touch their own account. */
    private function isUserAdminActingOnSelf(User $actor, User $target): bool
    {
        return $actor->isUserAdmin() && ! $actor->isSuperAdmin() && $target->is($actor);
    }

    private function wouldRemoveLastSuperAdmin(User $user, array $newRoles): bool
    {
        if (! $user->isSuperAdmin() || in_array(Role::SuperAdmin->value, $newRoles, true)) {
            return false;
        }

        return User::query()->whereJsonContains('roles', Role::SuperAdmin->value)->count() === 1;
    }
}
