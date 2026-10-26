<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Support\AuthMethods;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

/** Set or change the signed-in user's own password (SSO users may add one too). */
class PasswordController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->passwordLoginAllowed()) {
            return back()->withErrors([
                'password' => 'This account signs in with single sign-on only. Ask an administrator if you need a password.',
            ]);
        }

        $hadPassword = $user->password !== null;

        $rules = ['password' => ['required', 'confirmed', AuthMethods::passwordRule()]];

        if ($hadPassword) {
            $rules['current_password'] = ['required', 'current_password'];
        }

        $validated = $request->validate($rules);

        $user->forceFill(['password' => $validated['password'], 'must_change_password' => false])->save();
        $user->signOutOtherSessions($request->session()->getId());

        $event = $hadPassword ? 'password-changed' : 'password-set';
        activity('auth')->causedBy($user)->performedOn($user)->event($event)->log($event);

        return back()->with('success', $hadPassword ? 'Password updated.' : 'Password set. You can now sign in with your email and password.');
    }
}
