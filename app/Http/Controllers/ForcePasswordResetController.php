<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;

class ForcePasswordResetController extends Controller
{
    public function store(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->withErrors(['user' => 'You cannot force a reset on your own account — change your password from your profile.']);
        }

        if ($user->password === null) {
            return back()->withErrors(['user' => $user->isPendingInvite()
                ? "{$user->name} has not set a password yet — use \"Resend invite\" instead."
                : "{$user->name} has no password to reset — they sign in with single sign-on only."]);
        }

        if (! $user->passwordLoginAllowed()) {
            return back()->withErrors(['user' => "{$user->name} is SSO-only — password sign-in is disabled for them."]);
        }

        $user->forceFill(['must_change_password' => true])->save();
        $user->signOutOtherSessions();

        $status = Password::broker('users')->sendResetLink(['email' => $user->email]);

        activity('auth')
            ->causedBy($request->user())
            ->performedOn($user)
            ->event('password-reset-forced')
            ->withProperties(['email_sent' => $status === Password::RESET_LINK_SENT])
            ->log('password-reset-forced');

        $message = match ($status) {
            Password::RESET_LINK_SENT => "{$user->name} was signed out everywhere and emailed a reset link. They cannot sign in with their password until they set a new one.",
            Password::RESET_THROTTLED => "{$user->name} was signed out everywhere and must reset their password, but a reset email was already sent in the last minute, so no new one was sent.",
            default => "{$user->name} was signed out everywhere and must reset their password, but the reset email could not be sent.",
        };

        return back()->with('success', $message);
    }
}
