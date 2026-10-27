<?php

declare(strict_types = 1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use App\Http\Requests\Auth\PasswordLoginRequest;

class PasswordLoginController extends Controller
{
    public function store(PasswordLoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $user = Auth::user();

        // Correct password, but an admin forced a reset: end the session and
        // email a reset link instead of letting them in.
        if ($user->must_change_password) {
            Auth::logout();
            Password::sendResetLink(['email' => $user->email]);

            return back()->withErrors([
                'email' => 'An administrator requires you to reset your password. We have emailed you a link.',
            ]);
        }

        // Second factor pending: park the user id in the session and send
        // them to the challenge. They are NOT signed in until it passes.
        if ($user->hasTwoFactorEnabled()) {
            Auth::logout();
            $request->session()->put([
                'two_factor.id' => $user->id,
                'two_factor.remember' => $request->boolean('remember'),
                'two_factor.started_at' => now()->toIso8601String(),
            ]);

            return redirect()->route('two-factor.challenge');
        }

        $request->session()->regenerate();

        activity('auth')->causedBy($user)->performedOn($user)->event('login')->withProperties(['method' => 'password'])->log('login');

        return redirect()->intended(route('dashboard'));
    }
}
