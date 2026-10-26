<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

class UserSignInController extends Controller
{
    public function update(Request $request, User $user): RedirectResponse
    {
        $enabled = (bool) $request->validate(['sso_only' => ['required', 'boolean']])['sso_only'];
        $actor = $request->user();

        if ($actor->isUserAdmin() && ! $actor->isSuperAdmin() && $user->is($actor)) {
            return back()->withErrors(['sso_only' => 'You cannot change your own account.']);
        }

        if ($user->sso_only === $enabled) {
            return back();
        }

        $user->forceFill(['sso_only' => $enabled])->save();

        activity('auth')
            ->causedBy($actor)
            ->performedOn($user)
            ->event('sso-only-changed')
            ->withProperties(['sso_only' => $enabled])
            ->log('sso-only-changed');

        return back()->with('success', $enabled
            ? "{$user->name} can now only sign in with single sign-on."
            : "{$user->name} can sign in with a password again.");
    }
}
