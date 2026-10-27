<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;
use App\Enums\ZoneRole;
use App\Models\DnsZone;
use App\Models\ZoneUser;
use App\Support\Gravatar;
use App\Support\AuthMethods;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\RedirectResponse;

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
                    'invitePending' => $user->isPendingInvite(),
                    'ssoOnly' => $user->sso_only,
                    'mustChangePassword' => $user->must_change_password,
                    'zoneGrantsCount' => $user->zone_grants_count,
                    'createdAt' => $user->created_at->toIso8601String(),
                ]),
            'canManage' => Gate::allows('manage-users'),
            'roleOptions' => $this->roleOptions(),
            'basicEnabled' => AuthMethods::basic(),
            'ssoEnabled' => AuthMethods::sso(),
            'canCreate' => AuthMethods::canCreateUsers(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(AuthMethods::canCreateUsers(), 403, 'Users are created automatically on SSO sign-in.');

        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'roles' => ['present', 'array'],
            'roles.*' => [Rule::enum(Role::class)],
            'sso_only' => ['sometimes', 'boolean'],
        ]);

        $roles = array_values(array_unique($validated['roles']));
        $ssoOnly = AuthMethods::sso() && $request->boolean('sso_only');

        if (! $request->user()->isSuperAdmin() && in_array(Role::SuperAdmin->value, $roles, true)) {
            return back()->withErrors([
                'roles' => 'Only a Super Admin can grant the Super Admin role.',
            ]);
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'roles' => $roles,
            'avatar_url' => Gravatar::url($validated['email']),
            'sso_only' => $ssoOnly,
        ]);

        if (AuthMethods::basic() && ! $ssoOnly) {
            $user->sendInvite();
            activity('auth')->causedBy($request->user())->performedOn($user)->event('invite-sent')->log('invite-sent');
            $message = "{$user->name} created — an email with a link to set their password was sent to {$user->email}.";
        } else {
            $message = "{$user->name} created. They can sign in with SSO using {$user->email}.";
        }

        return redirect()->route('users.show', $user)->with('success', $message);
    }

    public function resendInvite(Request $request, User $user): RedirectResponse
    {
        if (! $user->passwordLoginAllowed()) {
            return back()->withErrors(['user' => "{$user->name} signs in with single sign-on only — there is no invite to send."]);
        }

        if (! $user->isPendingInvite()) {
            return back()->withErrors(['user' => "{$user->name} has already signed in or set a password."]);
        }

        $user->sendInvite();
        activity('auth')->causedBy($request->user())->performedOn($user)->event('invite-sent')->log('invite-sent');

        return back()->with('success', "Invite re-sent to {$user->email}.");
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
                'invitePending' => $user->isPendingInvite(),
                'hasPassword' => $user->password !== null,
                'ssoLinked' => $user->oidc_sub !== null,
                'ssoOnly' => $user->sso_only,
                'mustChangePassword' => $user->must_change_password,
                'twoFactorEnabled' => $user->hasTwoFactorEnabled(),
            ],
            'ssoEnabled' => AuthMethods::sso(),
            'basicEnabled' => AuthMethods::basic(),
            'grants' => $grants->map(fn (ZoneUser $grant) => [
                'zoneId' => $grant->dns_zone_id,
                'zoneName' => $grant->zone->name,
                'roles' => $grant->roles ?? [],
            ]),
            'roleOptions' => $this->roleOptions(),
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
        if ($request->has('email')) {
            $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'roles' => ['present', 'array'],
            'roles.*' => [Rule::enum(Role::class)],
        ]);

        $details = array_intersect_key($validated, array_flip(['name', 'email']));

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

        // SSO users' name and email are refreshed from the identity provider
        // on every login, so edits here would silently revert.
        if ($user->oidc_sub !== null && array_diff_assoc($details, $user->only(['name', 'email'])) !== []) {
            return back()->withErrors([
                'name' => 'Name and email are managed by the identity provider for SSO users.',
            ]);
        }

        $user->update($details + ['roles' => $roles]);

        return back()->with('success', "{$user->name} updated.");
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

    /** @return Collection<int, array{value: string, label: string, description: string}> */
    private function roleOptions(): Collection
    {
        return collect(Role::cases())->map(fn (Role $role) => [
            'value' => $role->value,
            'label' => $role->label(),
            'description' => $role->description(),
        ]);
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
