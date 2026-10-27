<?php

declare(strict_types = 1);

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;
use App\Support\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\RedirectResponse;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;

/** Second step of password sign-in: the user id is parked in the session, nobody is authenticated yet. */
class TwoFactorChallengeController extends Controller
{
    private const EXPIRES_AFTER_MINUTES = 10;

    public function show(Request $request): Response|RedirectResponse
    {
        if ($this->pendingUser($request) === null) {
            return $this->expired($request);
        }

        return Inertia::render('auth/two-factor-challenge');
    }

    public function store(TwoFactorChallengeRequest $request, TwoFactor $twoFactor): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return $this->expired($request);
        }

        $usingRecoveryCode = $request->filled('recovery_code');
        $field = $usingRecoveryCode ? 'recovery_code' : 'code';
        $input = (string) $request->input($field);

        $valid = $usingRecoveryCode
            ? $twoFactor->consumeRecoveryCode($user, trim($input))
            : $twoFactor->verify($user, $input);

        if (! $valid) {
            activity('auth')
                ->performedOn($user)
                ->event('two-factor-challenge-failed')
                ->withProperties(['ip' => $request->ip(), 'recovery_code' => $usingRecoveryCode])
                ->log('two-factor-challenge-failed');

            return back()->withErrors([
                $field => $usingRecoveryCode ? 'That recovery code is not valid.' : 'That code is not valid. Check your authenticator app and try again.',
            ]);
        }

        $remember = (bool) $request->session()->get('two_factor.remember', false);

        Auth::loginUsingId($user->getKey(), $remember);
        $request->session()->regenerate();
        $request->session()->forget('two_factor');

        activity('auth')
            ->causedBy($user)
            ->performedOn($user)
            ->event('login')
            ->withProperties(['method' => 'password', 'two_factor' => true, 'recovery_code' => $usingRecoveryCode])
            ->log('login');

        return redirect()->intended(route('dashboard'));
    }

    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get('two_factor.id');
        $startedAt = $request->session()->get('two_factor.started_at');

        if ($id === null || $startedAt === null) {
            return null;
        }

        if (Carbon::parse($startedAt)->addMinutes(self::EXPIRES_AFTER_MINUTES)->isPast()) {
            return null;
        }

        $user = User::find($id);

        return $user?->hasTwoFactorEnabled() ? $user : null;
    }

    private function expired(Request $request): RedirectResponse
    {
        $hadSession = $request->session()->has('two_factor.id');
        $request->session()->forget('two_factor');

        $redirect = redirect()->route('login');

        return $hadSession ? $redirect->withErrors(['email' => 'Your sign-in expired. Please sign in again.']) : $redirect;
    }
}
