<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Gravatar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

class OidcController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('auth/login', [
            'providerLabel' => config('services.oidc.label'),
        ]);
    }

    public function redirect(): RedirectResponse
    {
        return Socialite::driver('oidc')->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        try {
            $oidcUser = Socialite::driver('oidc')->user();
        } catch (InvalidStateException) {
            return redirect()->route('login')->withErrors([
                'oidc' => 'Your sign-in session expired. Please try again.',
            ]);
        }

        $email = $oidcUser->getEmail();

        if (! $email) {
            return redirect()->route('login')->withErrors([
                'oidc' => 'The identity provider did not return an email address.',
            ]);
        }

        $user = User::query()
            ->where('oidc_sub', $oidcUser->getId())
            ->orWhere('email', $email)
            ->first();

        $attributes = [
            'name' => $oidcUser->getName() ?: ($oidcUser->getNickname() ?: $email),
            'email' => $email,
            'oidc_sub' => $oidcUser->getId(),
            'avatar_url' => Gravatar::url($email),
        ];

        if ($user) {
            $user->update($attributes);
        } else {
            $user = User::create($attributes);
        }

        Auth::login($user, remember: true);

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
