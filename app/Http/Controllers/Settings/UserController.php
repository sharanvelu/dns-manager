<?php

namespace App\Http\Controllers\Settings;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('settings/users', [
            'users' => User::query()
                ->orderBy('name')
                ->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatarUrl' => $user->avatar_url,
                    'roles' => $user->roles ?? [],
                    'createdAt' => $user->created_at->toIso8601String(),
                ]),
            'roles' => collect(Role::cases())->map(fn (Role $role) => [
                'value' => $role->value,
                'label' => $role->label(),
                'description' => $role->description(),
            ]),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::enum(Role::class)],
        ]);

        $roles = array_values(array_unique($validated['roles']));

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

        return back()->with('success', "{$user->name} removed. If they sign in again they will be re-provisioned as a Viewer.");
    }

    private function wouldRemoveLastSuperAdmin(User $user, array $newRoles): bool
    {
        if (! $user->isSuperAdmin() || in_array(Role::SuperAdmin->value, $newRoles, true)) {
            return false;
        }

        return User::query()->whereJsonContains('roles', Role::SuperAdmin->value)->count() === 1;
    }
}
