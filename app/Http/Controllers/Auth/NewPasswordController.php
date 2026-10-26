<?php

declare(strict_types = 1);

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;
use App\Support\AuthMethods;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * Serves both "reset forgotten password" and "create your first password"
 * (admin invite) — the routes pass the password broker as a route default.
 */
class NewPasswordController extends Controller
{
    public function create(Request $request, string $token): Response
    {
        return Inertia::render('auth/reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
            'mode' => $this->isInvite($request) ? 'invite' : 'reset',
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', AuthMethods::passwordRule()],
        ]);

        $invite = $this->isInvite($request);

        $status = Password::broker($request->route('broker'))->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use ($invite) {
                $user->forceFill(['password' => $password]);

                $user->email_verified_at ??= now();
                $user->save();

                $user->signOutOtherSessions();

                $event = $invite ? 'password-set' : 'password-reset';
                activity('auth')->causedBy($user)->performedOn($user)->event($event)->log($event);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => $invite
                    ? 'This invite link is invalid or has expired. Ask an administrator to resend it.'
                    : 'This password reset link is invalid or has expired. Request a new one.',
            ]);
        }

        return redirect()->route('login')->with('status', $invite
            ? 'Your password is set. You can sign in now.'
            : 'Your password has been reset. You can sign in now.');
    }

    private function isInvite(Request $request): bool
    {
        return $request->route('broker') === 'invites';
    }
}
